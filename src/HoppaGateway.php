<?php

namespace XLaravel\Payline\Gateways\Hoppa;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\ProvidesCommissionRates;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
use XLaravel\Payline\Contracts\QueriesPayments;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\BasketItem;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CommissionRateData;
use XLaravel\Payline\DTOs\GatewayCapabilities;
use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class HoppaGateway implements ChargesPayments, Gateway, HandlesCallbacks, ProvidesCommissionRates, ProvidesGatewayCapabilities, QueriesPayments, RefundsPayments, VoidsPayments
{
    private const string TEST_BASE_URL = 'https://posservicetest.esnekpos.com';

    private const string LIVE_BASE_URL = 'https://posservice.esnekpos.com';

    private const int ORDER_REFERENCE_LENGTH = 24;

    private const string PAYMENT_WAITING = 'PAYMENT_WAITING';

    private const int THREE_DS_SESSION_MINUTES = 30;

    private readonly string $baseUrl;

    public function __construct(private readonly array $config)
    {
        $this->baseUrl = $config['base_url']
            ?? (($config['test_mode'] ?? false) ? self::TEST_BASE_URL : self::LIVE_BASE_URL);
    }

    public function getName(): string
    {
        return 'hoppa';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            operations: [TransactionType::Payment, TransactionType::Refund, TransactionType::Void],
            methods: [PaymentMethod::CreditCard, PaymentMethod::DebitCard],
            currencies: ['TRY', 'USD', 'EUR', 'GBP'],
            threeDs: true,
            nonThreeDs: false,
            partialRefunds: true,
            statusQueries: true,
        );
    }

    public function pay(PaymentRequest $data): PaymentResponse
    {
        $card = $data->card ?? throw new InvalidArgumentException('Card is required for Hoppa payment.');

        [$firstName, $lastName] = $this->splitName($data->customerName ?? $card->holderName);
        $orderRef = Str::random(self::ORDER_REFERENCE_LENGTH);

        $payload = [
            'Config' => [
                'MERCHANT' => $this->config['merchant_id'],
                'MERCHANT_KEY' => $this->config['merchant_key'],
                'BACK_URL' => $data->callbackUrl,
                'PRICES_CURRENCY' => strtoupper($data->currency),
                'ORDER_REF_NUMBER' => $orderRef,
                'ORDER_AMOUNT' => $this->formatAmount($data->amount),
            ],
            'CreditCard' => [
                'CC_NUMBER' => $card->number,
                'EXP_MONTH' => $card->expiryMonth,
                'EXP_YEAR' => $this->fullYear($card->expiryYear),
                'CC_CVV' => $card->cvv,
                'CC_OWNER' => $card->holderName,
                'INSTALLMENT_NUMBER' => (string) ($data->installments ?? 1),
            ],
            'Customer' => [
                'FIRST_NAME' => $firstName,
                'LAST_NAME' => $lastName,
                'MAIL' => $data->customerEmail ?? '',
                'PHONE' => $data->customerPhone ?? '',
                'CITY' => $data->billingAddress?->city ?? '',
                'STATE' => $data->billingAddress?->state ?? '',
                'ADDRESS' => $data->billingAddress?->line1 ?? '',
                'CLIENT_IP' => $data->customerIp ?? '',
            ],
        ];

        if ($data->basketItems !== []) {
            $payload['Product'] = array_map($this->productLine(...), $data->basketItems);
        }

        $response = Http::post($this->baseUrl . '/api/pay/EYV3DPay', $payload)->json() ?? [];

        if (($response['STATUS'] ?? '') !== 'SUCCESS' || blank($response['URL_3DS'] ?? null)) {
            return new PaymentResponse(
                status: TransactionStatus::Failed,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderRef,
                amount: $data->amount,
                currency: $data->currency,
                errorCode: $response['RETURN_CODE'] ?? 'UNKNOWN',
                errorMessage: $response['RETURN_MESSAGE'] ?? 'Payment initiation failed.',
                metadata: $response ?: null,
            );
        }

        return new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderRef,
            gatewayOrderId: $response['REFNO'] ?? null,
            amount: $data->amount,
            currency: $data->currency,
            redirectUrl: $response['URL_3DS'],
            metadata: $response,
            expiresAt: now()->addMinutes($this->threeDsSessionMinutes()),
        );
    }

    private function threeDsSessionMinutes(): int
    {
        return (int) ($this->config['three_ds_session_minutes'] ?? self::THREE_DS_SESSION_MINUTES);
    }

    public function refund(RefundData $data): PaymentResponse
    {
        return $this->reverse(
            $data->gatewayTransactionId,
            $data->amount,
            $data->currency,
            TransactionType::Refund,
            TransactionStatus::Successful,
        );
    }

    public function void(VoidData $data): PaymentResponse
    {
        return $this->reverse(
            $data->gatewayTransactionId,
            $data->amount,
            $data->currency,
            TransactionType::Void,
            TransactionStatus::Voided,
        );
    }

    private function reverse(
        string $orderRef,
        int $amount,
        string $currency,
        TransactionType $type,
        TransactionStatus $settled,
    ): PaymentResponse {
        $response = Http::post($this->baseUrl . '/api/services/OrderReturn', [
            'MERCHANT' => $this->config['merchant_id'],
            'MERCHANT_KEY' => $this->config['merchant_key'],
            'ORDER_REF_NUMBER' => $orderRef,
            'AMOUNT' => $this->formatAmount($amount),
            'SYNC_WITH_POS' => true,
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            return new PaymentResponse(
                status: TransactionStatus::Unknown,
                type: $type,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderRef,
                currency: $currency,
                errorCode: (string) $response->status(),
                errorMessage: 'Reversal request failed.',
                metadata: $body ?: null,
            );
        }

        $success = ($body['STATUS'] ?? '') === 'SUCCESS' && ($body['RETURN_CODE'] ?? '') === '0';

        return new PaymentResponse(
            status: $success ? $settled : TransactionStatus::Failed,
            type: $type,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderRef,
            gatewayOrderId: $this->nullIfBlank($body['REFNO'] ?? null),
            gatewayResponseCode: $this->nullIfBlank($body['RETURN_CODE'] ?? null),
            gatewayResponseMessage: $this->nullIfBlank($body['RETURN_MESSAGE'] ?? null),
            currency: $currency,
            errorCode: $success ? null : ($this->nullIfBlank($body['RETURN_CODE'] ?? null) ?? 'UNKNOWN'),
            errorMessage: $success ? null : ($this->nullIfBlank($body['RETURN_MESSAGE'] ?? null) ?? 'Reversal failed.'),
            metadata: $body ?: null,
        );
    }

    public function handleCallback(CallbackData $data): PaymentResponse
    {
        $post = $data->requestData;
        $orderRef = $post['ORDER_REF_NUMBER'] ?? null;

        if (blank($orderRef)) {
            return new PaymentResponse(
                status: TransactionStatus::Unknown,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                errorCode: 'MISSING_ORDER_REFERENCE',
                errorMessage: 'Hoppa callback carried no order reference.',
            );
        }

        return $this->orderState($orderRef, $post);
    }

    public function commissionRates(): array
    {
        $response = Http::post($this->baseUrl . '/api/services/GetInstallments', [
            'MERCHANT' => $this->config['merchant_id'],
            'MERCHANT_KEY' => $this->config['merchant_key'],
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['STATUS'] ?? '') !== 'SUCCESS') {
            throw new RuntimeException(sprintf(
                'Hoppa refused the rate listing: %s',
                $body['RETURN_MESSAGE'] ?? (string) $response->status(),
            ));
        }

        $rates = [];

        foreach ($body['INSTALLMENTS'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['RATE'], $entry['INSTALLMENT'])) {
                continue;
            }

            $rates[] = new CommissionRateData(
                rate: round((float) $entry['RATE'] * 100, 4),
                installments: max(1, (int) $entry['INSTALLMENT']),
                cardFamily: $this->cardFamily($entry['FAMILY'] ?? null),
                blockingDays: isset($this->config['blocking_days'])
                    ? (int) $this->config['blocking_days']
                    : null,
            );
        }

        return $rates;
    }

    public function queryPayment(PaymentQuery $query): PaymentResponse
    {
        $orderRef = $query->gatewayTransactionId
            ?? throw new InvalidArgumentException('Hoppa requires the order reference to query a payment.');

        return $this->orderState($orderRef, currency: $query->currency);
    }

    private function orderState(string $orderRef, array $callback = [], ?string $currency = null): PaymentResponse
    {
        $response = Http::post($this->baseUrl . '/api/services/ProcessQuery', [
            'MERCHANT' => $this->config['merchant_id'],
            'MERCHANT_KEY' => $this->config['merchant_key'],
            'ORDER_REF_NUMBER' => $orderRef,
        ]);

        $body = $response->json() ?? [];
        $metadata = $callback === [] ? $body : ['callback' => $callback, 'query' => $body];

        if (! $response->successful()) {
            return new PaymentResponse(
                status: TransactionStatus::Unknown,
                type: TransactionType::Payment,
                gatewayName: $this->getName(),
                gatewayTransactionId: $orderRef,
                currency: $currency,
                errorCode: (string) $response->status(),
                errorMessage: 'Order query failed.',
                metadata: $metadata ?: null,
            );
        }

        $names = $this->transactionNames($body);
        $status = $this->statusFrom($body, $names);
        $settled = in_array($status, [TransactionStatus::Successful, TransactionStatus::Voided], true);
        $found = $status !== TransactionStatus::Unknown;

        return new PaymentResponse(
            status: $status,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: $orderRef,
            gatewayOrderId: $this->nullIfBlank($callback['REFNO'] ?? $body['REFNO'] ?? null),
            gatewayAuthCode: $this->nullIfBlank($callback['BANK_AUTH_CODE'] ?? null),
            gatewayResponseCode: $this->nullIfBlank($body['RETURN_CODE'] ?? null),
            gatewayResponseMessage: $this->nullIfBlank($body['RETURN_MESSAGE'] ?? null),
            currency: $currency,
            errorCode: $settled ? null : $this->nullIfBlank($callback['ERROR_CODE'] ?? $body['RETURN_CODE'] ?? null),
            errorMessage: $settled ? null : $this->nullIfBlank($callback['RETURN_MESSAGE_TR'] ?? $callback['RETURN_MESSAGE'] ?? $body['RETURN_MESSAGE'] ?? null),
            metadata: $this->withRefundState($metadata, $names),
            refundedAmount: $found ? $this->refundedAmount($body) : null,
            voided: $found ? $status === TransactionStatus::Voided : null,
        );
    }

    private function statusFrom(array $body, array $names): TransactionStatus
    {
        if (($body['STATUS'] ?? '') === 'ORDER_CANCEL' || $this->has($names, 'İptal', 'Başarılı')) {
            return TransactionStatus::Voided;
        }

        if ($this->has($names, 'Ödeme', 'Başarılı')) {
            return TransactionStatus::Successful;
        }

        if ($this->has($names, 'Ödeme', 'Başarısız')) {
            return TransactionStatus::Failed;
        }

        if (($body['STATUS'] ?? '') === self::PAYMENT_WAITING || $this->has($names, 'Ödeme', 'Bekliyor')) {
            return TransactionStatus::Pending;
        }

        return TransactionStatus::Unknown;
    }

    private function transactionNames(array $body): array
    {
        $transactions = $body['TRANSACTIONS'] ?? [];

        if (! is_array($transactions)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($transaction) => is_array($transaction) ? ($transaction['STATUS_NAME'] ?? null) : null,
            $transactions,
        )));
    }

    private function has(array $names, string $operation, string $outcome): bool
    {
        foreach ($names as $name) {
            if (str_starts_with($name, $operation) && str_contains($name, $outcome)) {
                return true;
            }
        }

        return false;
    }

    private function withRefundState(array $metadata, array $names): array
    {
        $metadata['refund_state'] = $this->has($names, 'İade', 'Başarılı') ? 'refunded' : 'none';

        return $metadata;
    }

    private function refundedAmount(array $body): ?int
    {
        $total = 0;

        foreach ($body['TRANSACTIONS'] ?? [] as $transaction) {
            if (! is_array($transaction) || ! $this->returnsMoney($transaction['STATUS_NAME'] ?? '')) {
                continue;
            }

            $amount = $this->minorUnits($transaction['AMOUNT'] ?? null);

            if ($amount === null) {
                return null;
            }

            $total += $amount;
        }

        return $total;
    }

    private function returnsMoney(string $name): bool
    {
        return $this->has([$name], 'İade', 'Başarılı')
            || $this->has([$name], 'İptal', 'Başarılı');
    }

    private function minorUnits(mixed $amount): ?int
    {
        if (! is_string($amount) && ! is_numeric($amount)) {
            return null;
        }

        $normalised = str_replace([' ', '.', ','], ['', '', '.'], (string) $amount);

        return is_numeric($normalised) ? (int) round(abs((float) $normalised) * 100) : null;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        return blank($value) ? null : (string) $value;
    }

    private function cardFamily(?string $family): ?string
    {
        $family = mb_strtolower(trim((string) $family), 'UTF-8');

        return $family === '' || $family === '*' ? null : $family;
    }

    private function productLine(BasketItem $item): array
    {
        return [
            'PRODUCT_ID' => $item->id,
            'PRODUCT_NAME' => $item->name,
            'PRODUCT_CATEGORY' => $item->category,
            'PRODUCT_DESCRIPTION' => $item->name,
            'PRODUCT_AMOUNT' => $this->formatAmount($item->price * $item->quantity),
        ];
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    private function fullYear(string $year): string
    {
        return strlen($year) === 4 ? $year : '20' . substr($year, -2);
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
