# Cryptoway PHP SDK

Take crypto payments on a PHP site through the [Cryptoway](https://cryptoway.com) gateway.

## What you get

Three ways to take money:

- **Payment page.** You create an invoice, the buyer pays on Cryptoway's page: picks a coin, sees the
  address and the QR code. The least code on your side.
- **On your own page.** The buyer picks the coin on your site and gets the address and the exact
  amount at once. Nobody leaves your checkout; the screen is yours.
- **A permanent address per customer.** For balances and top-ups: one address for ever, any amount,
  every transfer reported to you with the customer's id.

And the part that is the same in all three:

- Signed API requests, with timeouts, retries where they are safe and errors that come back as values
  instead of exceptions, so a webhook handler always finishes its work.
- Webhook checks in one call: freshness, signature and replay. A forged, stale or repeated
  notification is refused with the right HTTP status.
- The money rules: an underpayment is never a payment, an overpayment is reported, an expired invoice
  that received coins goes to a person instead of being cancelled, and a transfer is credited once.
- Explained error messages for the cases merchants actually hit, ready for translation.

The same code runs the Cryptoway extensions for OpenCart, PrestaShop and BILLmanager. The test suite
runs on PHP 8.1 to 8.5.

## Install

```sh
composer require cryptoway/php-sdk
```

Requires PHP 8.1 or newer with `ext-curl`, `ext-json` and `ext-mbstring`.

You need two things from your Cryptoway account (Projects → your project):

| Value | Used for |
| --- | --- |
| **Project ID** and **Secret key** | signing API requests |
| **Callback key** | checking the webhooks Cryptoway sends you |

## Create an invoice and handle the webhook

```php
use Cryptoway\Core\{ApiClient, ApiError, Amounts, Webhook};

$api = new ApiClient(api_key_id: getenv('CRYPTOWAY_PROJECT_ID'), api_key_secret: getenv('CRYPTOWAY_SECRET_KEY'));

$invoice = $api->createInvoice([
    'name'                 => 'Order 1042',
    'target_value_fiat'    => 30.26,
    'target_currency_fiat' => 'EUR',
    'external_id'          => '1042-' . bin2hex(random_bytes(8)),  // your own reference
    'callback_url'         => 'https://shop.example.com/cryptoway/webhook',
]);

if ($invoice instanceof ApiError) {
    exit('Cryptoway: ' . $invoice->getMessage());          // failures are values, not exceptions
}

// Send the buyer to $invoice['pay_url'] and store $invoice['id'] on the order.

// ...and in the webhook endpoint:
$event = (new Webhook(getenv('CRYPTOWAY_CALLBACK_KEY')))
    ->check(file_get_contents('php://input'), $_SERVER['HTTP_X_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_SIGNATURE'] ?? '');

http_response_code($event->getHttpStatus());

if ($event->isOk() && 'executed' === ($event->getPayload()['status'] ?? '')) {
    // An invoice event names the invoice at `id`, a deposit event at `invoice.id`.
    $invoice_id = $event->getPayload()['id'] ?? ($event->getPayload()['invoice']['id'] ?? '');
    $fresh = '' !== $invoice_id ? $api->getInvoice($invoice_id) : null;  // the body carries no amount, so read it back

    if (!$fresh instanceof ApiError && Amounts::invoiceIsCovered($fresh)) {
        // Only here is the order paid in full.
    }
}
```

A new invoice has no coin yet: `symbol_id` and `target_value_symbol` stay empty until the buyer
picks a coin and network on the payment page. The invoice can also ask for more than the order
total when the project adds a markup or charges the buyer the fee.

Three runnable scripts are in [`examples/`](examples): `create_invoice.php`, `handle_webhook.php`
and `pay_on_site.php`.

## Pay on your own page

You do not have to send the buyer to Cryptoway. Let them pick the coin on your page, create the
invoice with that coin, and show the address and the amount yourself:

```php
$symbols = $api->getSymbols();             // coins and networks that can be paid with right now

// ...the buyer picks one; its `id` (USDTBEP20, BTC, ...) comes back from your form:
$invoice = $api->createInvoice([
    'name'                 => 'Order 1042',
    'target_value_fiat'    => 30.26,
    'target_currency_fiat' => 'EUR',
    'symbol_id'            => $_POST['coin'],
    'external_id'          => '1042-' . bin2hex(random_bytes(8)),
    'callback_url'         => 'https://shop.example.com/cryptoway/webhook',
]);

$invoice['target_wallet']['value'];        // the address: put it in a QR code
$invoice['target_value_symbol'];           // the exact amount to send, fees and markup included
$invoice['deadline_at'];                   // until when
```

`getSymbols()` reads every page of `GET /symbol/` and leaves out what cannot be paid to: virtual
entries (a coin with no network, like `USDT`) and inactive ones. `Coins::label()` turns an id into
`USDT BEP-20` for the picker.

Everything after that is the same as with the payment page: the deposit webhook names the invoice,
you read it back and check `Amounts::invoiceIsCovered()` before releasing the goods.

## Permanent addresses

For balances and top-ups rather than orders: one address per customer, any amount, no deadline.
Every transfer to it arrives as its own deposit webhook.

```php
$address = $api->createAddress([
    'symbol_id'    => 'USDTBEP20',
    'external_id'  => 'customer-42',                      // your id, echoed in every webhook
    'callback_url' => 'https://shop.example.com/cryptoway/topup',
]);

$address['value'];                         // store it on the customer together with $address['id']
```

Keep the address on your side; `listAddresses()` is there for reconciliation, not for lookups on
every page view. When a deposit webhook arrives, credit the customer named by `external_id` with
`amount` once the status is `executed`, and never on `dirty_lock`. Use `blockchain_data.hash` as
the key that stops the same transfer being credited twice.

## The webhook signature

Cryptoway posts every event to your `callback_url` with two headers:

```
X-TIMESTAMP: 1757500000
X-SIGNATURE: 06ef50cc480a29f2157dde96b237e4b896d71efb106cee7baf6fc8581d5d93d8
```

The signature is

```
HMAC-SHA256(callback key, timestamp . canonical_json(body))
```

where `canonical_json` is what Python's `json.dumps(data, separators=(",", ":"), sort_keys=True)`
produces: object keys sorted recursively, list order kept, non-ASCII escaped as `\uXXXX`, `/`
left alone, no whitespace. `CanonicalJson::encode()` reproduces it byte for byte. It also pins
`serialize_precision` to `-1` for the call, because a php.ini that sets it to `17` prints `30.26`
as `30.260000000000002` and breaks every signature.

`Webhook::check()` does three things:

1. **Freshness.** A delivery more than `Signature::WEBHOOK_TOLERANCE` (300 s) away from your clock
   is refused. Without it, a captured request stays valid for ever. This runs first, so a stale
   but genuine delivery is answered `400`, not `401`.
2. **Signature.** Compared with `hash_equals()`. Cryptoway signs invoice events as a flat body and
   deposit events inside a `{"params":"","body":{…}}` envelope, so both forms are tried with the
   same key.
3. **Replay.** `getFingerprint()` returns a stable id for the delivery. Store it *after* the event's
   work has succeeded and pass a `seen` callback, so a redelivery is recognised:

```php
$webhook = new Webhook($callback_key, fn (string $id): bool => $db->eventWasApplied($id));
```

Answer Cryptoway with `$event->getHttpStatus()`:

| Status | When |
| --- | --- |
| `200` | accepted, or a repeat of an event you already applied |
| `400` | stale or malformed timestamp, empty body, body that is not JSON |
| `401` | signature missing or wrong |
| `413` | body over 64 KB |
| `503` | no callback key stored yet, or you could not do the work |

Anything that is not 2xx is delivered again, and Cryptoway keeps at it: a wrong answer can bring
dozens of redeliveries. That is what you want for a temporary failure, and not for a forged request.

## Error handling

An API call never throws. It returns the decoded response as an array, or an `ApiError`:

```php
$invoice = $api->getInvoice($invoice_id);

if ($invoice instanceof ApiError) {
    $log->error($invoice->getMessage());   // already explained in English
    $invoice->getCode();                   // http_error | api_error | bad_invoice_id
    $invoice->getStatus();                 // HTTP status, 0 when the request never got an answer
}
```

This is deliberate. A webhook handler has to finish its work and send its reply even when the API
is unreachable, and an exception thrown out of a callback loses the event.

`getMessage()` covers the cases merchants actually hit: keys bound to another IP address, a project
that is not active yet, a clock outside the API's ±15 s window, rate limiting. Pass a `translator`
callable to the constructor to return them in your own language; the keys are in
`ApiClient::MESSAGES`.

`GET` requests are retried twice after a network error or a 5xx. A create is never repeated as it
was sent. The one repeat `POST /invoice/` can make is the same body without the `redirect_url`
field, after a 400 or 422 that carried it, so a deployment that rejects the field still opens a
checkout. A 500 is never followed by a repeat: it can arrive after the invoice was created, and a
repeat would open a second one.

Debug logging (`debug: true` with a `logger` callable) masks secrets in bodies, headers and URL
query strings before anything is written, and the account's personal data from the profile (email,
Telegram, referral code) with them.

## The money rules are yours to enforce

The SDK gives you the arithmetic. What to do with the answer is the merchant's decision. Two rules
are worth stating plainly:

- **An underpaid invoice is not a paid invoice.** `Amounts::invoiceIsCovered()` compares plain
  decimal strings digit by digit, with no tolerance that could swallow a shortfall. If it returns
  `false`, do not release the goods, whatever the invoice status says. `Amounts::shortfall()` tells
  you what is missing, so you can ask for the rest or refund what arrived.
- **An expired invoice that received coins needs a human.** Money can land after the deadline. Put
  such an order in front of a person instead of cancelling it. The coins are real and the buyer
  will ask about them.

`Amounts::check()` compares the invoice against the order total. An invoice that asks for *more*
than the order total is normal (project markup, buyer-paid network fee) and comes back as `ok` with
`over => true`. An invoice that asks for *less* is an `amount_mismatch` and should stop the order.

## What is in the package

| Class | Use |
| --- | --- |
| `ApiClient` | `createInvoice()`, `getInvoice()`, `getProfile()`, `getSymbols()`, `createAddress()`, `listAddresses()`, plus `payUrlIsTrusted()` and `urlIsLocal()` guards |
| `ApiError` | a failed call, returned as a value |
| `Webhook`, `WebhookResult` | checking an incoming notification |
| `Signature` | signing and verification, if you need them directly |
| `CanonicalJson` | the canonical form the signature is taken over |
| `Amounts` | covered / short / overpaid, and invoice against order total |
| `Coins` | `USDTBEP20` → `USDT` + `BEP-20`, for display |

The constructor takes named arguments, which is the readable way to reach the optional ones:

```php
new ApiClient(
    api_key_id: $project_id,
    api_key_secret: $secret_key,
    debug: true,
    logger: fn (string $line) => $log->debug($line),
    translator: fn (string $key, array $args) => __($key, $args),   // keys from ApiClient::MESSAGES
    user_agent: 'MyShop/2.4 (cryptoway-php-sdk)',
);
```

Pass `user_agent`. Left out, the client reports a bare `CryptowayPHP`, which makes your traffic
hard to tell apart in Cryptoway's logs.

**Not included: `OrderManager`.** The shop extensions share a class that applies invoice events to
an order. It takes a shop's meta model, its order-status ids and its translator, and its defaults
are OpenCart's numeric status ids, so it would only mislead here. The rules it encodes are under
*The money rules are yours to enforce* above.

## Tests

```sh
composer install
composer test
```

The suite covers the signature vectors, canonical JSON, the amount rules and the webhook refusals.
Invoice creation and read-back run against a stub that speaks the API's protocol on the loopback
interface and checks the signature itself, so no test reaches the internet:

```sh
sh tools/run-tests.sh          # PHP 8.1 - 8.5 in Docker, each container with --network none
```

Composer itself needs the network to fetch PHPUnit; the test runs that follow do not.

## License

MIT. See [LICENSE](LICENSE).
