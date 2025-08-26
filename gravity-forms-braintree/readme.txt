=== Gravity Forms Braintree Gateway ===
Contributors: (you)
Tags: gravityforms, braintree, payments, subscriptions
Requires at least: 6.0
Tested up to: 6.8.2
Requires PHP: 8.1
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Braintree payment gateway integration for Gravity Forms. Supports one-time payments, subscriptions (dynamic or static plan selection), webhooks, partial/full refunds, Hosted Fields, device data (fraud) collection, vaulting, and detailed logging.

== Description ==
Implements an integration with Braintree using:
* Braintree PHP SDK 6.28.0 (manually bundled / autoload you supply at includes/lib/autoload.php)
* Braintree JS SDK 3.127.0 (Client, Hosted Fields, Data Collector)
* Gravity Forms Payment Add-On Framework (2.7+)

== Features ==
* Hosted Fields (card number, expiration, CVV, optional postal code)
* Automatic injection of payment UI (override via filter gf_braintree_default_markup)
* Device data collection (Kount) optional
* Customer & billing field mapping
* One-time transactions
* Subscriptions:
  * Static plan selection (remote plan list + refresh)
  * Plan ID from form field
  * Webhook-driven lifecycle updates (activate, cancel, expire, recurring payments, failures)
* Vault support (stores customer by email if enabled)
* Partial or full refunds (entry detail UI)
* Plan cache with manual refresh
* Conditional feed logic
* Verbose entry notes (optional)
* Logging (Gravity Forms Logging Add-On compatible)
* REST webhook endpoint (challenge verification + notifications)
* Security: nonces for AJAX, sanitized input, minimal stored card data (only tokens/IDs)
* Extensible via actions & filters

== Installation ==
1. Upload folder to /wp-content/plugins/gravity-forms-braintree/
2. Place Braintree PHP SDK autoload at includes/lib/autoload.php (ensuring \Braintree\Gateway class exists).
3. Activate plugin.
4. Enter credentials under Forms > Settings > Braintree.
5. Create a Braintree Feed for your form.

== Webhook ==
Add this URL in Braintree Control Panel:
`https://example.com/wp-json/gf-braintree/v1/webhook`
Enable subscription events.

== Refunds ==
Open an entry and use the Refund button. Enter amount for partial refund (Braintree must allow partial for status).

== Filters ==
* gf_braintree_transaction_params ( $params, $feed, $form, $entry )
* gf_braintree_default_markup ( $html, $form_id, $form )

== Changelog ==
= 1.1.1 =
* Production hardening, admin feed UI + localization, safer constant references, improved error handling, subscription resilience.

= 1.1.0 =
* Consolidated advanced subscription + plan features, vault & device data, partial refunds.

= 1.0.0 =
* Initial baseline (internal).

== License ==
GPLv2 or later.