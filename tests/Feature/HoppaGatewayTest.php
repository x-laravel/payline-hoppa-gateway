<?php

namespace XLaravel\PaylineHoppaDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
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
            ]),
        ]);

        $response = $this->gateway->pay($this->makePaymentRequest());

        $this->assertSame(TransactionStatus::Pending, $response->status);
        $this->assertSame(TransactionType::Payment, $response->type);
        $this->assertSame('ORD-001', $response->gatewayTransactionId);
        $this->assertSame('https://3ds.hoppa.com/auth?token=abc123', $response->redirectUrl);
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

    public function test_pay_sends_correct_fields_to_hoppa(): void
    {
        Http::fake([
            '*/api/pay/EYV3DPay' => Http::response(['STATUS' => 'SUCCESS', 'URL_3DS' => 'https://3ds.hoppa.com']),
        ]);

        $this->gateway->pay($this->makePaymentRequest(amount: 15000, installments: 3));

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['Config']['MERCHANT'] === 'TEST_MERCHANT'
                && $body['Config']['MERCHANT_KEY'] === 'TEST_KEY'
                && $body['Config']['ORDER_REF_NUMBER'] === 'ORD-001'
                && $body['Config']['ORDER_AMOUNT'] === '150.00'
                && $body['Config']['PRICES_CURRENCY'] === 'TRY'
                && $body['CreditCard']['CC_NUMBER'] === '4111111111111111'
                && $body['CreditCard']['INSTALLMENT_NUMBER'] === 3;
        });
    }

    public function test_pay_formats_amount_correctly(): void
    {
        Http::fake([
            '*/api/pay/EYV3DPay' => Http::response(['STATUS' => 'SUCCESS', 'URL_3DS' => 'https://3ds.hoppa.com']),
        ]);

        $this->gateway->pay($this->makePaymentRequest(amount: 10050));

        Http::assertSent(fn ($r) => $r->data()['Config']['ORDER_AMOUNT'] === '100.50');
    }

    public function test_handleCallback_returns_successful_on_success_status(): void
    {
        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'hoppa',
            requestData: [
                'STATUS' => 'SUCCESS',
                'ORDER_REF_NUMBER' => 'ORD-001',
                'REFNO' => 'HOPPA-REF-999',
                'COMMISSION' => '2,92',
                'COMMISSION_RATE' => '2.92',
            ],
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame('ORD-001', $response->gatewayTransactionId);
        $this->assertSame('HOPPA-REF-999', $response->gatewayOrderId);
    }

    public function test_handleCallback_returns_failed_on_error_status(): void
    {
        $response = $this->gateway->handleCallback(new CallbackData(
            gateway: 'hoppa',
            requestData: [
                'STATUS' => 'ERROR',
                'ORDER_REF_NUMBER' => 'ORD-001',
                'RETURN_CODE' => 'INSUFFICIENT_FUNDS',
                'RETURN_MESSAGE' => 'Insufficient funds.',
            ],
        ));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('INSUFFICIENT_FUNDS', $response->errorCode);
        $this->assertSame('ORD-001', $response->gatewayTransactionId);
    }

    public function test_refund_sends_correct_request_and_returns_refunded_status(): void
    {
        Http::fake([
            '*/api/services/OrderReturn' => Http::response([
                'STATUS' => 'SUCCESS',
                'RETURN_CODE' => '0',
            ]),
        ]);

        $response = $this->gateway->refund(new RefundData(
            gatewayTransactionId: 'ORD-001',
            amount: 5000,
            currency: 'TRY',
        ));

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame(TransactionType::Refund, $response->type);

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

    public function test_void_returns_voided_status_when_amount_in_metadata(): void
    {
        Http::fake([
            '*/api/services/OrderReturn' => Http::response([
                'STATUS' => 'SUCCESS',
                'RETURN_CODE' => '0',
            ]),
        ]);

        $response = $this->gateway->void(new VoidData(
            gatewayTransactionId: 'ORD-001',
            metadata: ['amount' => 10000],
        ));

        $this->assertSame(TransactionStatus::Voided, $response->status);
        $this->assertSame(TransactionType::Void, $response->type);

        Http::assertSent(fn ($r) => $r->data()['AMOUNT'] === '100.00'
            && $r->data()['ORDER_REF_NUMBER'] === 'ORD-001');
    }

    public function test_void_returns_failed_when_amount_missing_from_metadata(): void
    {
        $response = $this->gateway->void(new VoidData(gatewayTransactionId: 'ORD-001'));

        $this->assertSame(TransactionStatus::Failed, $response->status);
        $this->assertSame('MISSING_AMOUNT', $response->errorCode);
    }

    public function test_get_name_returns_hoppa(): void
    {
        $this->assertSame('hoppa', $this->gateway->getName());
    }

    public function test_declares_only_the_operations_hoppa_supports(): void
    {
        $this->assertInstanceOf(ChargesPayments::class, $this->gateway);
        $this->assertInstanceOf(RefundsPayments::class, $this->gateway);
        $this->assertInstanceOf(VoidsPayments::class, $this->gateway);
        $this->assertInstanceOf(HandlesCallbacks::class, $this->gateway);

        $this->assertNotInstanceOf(AuthorizesPayments::class, $this->gateway);
        $this->assertNotInstanceOf(CapturesPayments::class, $this->gateway);
        $this->assertNotInstanceOf(HandlesWebhooks::class, $this->gateway);
    }

    private function makePaymentRequest(int $amount = 10000, ?int $installments = null): PaymentRequest
    {
        return new PaymentRequest(
            reference: 'ORD-001',
            amount: $amount,
            currency: 'TRY',
            customerEmail: 'test@example.com',
            customerPhone: '05001234567',
            customerIp: '127.0.0.1',
            callbackUrl: 'https://example.com/callback',
            installments: $installments,
            card: new Card(
                holderName: 'Test User',
                number: '4111111111111111',
                expiryMonth: '12',
                expiryYear: '2030',
                cvv: '123',
            ),
        );
    }
}
