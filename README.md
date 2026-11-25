# ElyonPay for WooCommerce

Accept **Mobile Money** (MTN, Orange, Wave, Moov, Airtel) and **bank cards**
(Visa, Mastercard) in your WooCommerce store with [ElyonPay](https://elyonpay.com).

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Built on the [ElyonPay PHP SDK](https://github.com/ElyonPay-org/php-sdk).
Uses the payment-link flow with **webhooks** and **server-side status
verification**, authenticates with the merchant **API key**, and supports both
the **classic** and **block-based** checkout.

## Requirements

- WordPress 5.6+, WooCommerce 4.0+
- PHP 7.4+ with the cURL extension
- An ElyonPay merchant account

## Build & install

The repository does not ship `vendor/`. Because the SDK is not on Packagist yet,
`composer.json` references it directly from GitHub, so a normal install works:

```bash
composer install --no-dev --optimize-autoloader
zip -r elyonpay-for-woocommerce.zip . -x ".git/*" ".github/*"
```

Then in WordPress: **Plugins -> Add New -> Upload Plugin**, upload the zip, activate.

## Configure

**WooCommerce -> Settings -> Payments -> ElyonPay -> Manage.**

- **Mode test** — sandbox (`api.elyonpay.net`) vs production (`api.elyonpay.org`).
- **Clé d'API** — the merchant API key from the ElyonPay dashboard
  (`pk_live_egt_...`), one for the sandbox account, one for production. It only
  reaches the payment and transaction endpoints.
- **Ancienne connexion** — merchant email + password + role, used only when the
  key of the selected mode is empty. Deprecated: an admin notice asks to switch.

## How payments flow

1. `process_payment` creates a payment link with `webhook`
   (`?wc-api=elyonpay_webhook&order_id=…&order_key=…`), an `external_id`
   (`wc-<order number>-<random>`, unique per attempt as ElyonPay requires) and the
   `customer` block (name, e-mail). The link's uuid is stored on the order; every
   attempt's uuid is kept (`_elyonpay_transaction_ids`).
2. The customer pays on ElyonPay's page (USSD or 3D Secure).
3. ElyonPay POSTs a notification at each state change. The body is **unsigned**,
   so the endpoint only accepts a uuid stored for that order, then reads
   `GET /transactions/{uuid}` with the API key (7 s timeout, within ElyonPay's
   10 s) and updates the order. It answers 503 when the API is unreachable or
   another check holds the order, so ElyonPay retries; 200 otherwise.
4. The customer's return (`elyonpay_success` / `elyonpay_error`) does the same
   read. A WP-Cron re-check runs 10 min, 1 h, 6 h and 24 h later while the order
   is unpaid — the safety net when webhooks cannot reach the site.

A per-order lock (`INSERT IGNORE` on `wp_options`) keeps the three paths from
completing an order twice.

Order states: `pending` (awaiting payment) -> `processing/completed` (paid),
`failed` (latest attempt declined or cancelled), or `on-hold` (paid for less
than the order total — check before shipping).

## Status mapping

Statuses are normalised by the SDK's `TransactionStatus` class — the single
source of truth shared by all ElyonPay plugins:

| Outcome | ElyonPay states |
|---------|-----------------|
| Paid    | `PENDING` (money collected, awaiting delivery), `WAITING_FOR_DELIVERY`, `ACCEPTED`, `PREPARATION`, `DELIVERING`, `DELIVERED` |
| Failed  | `REJECTED`, `DECLINED`, `CANCELLED` |
| Pending | `CREATED`, `WAITING_FOR_PAYMENT`, unknown |

## Blocks checkout

The block integration lives in `includes/class-wc-elyonpay-blocks-support.php`
and `assets/js/elyonpay-blocks.js`. Titles/descriptions are decoded with
`decodeEntities` so French labels render correctly. If you edit the JS, bump the
`version` in `assets/js/elyonpay-blocks.asset.php` (keep it aligned with the
plugin version) so browsers pick up the change.

## Security

The API key is stored in the gateway settings and used only server-side. In the
legacy mode, the JWT obtained with the email + password is cached in a transient
(TTL derived from the token's `exp` claim). Nothing is exposed to the browser.

## Tests

```bash
php tests/run.php
```

Runs the gateway against WordPress/WooCommerce stubs and a fake ElyonPay API
(webhook, forged uuid, retries, amount check, legacy login). Point
`ELYONPAY_SDK_SRC` at a checkout of the SDK if `vendor/` is not installed.

## Testing (sandbox)

| Test number | Network | Behaviour |
|-------------|---------|-----------|
| `+237600000001` | MTN MoMo | Success |
| `+237690000001` | Orange Money | Success |

Test card: `4242 4242 4242 4242`, exp `12/28`, CVV `123`.

## License

[MIT](LICENSE)
