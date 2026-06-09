=== ElyonPay for WooCommerce ===
Contributors: elyonpay
Tags: mobile money, payment gateway, mtn momo, orange money, africa
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept Mobile Money (MTN, Orange, Wave, Moov, Airtel) and bank cards on your WooCommerce store, across Africa.

== Description ==

**ElyonPay for WooCommerce** connects your store to the **ElyonPay** payment
gateway so you can collect payments in a few clicks across Cameroon, West and
Central Africa, and beyond — through **Mobile Money** as well as **bank cards**.

Your customers pay on a secure page hosted by ElyonPay (USSD for Mobile Money,
3D Secure for cards), then return to your store. Each order status is confirmed
only after a **server-side verification**: no payment is ever marked as paid on
the basis of a redirect alone.

= Supported payment methods =

* **Mobile Money**: MTN MoMo, Orange Money, Wave, Moov Money, Airtel Money
* **Bank cards**: Visa and Mastercard (3D Secure)

= Why ElyonPay =

* **Built for Africa** — local operators and currencies (XAF, XOF, EUR, USD,
  GBP, NGN, KES, CDF).
* **Classic and block-based checkout** — works with both the WooCommerce block
  checkout and the legacy checkout.
* **Reliable payments** — server-side status verification after every
  transaction, plus an automatic re-check of pending payments.
* **No sensitive data on your site** — card details are entered on ElyonPay's
  secure page, never on your store.
* **HPOS compatible** — ready for WooCommerce High-Performance Order Storage.
* **Built-in test mode** — a full sandbox environment to validate everything
  before going live.

= How it works =

1. The customer completes the cart and selects ElyonPay.
2. They are redirected to the ElyonPay payment page (Mobile Money or card).
3. After paying, they are redirected back to your store.
4. The plugin queries the ElyonPay API and updates the order according to the
   real status: paid, failed, or pending.

= Account required =

This plugin requires an ElyonPay merchant account. Account creation, onboarding
(KYB) and credentials are handled on [elyonpay.com](https://elyonpay.com). The
technical API documentation is available at
[docs.elyonpay.com](https://docs.elyonpay.com).

This plugin connects to a third-party service (the ElyonPay API) to create
payment links and verify transaction statuses. By using it you agree to
ElyonPay's terms of service and privacy policy, available on elyonpay.com.

== Installation ==

= Simple installation =

1. Download the `elyonpay-for-woocommerce.zip` archive.
2. In the WordPress admin: **Plugins -> Add New -> Upload Plugin**, select the
   archive and click **Install Now**.
3. Activate the plugin.
4. Go to **WooCommerce -> Settings -> Payments**, enable **ElyonPay**, then
   click **Manage**.
5. Choose the environment (test or production), enter your ElyonPay merchant
   credentials, then save.

= Configuration =

* **Test mode**: uses the ElyonPay sandbox environment (no real transactions).
  Uncheck it to collect live payments.
* **Credentials**: your ElyonPay merchant email and password (separate fields
  for test and production).
* **Role**: `ROLE_MERCHANT_ADMIN` in production. The test account documented by
  ElyonPay uses `ROLE_CUSTOMER`; if sandbox authentication fails, switch the
  role to `ROLE_CUSTOMER`.

= Requirements =

* WordPress 5.6 or higher
* WooCommerce 4.0 or higher
* PHP 7.4 or higher with the cURL extension
* An ElyonPay merchant account

== Frequently Asked Questions ==

= Is the plugin free? =

Yes, the plugin is free and open source (MIT license). Any transaction fees are
governed by your ElyonPay merchant agreement.

= Do I need an ElyonPay account? =

Yes. You need an approved ElyonPay merchant account to obtain your credentials.
Visit elyonpay.com to create one.

= Which countries and currencies are supported? =

ElyonPay targets Cameroon, West and Central Africa, and supports the XAF, XOF,
EUR, USD, GBP, NGN, KES and CDF currencies. Mobile Money operator availability
depends on the country.

= Does the plugin work with the block checkout? =

Yes. ElyonPay appears in both the classic checkout and the newer WooCommerce
block checkout.

= Is a phone number required? =

Yes for Mobile Money. The plugin automatically formats the number to
international format based on the order's billing country.

= How are payments secured? =

Payment details are entered on ElyonPay's secure page, not on your store. After
the redirect, the plugin confirms the payment by querying the ElyonPay API: an
order is validated only if the payment is actually confirmed server-side.

= What happens if the customer closes the page before finishing? =

The order stays "pending". The plugin automatically reschedules a status check,
and you can also check it from the order screen.

= Are my credentials safe? =

They are stored in the gateway settings and used only server-side to obtain an
access token (JWT), cached temporarily. They are never exposed in the browser.

== Screenshots ==

1. The ElyonPay settings page in WooCommerce (environment, credentials, role).
2. ElyonPay offered as a payment method in the classic checkout.
3. The ElyonPay payment method in the block checkout, with the test-mode notice.
4. The secure ElyonPay payment page (Mobile Money and card).
5. The transaction status reference table in the admin.

== Changelog ==

= 1.2.0 =
* Rebuilt on the official ElyonPay PHP SDK (shared status logic).
* Fixed international phone-number formatting (country dialing code).
* Stock reduction and cart emptying now happen only after the payment is confirmed.
* Order-key verification on return, HPOS compatibility, PHP 7.4 compatibility.
* Block checkout: HTML entity decoding for accented titles; asset version aligned.

= 1.1.1 =
* Previous standalone release (direct API calls).

== Upgrade Notice ==

= 1.2.0 =
Recommended update: fixes payment validation (statuses) and phone-number formatting. Remember to run "composer install" when building the archive.
