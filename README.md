# ElyonPay for WooCommerce

Accept **Mobile Money** (MTN, Orange, Wave, Moov, Airtel) and **bank cards**
(Visa, Mastercard) in your WooCommerce store with [ElyonPay](https://elyonpay.com).

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Built on the [ElyonPay PHP SDK](https://github.com/elyonpay/elyonpay-php-sdk).
Uses the payment-link flow with **server-side status verification** (no webhook),
and supports both the **classic** and **block-based** checkout.

## Requirements

- WordPress 5.6+, WooCommerce 4.0+
- PHP 7.4+ with the cURL extension
- An ElyonPay merchant account

## Build & install

The repository does not ship `vendor/`. Because the SDK is not on Packagist yet,
`composer.json` references it directly from GitHub, so a normal install works:

```bash
composer install --no-dev --optimize-autoloader
zip -r elyonpay-woocommerce.zip . -x ".git/*" ".github/*"
```

Then in WordPress: **Plugins -> Add New -> Upload Plugin**, upload the zip, activate.

## Configure

**WooCommerce -> Settings -> Payments -> ElyonPay -> Manage.**

- **Mode test** — sandbox (`api.elyonpay.net`) vs production (`api.elyonpay.org`).
- **Identifiants** — merchant email + password (test and live are separate fields).
- **Rôle** — `ROLE_MERCHANT_ADMIN` in production. The documented sandbox test
  account (`test@test.com` / `TestPassword1`) is listed with `ROLE_CUSTOMER`; if
  sandbox authentication fails, switch the role to `ROLE_CUSTOMER`.

## How payments flow (pull model)

1. The customer places the order and is redirected to the ElyonPay payment page.
2. They pay with Mobile Money (USSD) or a card (3D Secure).
3. They are redirected back to the store.
4. The plugin calls `GET /transactions/{ref}` via the SDK and marks the order
   paid **only** if the status is confirmed server-side. A pending payment is
   re-checked automatically after 5 minutes (WP-Cron).

Order states: `pending` -> `processing/completed` (paid), `failed`, or `on-hold`
(pending confirmation). The redirect URL is never trusted on its own.

## Status mapping

Statuses are normalised by the SDK's `TransactionStatus` class — the single
source of truth shared by all ElyonPay plugins:

| Outcome | ElyonPay states |
|---------|-----------------|
| Paid    | `ACCEPTED`, `DELIVERED` |
| Failed  | `REJECTED`, `DECLINED`, `CANCELLED` |
| Pending | `CREATED`, `PENDING`, `WAITING_FOR_PAYMENT`, unknown |

## Blocks checkout

The block integration lives in `includes/class-wc-elyonpay-blocks-support.php`
and `assets/js/elyonpay-blocks.js`. Titles/descriptions are decoded with
`decodeEntities` so French labels render correctly. If you edit the JS, bump the
`version` in `assets/js/elyonpay-blocks.asset.php` (keep it aligned with the
plugin version) so browsers pick up the change.

## Security

Credentials are stored in the gateway settings and used only server-side to
obtain a JWT, which is cached in a transient (TTL derived from the token's `exp`
claim). Nothing is exposed to the browser. Once ElyonPay offers revocable API
keys, switch to those.

## Testing (sandbox)

| Test number | Network | Behaviour |
|-------------|---------|-----------|
| `+237600000001` | MTN MoMo | Success |
| `+237690000001` | Orange Money | Success |

Test card: `4242 4242 4242 4242`, exp `12/28`, CVV `123`.

## License

[MIT](LICENSE)
