# payline-hoppa-driver

[![Tests](https://github.com/x-laravel/payline-hoppa-driver/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline-hoppa-driver/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

Hoppa payment gateway driver for [x-laravel/payline](https://github.com/x-laravel/payline).

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- x-laravel/payline ^2.0

## Installation

```bash
composer require x-laravel/payline-hoppa-driver
```

## Configuration

Add the `hoppa` block to `config/payline.php` under `gateways`:

```php
'gateways' => [
    'hoppa' => [
        'api_url'      => env('HOPPA_API_URL', 'https://posservicetest.esnekpos.com'),
        'merchant_id'  => env('HOPPA_MERCHANT_ID'),
        'merchant_key' => env('HOPPA_MERCHANT_KEY'),
    ],
],
```

Set the corresponding environment variables in `.env`:

```dotenv
PAYLINE_DRIVER=hoppa

HOPPA_API_URL=https://posservice.esnekpos.com
HOPPA_MERCHANT_ID=your-merchant-id
HOPPA_MERCHANT_KEY=your-merchant-key
```

## Usage

### Charging a payment

```php
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentRequest;

$data = PaymentRequest::fromPayable(
    payable: $order,
    card: new Card(
        holderName: 'John Doe',
        number: '4111111111111111',
        expiryMonth: '12',
        expiryYear: '2030',
        cvv: '123',
    ),
    installments: 1,
    customerIp: $request->ip(),
);

$response = $order->pay('hoppa')->charge($data);
```

Hoppa uses a **3DS redirect** flow. On success, `pay()` returns a `PaymentResponse` with `status = Pending` and a `redirectUrl` pointing to Hoppa's 3DS page:

```php
if ($response->requiresRedirect()) {
    return redirect($response->redirectUrl);
}
```

### Handling the callback

Payline handles the callback automatically via its built-in route (`/payline/callback/hoppa`). After 3DS completes, Hoppa POSTs to this URL and the customer is sent on according to `payline.routes.callback_response`.

The driver does not take the outcome from that POST. Hoppa's callback carries a `HASH`, but the algorithm behind it is not published — the integration document tells merchants to ask support for it. The driver therefore reads the order reference from the callback and asks `/api/services/ProcessQuery` what actually happened, so a forged POST cannot settle a payment. This costs one extra request per callback.

The order reference is generated per attempt rather than taken from the merchant reference, because Hoppa caps `ORDER_REF_NUMBER` at 24 characters and rejects a reference it has already seen. It is recorded as `gateway_transaction_id`, and refunds and reconciliation are keyed on it.

You can listen to the dispatched events for any post-payment logic:

```php
use XLaravel\Payline\Events\PaymentSucceeded;
use XLaravel\Payline\Events\PaymentFailed;

class HandlePaymentSucceeded
{
    public function handle(PaymentSucceeded $event): void
    {
        $event->payment;     // Payment model
        $event->transaction; // Transaction model
        $event->response;    // PaymentResponse DTO
    }
}
```

### Refund

```php
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\Facades\Payline;

Payline::via('hoppa')->refund(
    new RefundData(
        gatewayTransactionId: $transaction->gateway_transaction_id,
        amount: 5000, // kuruş
        currency: 'TRY',
    ),
    $payment,
    $transaction,
);
```

### Cancelling

Hoppa cancels through the same `/api/services/OrderReturn` endpoint it refunds through, so a cancellation is a refund for the full amount and the driver does not implement `VoidsPayments`. Payline's `void()` releases an authorization, and Hoppa takes no authorizations.

### Reconciliation

```php
Payline::payment($payment)->reconcile();
```

```shell
php artisan payline:reconcile --gateway=hoppa
```

`queryPayment()` reads `/api/services/ProcessQuery` and derives the status from the `STATUS_NAME` of each entry in `TRANSACTIONS`:

| `STATUS_NAME` | Result |
|---------------|--------|
| `İptal - Başarılı` | `voided` |
| `Ödeme - Başarılı` | `successful` |
| `Ödeme - Başarısız` | `failed` |
| anything else | `unknown` |

`İade - Başarılı` does not change the status of the sale, since a refund is its own transaction in Payline. It surfaces as `refund_state` on the metadata, either `refunded` or `none`.

## BIN Lookup

Hoppa provides a BIN lookup service that resolves card family and type from the first 8 digits of a card number. Enable it by setting `payline.bin_lookup.default` to `hoppa`:

```php
// config/payline.php
'bin_lookup' => [
    'default' => env('PAYLINE_BIN_LOOKUP_DRIVER', 'hoppa'),
    'drivers' => [],
],
```

The BIN lookup driver automatically uses the same `api_url` configured under `payline.gateways.hoppa`. Usage:

```php
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\BinLookupManager;

$card = (new Card(
    holderName: 'John Doe',
    number: '4111111111111111',
    expiryMonth: '12',
    expiryYear: '2030',
    cvv: '123',
))->resolveProfile(app(BinLookupManager::class));

// $card->profile->family → 'Maximum'
// $card->profile->type   → CardType::Credit
```

When used with `PaymentRequest`, the resolved `CardProfile` enables automatic gateway routing via `GatewayRouter`:

```php
$data = PaymentRequest::fromPayable(
    payable: $order,
    card: $card, // profile already resolved
    installments: 3,
);

$order->pay()->charge($data); // cheapest gateway selected automatically
```

| Hoppa `Card_Type` | Payline `CardType`   |
|-------------------|----------------------|
| CREDIT            | `CardType::Credit`   |
| DEBIT             | `CardType::Debit`    |

## Supported Operations

| Operation | Supported | Notes |
|-----------|-----------|-------|
| Pay (3DS) | ✓ | Redirects to Hoppa's 3DS page |
| Refund | ✓ | Partial or full, through `/api/services/OrderReturn` |
| Reconcile | ✓ | `/api/services/ProcessQuery`, keyed on the order reference |
| Authorize | ✗ | Hoppa takes no authorizations |
| Capture | ✗ | Follows from the above |
| Void/Cancel | ✗ | Cancelling is a full refund through the refund endpoint |
| Webhooks | ✗ | Hoppa uses a callback-only flow |

Amounts are sent as lira with two decimal places: Payline's `10050` in the minor unit leaves as `100.50`. The basket, when the request carries one, is sent as the `Product` group.

The driver declares the table above through `ProvidesGatewayCapabilities`, so commission
routing skips it for a request it cannot take. Credit and debit cards are both accepted,
and the currencies are the four `PRICES_CURRENCY` values the provider documents.

## Testing

```bash
# Build first (once per PHP version)
DOCKER_BUILDKIT=0 docker compose --profile php83 build

# Run tests
docker compose --profile php83 up
docker compose --profile php84 up
docker compose --profile php85 up
```

Or directly:

```bash
composer test
```

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).
