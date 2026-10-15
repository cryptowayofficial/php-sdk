# Changelog

All notable changes to this package are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-15

Payment on the merchant's own page, and permanent addresses.

### Added

- `ApiClient::getSymbols()` — the coins and networks that can be paid with right now, every
  page of `GET /symbol/`, without virtual and inactive entries. Build your coin picker from it.
- `createInvoice()` with `symbol_id` is now documented as the way to pay without leaving the
  site: the answer carries the address in `target_wallet.value` and the amount in
  `target_value_symbol`, so the page shows them itself. The client passed the field before; the
  README and an example (`examples/pay_on_site.php`) now show the flow.
- `ApiClient::createAddress()` — a permanent deposit address (`POST /user/address/`) for one
  customer or account: `symbol_id`, optional `external_id`, `callback_url`, `description`. A
  coin without its network is refused before any request is made.
- `ApiClient::listAddresses()` — the addresses created so far (`GET /user/address/`), with the
  API's filters and paging.

### Notes

- Nothing changed for invoices created without `symbol_id`, for the webhook check or for the
  amount rules. Packages on 1.0.0 upgrade without code changes.

## [1.0.0] - 2026-09-29

First release. The library that ships inside the Cryptoway extensions for OpenCart, PrestaShop and
BILLmanager, packaged for use on its own, with the webhook checks and the money rules included.

### Added

- `ApiClient` — signed requests to the Cryptoway API: `createInvoice()`, `getInvoice()`,
  `getProfile()`. Failures come back as `ApiError` values rather than exceptions. `GET` is retried
  twice after a network error or a 5xx; `POST /invoice/` is repeated only to drop the
  `redirect_url` field after a 400 or 422 that carried it, and never otherwise (a 500 may come after
  the invoice was created). Debug logging masks secrets and the account's personal data in bodies,
  headers and URL query strings. Without a `user_agent` the client sends `CryptowayPHP`.
- `Signature` and `CanonicalJson` — HMAC-SHA256 over `timestamp . canonical_json(data)`, with the
  canonical form byte-compatible with `json.dumps(data, separators=(",", ":"), sort_keys=True)`.
  `serialize_precision` is pinned to `-1` for the encode and restored afterwards.
- `Webhook` and `WebhookResult` — authentication of an incoming notification: a 300 s freshness
  window, the signature (both the flat body and the `{params, body}` envelope), a delivery
  fingerprint for the caller's replay store, and the HTTP status to answer with.
- `Amounts` — the money rules: digit-by-digit decimal comparison with no tolerance that could hide
  a shortfall, overpayment detection above wallet rounding and a 1% threshold, and a check of the
  invoice against the order total that treats project markup and buyer fees as expected.
- `Coins` — splitting `USDTBEP20` into a coin and a readable network name.
- `ApiError` — code, explained message and HTTP status of a failed call.

### Notes

- `OrderManager`, the class the shop extensions use to apply invoice events to an order, is not
  part of this package: it depends on a shop's meta model, status ids and translator. The rules it
  encodes are described in the README.

[1.1.0]: https://github.com/cryptowayofficial/php-sdk/releases/tag/1.1.0
[1.0.0]: https://github.com/cryptowayofficial/php-sdk/releases/tag/1.0.0
