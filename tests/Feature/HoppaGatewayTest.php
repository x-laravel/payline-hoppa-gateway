<?php

namespace XLaravel\PaylineHoppaDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\Contracts\QueriesPayments;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\BasketItem;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\PaylineHoppaDriver\HoppaGateway;
use XLaravel\PaylineHoppaDriver\Tests\TestCase;

class HoppaGatewayTest extends TestCase
{
    private HoppaGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new HoppaGateway(config('payline.gateways.hoppa'));
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

    public function test_declares_only_the_operations_hoppa_supports(): void
    {
        $this->assertInstanceOf(ChargesPayments::class, $this->gateway);
        $this->assertInstanceOf(RefundsPayments::class, $this->gateway);
        $this->assertInstanceOf(HandlesCallbacks::class, $this->gateway);
        $this->assertInstanceOf(QueriesPayments::class, $this->gateway);

        $this->assertNotInstanceOf(VoidsPayments::class, $this->gateway);
        $this->assertNotInstanceOf(AuthorizesPayments::class, $this->gateway);
        $this->assertNotInstanceOf(CapturesPayments::class, $this->gateway);
        $this->assertNotInstanceOf(HandlesWebhooks::class, $this->gateway);
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
