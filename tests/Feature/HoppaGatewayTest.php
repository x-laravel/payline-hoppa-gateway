<?php

namespace XLaravel\PaylineHoppaDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\Contracts\ProvidesCommissionRates;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
use XLaravel\Payline\Contracts\QueriesPayments;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\BasketItem;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\PaylineHoppaDriver\HoppaGateway;
use XLaravel\PaylineHoppaDriver\Tests\TestCase;

class HoppaGatewayTest extends TestCase
{
    private HoppaGateway $gateway;
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = config('payline.gateways.hoppa');
        $this->gateway = new HoppaGateway($this->config);
    }

    public function test_pay_returns_pending_with_redirect_url(): void
    {
        Http::fake([
            '*/api/pay/EYV3DPay' => Http::response([
                'STATUS' => 'SUCCESS',
                'URL_3DS' => 'https://3ds.hoppa.com/auth?token=abc123',
                'REFNO' => 'HOPPA-REF-1',
            ]),
        ]);

        $response = $this->gateway->pay($this->makePaymentRequest());

        $this->assertSame(TransactionStatus::Pending, $response->status);
        $this->assertSame(TransactionType::Payment, $response->type);
        $this->assertSame('https://3ds.hoppa.com/auth?token=abc123', $response->redirectUrl);
        $this->assertSame('HOPPA-REF-1', $response->gatewayOrderId);
        $this->assertTrue($response->requiresRedirect());
    }

    public function test_pay_carries_the_3ds_session_deadline(): void
    {
        Http::fake([
            '*/api/pay/EYV3DPay' => Http::response([
                'STATUS' => 'SUCCESS',
                'URL_3DS' => 'https://3ds.hoppa.com/auth?token=abc123',
            ]),
        ]);

        $response = $this->gateway->pay($this->makePaymentRequest());

        $this->assertNotNull($response->expiresAt);
        $this->assertSame(
            now()->addMinutes(30)->format('Y-m-d H:i'),
            $response->expiresAt->format('Y-m-d H:i'),
        );
    }

    public function test_the_3ds_session_deadline_is_configurable(): void
    {
        Http::fake([
            '*/api/pay/EYV3DPay' => Http::response([
                'STATUS' => 'SUCCESS',
                'URL_3DS' => 'https://3ds.hoppa.com/auth?token=abc123',
            ]),
        ]);

        $gateway = new HoppaGateway([...$this->config, 'three_ds_session_minutes' => 10]);

        $response = $gateway->pay($this->makePaymentRequest());

        $this->assertSame(
            now()->addMinutes(10)->format('Y-m-d H:i'),
            $response->expiresAt->format('Y-m-d H:i'),
        );
    }

    public function test_pay_returns_failed_on_error_response(): void
    {
        Http::fake([
            '*/api/pay/EYV3DPay' => Http::response([
                'STATUS' => 'ERROR',
                'RETURN_CODE' => 'INVALID_CARD',
                'RETURN_MESSAGE' => 'Card information is invalid.',
            ]),
        ]);

        $response = $this->gateway->pay($this->makePaymentRequest());

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('INVALID_CARD', $response->errorCode);
        $this->assertSame('Card information is invalid.', $response->errorMessage);
    }

    public function test_pay_fails_when_the_redirect_url_is_missing(): void
    {
        Http::fake(['*/api/pay/EYV3DPay' => Http::response(['STATUS' => 'SUCCESS'])]);

        $response = $this->gateway->pay($this->makePaymentRequest());

        $this->assertSame(TransactionStatus::Failed, $response->status);
    }

    public function test_pay_sends_correct_fields_to_hoppa(): void
    {
        $this->fakeInitiation();

        $this->gateway->pay($this->makePaymentRequest(amount: 15000, installments: 3));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['Config']['MERCHANT'] === 'TEST_MERCHANT'
                && $body['Config']['MERCHANT_KEY'] === 'TEST_KEY'
                && $body['Config']['ORDER_AMOUNT'] === '150.00'
                && $body['Config']['PRICES_CURRENCY'] === 'TRY'
                && $body['CreditCard']['CC_NUMBER'] === '4111111111111111'
                && $body['CreditCard']['CC_CVV'] === '123'
                && $body['CreditCard']['EXP_YEAR'] === '2030'
                && $body['CreditCard']['INSTALLMENT_NUMBER'] === '3';
        });
    }

    public function test_pay_generates_an_order_reference_within_the_provider_limit(): void
    {
        $this->fakeInitiation();

        $response = $this->gateway->pay($this->makePaymentRequest());

        Http::assertSent(function ($request) use ($response) {
            $reference = $request->data()['Config']['ORDER_REF_NUMBER'];

            return $reference === $response->gatewayTransactionId
                && strlen($reference) === 24
                && $reference !== 'ORD-001';
        });
    }

    public function test_pay_generates_a_distinct_order_reference_per_attempt(): void
    {
        $this->fakeInitiation();

        $first = $this->gateway->pay($this->makePaymentRequest());
        $second = $this->gateway->pay($this->makePaymentRequest());

        $this->assertNotSame($first->gatewayTransactionId, $second->gatewayTransactionId);
    }

    public function test_pay_expands_a_two_digit_expiry_year(): void
    {
        $this->fakeInitiation();

        $this->gateway->pay($this->makePaymentRequest(expiryYear: '30'));

        Http::assertSent(fn ($r) => $r->data()['CreditCard']['EXP_YEAR'] === '2030');
    }

    public function test_pay_sends_the_basket_as_products(): void
    {
        $this->fakeInitiation();

        $this->gateway->pay($this->makePaymentRequest(basketItems: [
            new BasketItem(id: '1', name: 'Bilet', category: 'Etkinlik', price: 5000, quantity: 2),
        ]));

        Http::assertSent(function ($request) {
            $product = $request->data()['Product'][0];

            return $product['PRODUCT_ID'] === '1'
                && $product['PRODUCT_NAME'] === 'Bilet'
                && $product['PRODUCT_CATEGORY'] === 'Etkinlik'
                && $product['PRODUCT_AMOUNT'] === '100.00';
        });
    }

    public function test_pay_omits_the_product_group_without_a_basket(): void
    {
        $this->fakeInitiation();

        $this->gateway->pay($this->makePaymentRequest());

        Http::assertSent(fn ($r) => ! array_key_exists('Product', $r->data()));
    }

    public function test_pay_formats_amount_correctly(): void
    {
        $this->fakeInitiation();

        $this->gateway->pay($this->makePaymentRequest(amount: 10050));

        Http::assertSent(fn ($r) => $r->data()['Config']['ORDER_AMOUNT'] === '100.50');
    }

    public function test_the_callback_outcome_comes_from_the_provider_not_the_browser(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Başarılı']);

        $response = $this->gateway->handleCallback($this->callbackData(['STATUS' => 'ERROR']));

        $this->assertSame(TransactionStatus::Successful, $response->status);
    }

    public function test_a_forged_success_callback_does_not_settle_the_payment(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Başarısız']);

        $response = $this->gateway->handleCallback($this->callbackData());

        $this->assertSame(TransactionStatus::Failed, $response->status);
    }

    public function test_the_callback_queries_the_order_it_names(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Başarılı']);

        $this->gateway->handleCallback($this->callbackData());

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/api/services/ProcessQuery')
                && $body['ORDER_REF_NUMBER'] === 'ORD-001'
                && $body['MERCHANT'] === 'TEST_MERCHANT';
        });
    }

    public function test_a_callback_without_an_order_reference_is_unknown(): void
    {
        $response = $this->gateway->handleCallback(new CallbackData(gateway: 'hoppa', requestData: []));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
        $this->assertSame('MISSING_ORDER_REFERENCE', $response->errorCode);
        $this->assertNull($response->gatewayTransactionId);
    }

    public function test_an_unreadable_query_leaves_the_payment_unknown(): void
    {
        Http::fake(['*/api/services/ProcessQuery' => Http::response('', 500)]);

        $response = $this->gateway->handleCallback($this->callbackData());

        $this->assertSame(TransactionStatus::Unknown, $response->status);
    }

    public function test_an_unrecognised_transaction_list_leaves_the_payment_unknown(): void
    {
        Http::fake(['*/api/services/ProcessQuery' => Http::response(['STATUS' => 'SUCCESS'])]);

        $response = $this->gateway->handleCallback($this->callbackData());

        $this->assertSame(TransactionStatus::Unknown, $response->status);
    }

    public function test_the_callback_keeps_both_the_provider_records(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Başarılı']);

        $response = $this->gateway->handleCallback($this->callbackData([
            'BANK_AUTH_CODE' => 'AUTH-1',
            'COMMISSION' => '2,92',
        ]));

        $this->assertSame('AUTH-1', $response->gatewayAuthCode);
        $this->assertSame('2,92', $response->metadata['callback']['COMMISSION']);
        $this->assertSame('SUCCESS', $response->metadata['query']['STATUS']);
    }

    public function test_query_payment_reports_a_cancelled_order(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'İptal - Başarılı']);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Voided, $response->status);
    }

    public function test_query_payment_reads_the_order_cancel_status(): void
    {
        Http::fake(['*/api/services/ProcessQuery' => Http::response([
            'STATUS' => 'ORDER_CANCEL',
            'RETURN_CODE' => '300',
        ])]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Voided, $response->status);
    }

    public function test_query_payment_reports_a_refund_through_the_metadata(): void
    {
        $this->fakeQuery(
            ['STATUS_NAME' => 'Ödeme - Başarılı'],
            ['STATUS_NAME' => 'İade - Başarılı'],
        );

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame('refunded', $response->metadata['refund_state']);
    }

    public function test_query_payment_reports_no_refund_on_a_plain_sale(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Başarılı']);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame('none', $response->metadata['refund_state']);
    }

    public function test_query_payment_leaves_an_unfinished_3ds_order_pending(): void
    {
        Http::fake(['*/api/services/ProcessQuery' => Http::response([
            'STATUS' => 'PAYMENT_WAITING',
            'RETURN_CODE' => '106',
            'RETURN_MESSAGE' => 'Ödeme - Bekliyor',
            'TRANSACTIONS' => [
                ['TRANSACTION_ID' => 783335, 'STATUS_NAME' => 'Ödeme - Bekliyor', 'AMOUNT' => '-1,00'],
            ],
        ])]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Pending, $response->status);
    }

    public function test_query_payment_reads_a_waiting_transaction_without_the_order_status(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Bekliyor', 'AMOUNT' => '-1,00']);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Pending, $response->status);
    }

    public function test_query_payment_claims_nothing_about_an_order_it_cannot_find(): void
    {
        Http::fake(['*/api/services/ProcessQuery' => Http::response([
            'STATUS' => 'PROCESS_QUERY',
            'RETURN_CODE' => '400',
            'RETURN_MESSAGE' => 'Referans numarası bulunamadı (Not.ProcessQuery)',
            'TRANSACTIONS' => null,
        ])]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
        $this->assertNull($response->refundedAmount);
        $this->assertNull($response->voided);
    }

    public function test_query_payment_reports_no_refund_as_a_zero_total(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Başarılı', 'AMOUNT' => '-350,00']);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(0, $response->refundedAmount);
        $this->assertFalse($response->voided);
    }

    public function test_query_payment_totals_the_refunds_in_minor_units(): void
    {
        $this->fakeQuery(
            ['STATUS_NAME' => 'Ödeme - Başarılı', 'AMOUNT' => '-1.250,00'],
            ['STATUS_NAME' => 'İade - Başarılı', 'AMOUNT' => '120,50'],
            ['STATUS_NAME' => 'İade - Başarılı', 'AMOUNT' => '-30,25'],
        );

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(15075, $response->refundedAmount);
    }

    public function test_query_payment_claims_no_total_when_a_refund_amount_cannot_be_read(): void
    {
        $this->fakeQuery(
            ['STATUS_NAME' => 'Ödeme - Başarılı', 'AMOUNT' => '-350,00'],
            ['STATUS_NAME' => 'İade - Başarılı'],
        );

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertNull($response->refundedAmount);
    }

    public function test_a_cancelled_order_reports_the_cancelled_amount_as_returned(): void
    {
        $this->fakeQuery(
            ['TRANSACTION_ID' => 783347, 'STATUS_NAME' => 'Ödeme - Bekliyor', 'AMOUNT' => '350,00'],
            ['TRANSACTION_ID' => 783348, 'STATUS_NAME' => 'Ödeme - 3D Doğrulama Bekleniyor', 'AMOUNT' => '350,00'],
            ['TRANSACTION_ID' => 783349, 'STATUS_NAME' => 'Ödeme - Başarılı', 'AMOUNT' => '350,00'],
            ['TRANSACTION_ID' => 783356, 'STATUS_NAME' => 'İptal - Başarılı', 'AMOUNT' => '350,00'],
        );

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Voided, $response->status);
        $this->assertTrue($response->voided);
        $this->assertSame(35000, $response->refundedAmount);
    }

    public function test_a_cancellation_and_a_refund_are_totalled_together(): void
    {
        $this->fakeQuery(
            ['STATUS_NAME' => 'Ödeme - Başarılı', 'AMOUNT' => '350,00'],
            ['STATUS_NAME' => 'İade - Başarılı', 'AMOUNT' => '150,00'],
            ['STATUS_NAME' => 'İptal - Başarılı', 'AMOUNT' => '200,00'],
        );

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(35000, $response->refundedAmount);
    }

    public function test_query_payment_reports_a_cancelled_order_as_voided(): void
    {
        Http::fake(['*/api/services/ProcessQuery' => Http::response([
            'STATUS' => 'ORDER_CANCEL',
            'RETURN_CODE' => '300',
        ])]);

        $response = $this->gateway->queryPayment(new PaymentQuery(gatewayTransactionId: 'ORD-001'));

        $this->assertTrue($response->voided);
    }

    public function test_query_payment_keeps_the_currency_it_asked_about(): void
    {
        $this->fakeQuery(['STATUS_NAME' => 'Ödeme - Başarılı', 'AMOUNT' => '-350,00']);

        $response = $this->gateway->queryPayment(
            new PaymentQuery(gatewayTransactionId: 'ORD-001', currency: 'EUR'),
        );

        $this->assertSame('EUR', $response->currency);
    }

    public function test_a_callback_claims_no_currency_because_hoppa_reports_none(): void
    {
        Http::fake([
            '*/api/services/ProcessQuery' => Http::response([
                'STATUS' => 'SUCCESS',
                'RETURN_CODE' => '0',
                'TRANSACTIONS' => [['STATUS_NAME' => 'Ödeme - Başarılı', 'AMOUNT' => '-350,00']],
            ]),
        ]);

        $response = $this->gateway->handleCallback($this->callbackData([
            'ORDER_REF_NUMBER' => 'ORD-001',
            'STATUS' => 'SUCCESS',
        ]));

        $this->assertNull($response->currency);
    }

    public function test_query_payment_requires_the_order_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->gateway->queryPayment(new PaymentQuery(reference: 'ORD-001'));
    }

    public function test_refund_sends_correct_request_and_returns_refunded_status(): void
    {
        Http::fake([
            '*/api/services/OrderReturn' => Http::response([
                'STATUS' => 'SUCCESS',
                'RETURN_CODE' => '0',
                'REFNO' => 'HOPPA-REFUND-1',
            ]),
        ]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame(TransactionType::Refund, $response->type);
        $this->assertSame('HOPPA-REFUND-1', $response->gatewayOrderId);
        $this->assertSame('TRY', $response->currency);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['ORDER_REF_NUMBER'] === 'ORD-001'
                && $body['AMOUNT'] === '50.00'
                && $body['MERCHANT'] === 'TEST_MERCHANT'
                && $body['SYNC_WITH_POS'] === true;
        });
    }

    public function test_refund_returns_failed_on_error_response(): void
    {
        Http::fake([
            '*/api/services/OrderReturn' => Http::response([
                'STATUS' => 'ERROR',
                'RETURN_CODE' => '5',
                'RETURN_MESSAGE' => 'Refund not allowed.',
            ]),
        ]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('5', $response->errorCode);
    }

    public function test_an_unreachable_refund_endpoint_leaves_the_refund_unknown(): void
    {
        Http::fake(['*/api/services/OrderReturn' => Http::response('', 500)]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
    }

    public function test_get_name_returns_hoppa(): void
    {
        $this->assertSame('hoppa', $this->gateway->getName());
    }

    public function test_void_returns_the_full_amount_through_the_return_service(): void
    {
        Http::fake([
            '*/api/services/OrderReturn' => Http::response([
                'STATUS' => 'SUCCESS',
                'RETURN_CODE' => '0',
                'REFNO' => 'HOPPA-CANCEL-1',
            ]),
        ]);

        $response = $this->gateway->void(new VoidData(
            gatewayTransactionId: 'ORD-001',
            amount: 35000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Voided, $response->status);
        $this->assertSame(TransactionType::Void, $response->type);
        $this->assertSame('HOPPA-CANCEL-1', $response->gatewayOrderId);

        Http::assertSent(fn ($request) => $request->data()['AMOUNT'] === '350.00'
            && $request->data()['ORDER_REF_NUMBER'] === 'ORD-001');
    }

    public function test_a_rejected_void_is_failed_rather_than_voided(): void
    {
        Http::fake([
            '*/api/services/OrderReturn' => Http::response([
                'STATUS' => 'ERROR',
                'RETURN_CODE' => '500',
                'RETURN_MESSAGE' => 'İşlem bulunamadı',
            ]),
        ]);

        $response = $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001', amount: 35000));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('500', $response->errorCode);
    }

    public function test_an_unanswered_void_stays_unknown(): void
    {
        Http::fake(['*/api/services/OrderReturn' => Http::response('', 500)]);

        $response = $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001', amount: 35000));

        $this->assertSame(TransactionStatus::Unknown, $response->status);
        $this->assertSame(TransactionType::Void, $response->type);
    }

    public function test_declares_only_the_operations_hoppa_supports(): void
    {
        $this->assertInstanceOf(ChargesPayments::class, $this->gateway);
        $this->assertInstanceOf(RefundsPayments::class, $this->gateway);
        $this->assertInstanceOf(HandlesCallbacks::class, $this->gateway);
        $this->assertInstanceOf(QueriesPayments::class, $this->gateway);
        $this->assertInstanceOf(ProvidesGatewayCapabilities::class, $this->gateway);
        $this->assertInstanceOf(ProvidesCommissionRates::class, $this->gateway);
        $this->assertInstanceOf(VoidsPayments::class, $this->gateway);

        $this->assertNotInstanceOf(AuthorizesPayments::class, $this->gateway);
        $this->assertNotInstanceOf(CapturesPayments::class, $this->gateway);
        $this->assertNotInstanceOf(HandlesWebhooks::class, $this->gateway);
    }

    public function test_capabilities_cover_both_card_kinds(): void
    {
        $methods = $this->gateway->capabilities()->methods;

        $this->assertContains(PaymentMethod::CreditCard, $methods);
        $this->assertContains(PaymentMethod::DebitCard, $methods);
    }

    public function test_capabilities_list_only_the_operations_hoppa_takes(): void
    {
        $this->assertSame(
            [TransactionType::Payment, TransactionType::Refund, TransactionType::Void],
            $this->gateway->capabilities()->operations,
        );
    }

    public function test_capabilities_list_the_currencies_the_provider_accepts(): void
    {
        $this->assertSame(
            ['TRY', 'USD', 'EUR', 'GBP'],
            $this->gateway->capabilities()->currencies,
        );
    }

    public function test_capabilities_declare_three_d_secure_only(): void
    {
        $capabilities = $this->gateway->capabilities();

        $this->assertTrue($capabilities->threeDs);
        $this->assertFalse($capabilities->nonThreeDs);
    }

    public function test_commission_rates_are_read_from_the_installment_listing(): void
    {
        Http::fake(['*/api/services/GetInstallments' => Http::response([
            'STATUS' => 'SUCCESS',
            'RETURN_CODE' => '0',
            'INSTALLMENTS' => [
                ['FAMILY' => 'bonus', 'INSTALLMENT' => 1, 'RATE' => 0.0203],
                ['FAMILY' => 'maximum', 'INSTALLMENT' => 3, 'RATE' => 0.031],
            ],
        ])]);

        $rates = $this->gateway->commissionRates();

        $this->assertCount(2, $rates);
        $this->assertSame('bonus', $rates[0]->cardFamily);
        $this->assertSame(1, $rates[0]->installments);
        $this->assertSame(2.03, $rates[0]->rate);
        $this->assertNull($rates[0]->cardType);
        $this->assertNull($rates[0]->blockingDays);

        $this->assertSame('maximum', $rates[1]->cardFamily);
        $this->assertSame(3, $rates[1]->installments);
        $this->assertSame(3.10, $rates[1]->rate);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/api/services/GetInstallments')
                && $body['MERCHANT'] === 'TEST_MERCHANT'
                && $body['MERCHANT_KEY'] === 'TEST_KEY';
        });
    }

    public function test_the_providers_wildcard_family_becomes_a_wildcard_row(): void
    {
        Http::fake(['*/api/services/GetInstallments' => Http::response([
            'STATUS' => 'SUCCESS',
            'INSTALLMENTS' => [
                ['FAMILY' => '*', 'INSTALLMENT' => 1, 'RATE' => 0.0225],
                ['FAMILY' => '', 'INSTALLMENT' => 2, 'RATE' => 0.035],
                ['INSTALLMENT' => 3, 'RATE' => 0.045],
            ],
        ])]);

        foreach ($this->gateway->commissionRates() as $rate) {
            $this->assertNull($rate->cardFamily);
        }
    }

    public function test_the_family_is_normalised_the_way_the_bin_service_reports_it(): void
    {
        Http::fake(['*/api/services/GetInstallments' => Http::response([
            'STATUS' => 'SUCCESS',
            'INSTALLMENTS' => [['FAMILY' => ' Bonus ', 'INSTALLMENT' => 1, 'RATE' => 0.0275]],
        ])]);

        $this->assertSame('bonus', $this->gateway->commissionRates()[0]->cardFamily);
    }

    public function test_a_zero_installment_entry_counts_as_a_single_payment(): void
    {
        Http::fake(['*/api/services/GetInstallments' => Http::response([
            'STATUS' => 'SUCCESS',
            'INSTALLMENTS' => [['FAMILY' => 'bonus', 'INSTALLMENT' => 0, 'RATE' => 0.0203]],
        ])]);

        $this->assertSame(1, $this->gateway->commissionRates()[0]->installments);
    }

    public function test_an_entry_without_a_rate_is_skipped(): void
    {
        Http::fake(['*/api/services/GetInstallments' => Http::response([
            'STATUS' => 'SUCCESS',
            'INSTALLMENTS' => [
                ['FAMILY' => 'bonus', 'INSTALLMENT' => 1],
                ['FAMILY' => 'axess', 'INSTALLMENT' => 1, 'RATE' => 0.019],
            ],
        ])]);

        $rates = $this->gateway->commissionRates();

        $this->assertCount(1, $rates);
        $this->assertSame('axess', $rates[0]->cardFamily);
    }

    public function test_a_refused_rate_listing_throws(): void
    {
        Http::fake(['*/api/services/GetInstallments' => Http::response([
            'STATUS' => 'ERROR',
            'RETURN_MESSAGE' => 'Merchant not allowed.',
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Merchant not allowed.');

        $this->gateway->commissionRates();
    }

    public function test_an_unreachable_rate_listing_throws(): void
    {
        Http::fake(['*/api/services/GetInstallments' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);

        $this->gateway->commissionRates();
    }

    private function fakeInitiation(): void
    {
        Http::fake([
            '*/api/pay/EYV3DPay' => Http::response([
                'STATUS' => 'SUCCESS',
                'URL_3DS' => 'https://3ds.hoppa.com',
            ]),
        ]);
    }

    private function fakeQuery(array ...$transactions): void
    {
        Http::fake([
            '*/api/services/ProcessQuery' => Http::response([
                'STATUS' => 'SUCCESS',
                'RETURN_CODE' => '0',
                'TRANSACTIONS' => $transactions,
            ]),
        ]);
    }

    private function callbackData(array $overrides = []): CallbackData
    {
        return new CallbackData(gateway: 'hoppa', requestData: array_merge([
            'STATUS' => 'SUCCESS',
            'ORDER_REF_NUMBER' => 'ORD-001',
            'REFNO' => 'HOPPA-REF-999',
        ], $overrides));
    }

    private function makePaymentRequest(
        int $amount = 10000,
        ?int $installments = null,
        string $expiryYear = '2030',
        array $basketItems = [],
    ): PaymentRequest {
        return new PaymentRequest(
            reference: 'ORD-001',
            amount: $amount,
            currency: 'TRY',
            customerEmail: 'test@example.com',
            customerPhone: '05001234567',
            customerIp: '127.0.0.1',
            callbackUrl: 'https://example.com/callback',
            installments: $installments,
            basketItems: $basketItems,
            card: new Card(
                holderName: 'Test User',
                number: '4111111111111111',
                expiryMonth: '12',
                expiryYear: $expiryYear,
                cvv: '123',
            ),
        );
    }
}
