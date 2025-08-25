# Gravity Forms Braintree

Custom Braintree gateway integration for Gravity Forms.

## Requirements
- PHP 8.1+
- Gravity Forms >= 2.9
- Braintree PHP SDK present at `includes/autoload.php` (or wrapper to a vendor directory) and must load class `\Braintree\Gateway`.

## Installation
1. Upload plugin to `wp-content/plugins/`.
2. Place Braintree SDK autoloader at `includes/autoload.php`.
3. Activate plugin.
4. Configure credentials under the plugin settings.
5. Create feed, choose Subscription to auto-load plans.

## Development Notes
- Plan cache TTL: 30 minutes (constant `GF_BRAINTREE_PLAN_CACHE_TTL`).
- Verbose entry notes optionally enabled.
- Traits split by responsibility.

## Minimal Autoload Wrapper Example
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
```

## Logging
Enable logging in settings and Gravity Forms logging UI.

## Hooks
- `gf_braintree_transaction_params`
- `gf_braintree_subscription_params`
- `gf_braintree_verbose_entry_note_lines`
- `gf_braintree_after_successful_transaction`

## Troubleshooting
If you see the admin notice about missing SDK, ensure:
- File exists: `includes/autoload.php`
- After inclusion `class_exists('\Braintree\Gateway')` is true.