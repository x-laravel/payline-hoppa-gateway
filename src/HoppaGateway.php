<?php

namespace XLaravel\PaylineHoppaDriver;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class HoppaGateway implements ChargesPayments, Gateway, HandlesCallbacks, RefundsPayments, VoidsPayments
{
    public function __construct(private readonly array $config) {}

    public function getName(): string
    {
        return 'hoppa';
    }

    public function supportedMethods(): array
    {
        return [PaymentMethod::CreditCard];
    }

    public function pay(PaymentRequest $data): PaymentResponse
    {
        $card = $data->card ?? throw new \InvalidArgumentException('Card is required for Hoppa payment.');

        [$firstName, $lastName] = $this->splitName($data->customerName ?? $card->holderName);

        $response = Http::post($this->config['api_url'] . '/api/pay/EYV3DPay', [
            'Config' => [
                'MERCHANT'         => $this->config['merchant_id'],
                'MERCHANT_KEY'     => $this->config['merchant_key'],
                'BACK_URL'         => $data->callbackUrl,
                'PRICES_CURRENCY'  => $data->currency,
                'ORDER_REF_NUMBER' => $data->reference,
                'ORDER_AMOUNT'     => $this->formatAmount($data->amount),
            ],
            'CreditCard' => [
                'CC_OWNER'           => $card->holderName,
                'CC_NUMBER'          => $card->number,
                'EXP_MONTH'          => $card->expiryMonth,
                'EXP_YEAR'           => $card->expiryYear,
                'CC_CVC'             => $card->cvv,
                'INSTALLMENT_NUMBER' => $data->installments ?? 1,
            ],
            'Customer' => [
                'FIRST_NAME' => $firstName,
                'LAST_NAME'  => $lastName,
                'MAIL'       => $data->customerEmail ?? '',
                'PHONE'      => $data->customerPhone ?? '',
                'CITY'       => $data->billingAddress?->city ?? '',
                'STATE'      => $data->billingAddress?->city ?? '',
                'ADDRESS'    => $data->billingAddress?->line1 ?? '',
                'CLIENT_IP'  => $data->customerIp ?? '',
            ],
        ])->json();

        if (($response['STATUS'] ?? '') !== 'SUCCESS') {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                amount: $data->amount,
                currency: $data->currency,
                errorCode: $response['RETURN_CODE'] ?? 'UNKNOWN',
                errorMessage: $response['RETURN_MESSAGE'] ?? 'Payment initiation failed.',
                metadata: $response,
            );
        }

        return new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: $data->reference,
            amount: $data->amount,
            currency: $data->currency,
            redirectUrl: $response['URL_3DS'],
            metadata: $response,
        );
    }

    public function refund(RefundData $data): PaymentResponse
    {
        $response = Http::post($this->config['api_url'] . '/api/services/OrderReturn', [
            'MERCHANT'         => $this->config['merchant_id'],
            'MERCHANT_KEY'     => $this->config['merchant_key'],
            'ORDER_REF_NUMBER' => $data->gatewayTransactionId,
            'AMOUNT'           => $this->formatAmount($data->amount),
            'SYNC_WITH_POS'    => true,
        ])->json();

        $success = ($response['STATUS'] ?? '') === 'SUCCESS' && ($response['RETURN_CODE'] ?? '') === '0';

        return new PaymentResponse(
            status: $success ? TransactionStatus::Successful : TransactionStatus::Failed,
            type: TransactionType::Refund,
            gatewayName: $this->getName(),
            gatewayTransactionId: $data->gatewayTransactionId,
            gatewayResponseCode: $response['RETURN_CODE'] ?? null,
            errorCode: $success ? null : ($response['RETURN_CODE'] ?? 'UNKNOWN'),
            errorMessage: $success ? null : ($response['RETURN_MESSAGE'] ?? 'Refund failed.'),
            metadata: $response,
        );
    }

    public function void(VoidData $data): PaymentResponse
    {
        // Hoppa cancel requires the original amount — pass it via metadata['amount']
        $amount = $data->metadata['amount'] ?? null;

        if ($amount === null) {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: TransactionType::Void,
                gatewayName: $this->getName(),
                gatewayTransactionId: $data->gatewayTransactionId,
                errorCode: 'MISSING_AMOUNT',
                errorMessage: 'Hoppa void requires original amount in metadata["amount"].',
            );
        }

        $response = Http::post($this->config['api_url'] . '/api/services/OrderReturn', [
            'MERCHANT'         => $this->config['merchant_id'],
            'MERCHANT_KEY'     => $this->config['merchant_key'],
            'ORDER_REF_NUMBER' => $data->gatewayTransactionId,
            'AMOUNT'           => $this->formatAmount((int) $amount),
            'SYNC_WITH_POS'    => true,
        ])->json();

        $success = ($response['STATUS'] ?? '') === 'SUCCESS' && ($response['RETURN_CODE'] ?? '') === '0';

        return new PaymentResponse(
            status: $success ? TransactionStatus::Voided : TransactionStatus::Failed,
            type: TransactionType::Void,
            gatewayName: $this->getName(),
            gatewayTransactionId: $data->gatewayTransactionId,
            gatewayResponseCode: $response['RETURN_CODE'] ?? null,
            errorCode: $success ? null : ($response['RETURN_CODE'] ?? 'UNKNOWN'),
            errorMessage: $success ? null : ($response['RETURN_MESSAGE'] ?? 'Void failed.'),
            metadata: $response,
        );
    }

    public function handleCallback(CallbackData $data): PaymentResponse
    {
        $post = $data->requestData;

        // ORDER_REF_NUMBER is echoed back by Hoppa for transaction matching
        $orderId = $post['ORDER_REF_NUMBER'] ?? null;
        $refNo = $post['REFNO'] ?? null;

        if (($post['STATUS'] ?? '') !== 'SUCCESS') {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderId,
                gatewayOrderId: $refNo,
                errorCode: $post['RETURN_CODE'] ?? 'CALLBACK_FAILED',
                errorMessage: $post['RETURN_MESSAGE'] ?? 'Payment failed.',
                metadata: $post,
            );
        }

        return new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderId,
            gatewayOrderId: $refNo,
            metadata: $post,
        );
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
