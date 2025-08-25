# Gravity Forms Braintree (Custom)

Production-ready Braintree payment integration for Gravity Forms using Hosted Fields.  
Supports one-time transactions and subscriptions with plan caching, vaulting (optional), detailed logging, and security hardening.

## Compatibility Targets
- PHP 8.3.24
- WordPress 6.8.2
- Gravity Forms 2.9.16
- Braintree PHP SDK 6.28.0 (manually placed under `includes/lib/`)
- Braintree JS SDK 3.127.0 (loaded via CDN)

## Features
- Hosted Fields card entry (PCI SAQ-A approach)
- One-time payments and subscription creation
- Static or dynamic plan selection (admin UI)
- Plan caching (default 30 minutes, configurable via constant)
- Optional customer vault (email -> customer ID map)
- Detailed entry notes (optional)
- Logging with Gravity Forms Logging UI integration
- Secure AJAX endpoints with nonces & capability checks
- Admin notices for missing SDK or configuration
- Cron scaffold for housekeeping tasks

## Requirements
- PHP 8.1+ (tested up to 8.3.24)
- Gravity Forms 2.9.16+
- Braintree PHP SDK present at `includes/lib/autoload.php` and loads `\Braintree\Gateway`
- Braintree merchant credentials

## Installation
1. Copy plugin folder to `wp-content/plugins/gravity-forms-braintree/`.
2. Download Braintree PHP SDK (v6.28.0) and place it under `gravity-forms-braintree/includes/lib/` ensuring an `autoload.php` exists there.
3. Activate plugin in WordPress.
4. Configure credentials under Forms > Settings > Braintree.
5. Create a Braintree feed for your form (select transaction type and plan options).
6. Embed the form; Hosted Fields will render when the feed is active.

## Hosted Fields Markup
You must include a container with the appropriate selectors (e.g. via an HTML field or a custom hook). Example:

```html
<div class="gf-braintree-hosted-fields" data-form-id="{form_id}">
  <div class="gf-braintree-row bt-field-wrapper">
    <label>Card Number</label>
    <div class="bt-number bt-input"></div>
  </div>
  <div class="gf-braintree-row bt-field-wrapper">
    <label>Expiration</label>
    <div class="bt-exp bt-input"></div>
  </div>
  <div class="gf-braintree-row bt-field-wrapper">
    <label>CVV</label>
    <div class="bt-cvv bt-input"></div>
  </div>
  <div class="gf-braintree-row bt-field-wrapper optional-postal">
    <label>Postal Code</label>
    <div class="bt-postal bt-input"></div>
  </div>
</div>
```

(Postal block only if enabled in settings.)

## Configuration Fields
- Environment: Sandbox / Production
- Merchant ID / Public Key / Private Key
- Merchant Account ID (optional)
- Enable Vault (stores customer for re-use via email map)
- Enable Postal Code Hosted Field
- Verbose Entry Notes
- Enable Logging

## Hooks
- `gf_braintree_transaction_params` ( array $params, $feed, $form, $entry )
- `gf_braintree_subscription_params` ( array $params, $feed, $form, $entry )
- `gf_braintree_verbose_entry_note_lines` ( array $lines, $context )
- `gf_braintree_after_successful_transaction` ( $entry, $feed, $form )

## Caching
- Plans cached for `GF_BRAINTREE_PLAN_CACHE_TTL` seconds (default 1800).
- Manual refresh button in feed settings.

## Security
- Client tokens fetched via AJAX endpoint with nonce.
- Admin plan fetch endpoint requires `manage_options` + nonce.
- Sensitive keys stored in WP options (standard). Restrict admin access.

## Logging
Enable under settings and view logs at Forms > Settings > Logging.

## Housekeeping (Cron)
Daily hook `gf_braintree_daily_housekeeping` available for future maintenance tasks.

## Troubleshooting
- SDK notice: Ensure `includes/lib/autoload.php` is present and loads Braintree classes.
- No Hosted Fields: Confirm active feed, proper markup, and no JavaScript console errors.
- Plan list empty: Confirm credentials and that subscription plans exist in Braintree dashboard.
- Payment failures: Enable logging, review GF logs.

## Minimal Autoload Wrapper Example
```php
<?php
// File: includes/lib/autoload.php
require_once __DIR__ . '/Braintree.php'; // or vendor-style structure if provided.
```

## Roadmap (Optional Enhancements)
- Webhook verification & subscription status sync
- Refund / void UI actions
- 3D Secure integration (if required)
- Advanced fraud data collection (device data)

## License
Proprietary / Custom (adjust per project needs).