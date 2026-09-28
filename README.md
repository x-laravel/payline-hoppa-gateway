# payline-hoppa-gateway

[![Tests](https://github.com/x-laravel/payline-hoppa-gateway/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline-hoppa-gateway/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

Hoppa payment gateway for [x-laravel/payline](https://github.com/x-laravel/payline).

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- x-laravel/payline ^2.0

## Installation

```bash
composer require x-laravel/payline-hoppa-gateway
```

## Configuration

Add the `hoppa` block to `config/payline.php` under `gateways`:

```php
'gateways' => [
    'hoppa' => [
        'merchant_id'  => env('HOPPA_MERCHANT_ID'),
        'merchant_key' => env('HOPPA_MERCHANT_KEY'),
        'three_ds_session_minutes' => env('HOPPA_3DS_SESSION_MINUTES', 30),
        'blocking_days' => env('HOPPA_BLOCKING_DAYS'),
    ],
],
```

Set the corresponding environment variables in `.env`:

```dotenv
PAYLINE_GATEWAY=hoppa
PAYLINE_TEST_MODE=true

HOPPA_MERCHANT_ID=your-merchant-id
HOPPA_MERCHANT_KEY=your-merchant-key
```

## Test and Live

The gateway ships both Hoppa addresses and picks between them with `payline.test_mode`:

| `test_mode` | Host |
|-------------|------|
| `true` | `https://posservicetest.esnekpos.com` |
| `false` | `https://posservice.esnekpos.com` |

`PAYLINE_TEST_MODE` defaults to `false`, so an installation that never sets it talks to
the live one. The test environment issues its own merchant identifier and key, so
switching the flag also means switching those values.

A `base_url` in the `hoppa` block wins over both. It carries a scheme and a host only;
the gateway appends its own paths.

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

The gateway does not take the outcome from that POST. Hoppa's callback carries a `HASH`, but the algorithm behind it is not published — the integration document tells merchants to ask support for it. The gateway therefore reads the order reference from the callback and asks `/api/services/ProcessQuery` what actually happened, so a forged POST cannot settle a payment. This costs one extra request per callback.

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
use XLaravel\Payline\Facades\Payline;

Payline::payment($payment)->refund(amount: 5000);
```

Payline finds the sale itself. The amount is in minor units, and an `idempotencyKey` makes
a retry safe.

### Cancelling

```php
Payline::payment($payment)->void();
```

Hoppa cancels through the same `/api/services/OrderReturn` endpoint it refunds through. There
is no field that names the operation: a reversal that returns the whole order amount is
recorded as a cancellation, a smaller one as a refund. Partial refunds accumulate, so the
reversal that brings the returned total up to the order amount is the one that cancels it.

`void()` therefore sends the full amount of the sale and reports `voided`, while `refund()`
sends the amount it was given and reports `successful`. Both reach the same endpoint.

Hoppa records the outcome as its own entry under `TRANSACTIONS`, named `İptal - Başarılı` for
a cancellation and `İade - Başarılı` for a refund, each carrying its amount. The gateway totals
both into `PaymentResponse::$refundedAmount`, because a cancellation returns money just as a
refund does.

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
| `İptal - Başarılı` | `voided`, and its amount counts as returned |
| `Ödeme - Başarılı` | `successful` |
| `Ödeme - Başarısız` | `failed` |
| `Ödeme - Bekliyor` | `pending` |
| anything else | `unknown` |

The order status `PAYMENT_WAITING` means the same as the last row and is read as well. An
order whose customer has not come back from the 3D Secure page answers with both:

```json
{ "STATUS": "PAYMENT_WAITING", "RETURN_CODE": "106", "RETURN_MESSAGE": "Ödeme - Bekliyor",
  "TRANSACTIONS": [{ "STATUS_NAME": "Ödeme - Bekliyor", "AMOUNT": "-1,00" }] }
```

Hoppa sends that same answer while the customer is still on the page, so the gateway reports
it as `pending` and Payline settles it as `expired` once the transaction passes the deadline
set from `three_ds_session_minutes`. An order Hoppa cannot find answers `RETURN_CODE` `400`
with a null `TRANSACTIONS`, which stays `unknown`.

`İade - Başarılı` does not change the status of the sale, since a refund is its own
transaction in Payline. It surfaces as `refund_state` on the metadata, either `refunded` or
`none`, and its `AMOUNT` values are totalled into `PaymentResponse::$refundedAmount` so
Payline can settle an open refund. Amounts arrive in Turkish notation and carry a sign that
depends on the direction, so they are parsed as `-1.250,00` and taken as absolute values;
when one of them cannot be read the gateway reports no total rather than a wrong one.

## Currencies

Hoppa takes `PRICES_CURRENCY` on the payment request and accepts `TRY`, `USD`, `EUR` and
`GBP`, but it never reports a currency back: neither the `EYV3DPay` answer, nor the callback
POSTed to `BACK_URL`, nor the `ProcessQuery` response carries one. The gateway therefore
leaves `PaymentResponse::$currency` null after a callback rather than claiming `TRY`, and
uses `PaymentQuery::$currency` when reconciliation names the currency it is asking about.

## BIN Lookup

Hoppa's BIN service resolves a card family and card type from the first eight digits of
a card number, which is what Payline's commission routing needs. It lives in its own
package, because a merchant charging through another gateway can use it just as well:

```bash
composer require x-laravel/payline-hoppa-bin-lookup
```

See [payline-hoppa-bin-lookup](https://github.com/x-laravel/payline-hoppa-bin-lookup).

## Commission Rates

```shell
php artisan payline:sync-rates --gateway=hoppa --dry-run
php artisan payline:sync-rates --gateway=hoppa
```

`commissionRates()` reads `/api/services/GetInstallments`, which returns a rate per card
family and installment count. The provider reports the rate as a fraction, `0.0275` for a
single installment, and Payline stores a percentage, so the gateway multiplies by a hundred.
A family reported as `*` becomes a wildcard row.

The listing carries no settlement delay, so `blocking_days` comes from the gateway entry
and is written onto every rate the sync stores. It is the number of days Hoppa holds a
payment before the money reaches you, and it belongs to your agreement rather than to
this package, so nothing is assumed when the key is absent: the rates are stored without
it and ranking prices them on the commission alone.

```php
'blocking_days' => 14,
```

Payline turns that into a cost through `routing.cost_of_capital`. A gateway that holds
the money nine days longer than another is not free, and a lower commission can lose to
a shorter wait once the difference is priced.

## Supported Operations

| Operation | Supported | Notes |
|-----------|-----------|-------|
| Pay (3DS) | ✓ | Redirects to Hoppa's 3DS page |
| Refund | ✓ | Partial or full, through `/api/services/OrderReturn` |
| Reconcile | ✓ | `/api/services/ProcessQuery`, keyed on the order reference |
| Authorize | ✗ | Hoppa takes no authorizations |
| Capture | ✗ | Follows from the above |
| Void/Cancel | ✓ | Full amount through `/api/services/OrderReturn`; Hoppa records it as a cancellation |
| Webhooks | ✗ | Hoppa uses a callback-only flow |

Amounts are sent as lira with two decimal places: Payline's `10050` in the minor unit leaves as `100.50`. The basket, when the request carries one, is sent as the `Product` group.

The gateway declares the table above through `ProvidesGatewayCapabilities`, so commission
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
