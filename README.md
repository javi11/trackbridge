# TrackBridge for WooCommerce

Add shipment tracking from the official WooCommerce mobile app, by typing a tracking number into a plain order custom field.

[![CI](https://github.com/javi11/trackbridge/actions/workflows/ci.yml/badge.svg)](https://github.com/javi11/trackbridge/actions/workflows/ci.yml)

## The problem

The official WooCommerce mobile app cannot open plugin screens, so there is no way to add a tracking number to an order from your phone. Fulfilling an order means opening wp-admin on a desktop.

The app *can* create and edit order custom fields. TrackBridge turns that into a working fulfilment flow:

```
WooCommerce app                TrackBridge (server)
──────────────                 ────────────────────
open order
add custom field
  tracking_number = 1234567890
save  ──────────────────────▶  woocommerce_update_order
                               ├─ parse the value
                               ├─ write tracking via AST / Shipment Tracking
                               ├─ complete the order
                               ├─ email the customer
                               └─ clear the field
```

## Supported tracking plugins

| Plugin | Requirement | Notes |
| --- | --- | --- |
| [Advanced Shipment Tracking](https://wordpress.org/plugins/woo-advanced-shipment-tracking/) | Free version is enough | `WC_Advanced_Shipment_Tracking_Actions::get_instance()->add_tracking_item()`, with `ast_add_tracking_number()` as a fallback |
| WooCommerce Shipment Tracking | The official extension | Uses `wc_st_add_tracking_number()` |

> **Note:** AST's API is *not* on the object returned by `wc_advanced_shipment_tracking()` — that is the main plugin class and has no tracking methods. This tripped up v1.0.0; the integration suite now runs against the real plugin so it cannot recur.
>
> AST also downloads its carrier list from `api.trackship.com` through a background job, so the list can be empty for a short while after activation. TrackBridge falls back to a free-text carrier field when that happens.

Whichever is active is detected automatically. Adding another tracking plugin means writing one class against `Trackbridge_Provider` and registering it through the `trackbridge_providers` filter.

## Install

Download `trackbridge.zip` from the [latest release](https://github.com/javi11/trackbridge/releases/latest) and install it through **Plugins > Add New > Upload Plugin**.

Then open **WooCommerce > Settings > Shipping > TrackBridge**, choose your carrier, and note the field name shown in the status panel.

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Custom field name | `tracking_number` | The field TrackBridge watches. Cannot start with `_`. |
| Tracking plugin | Automatic | Which plugin receives the number. |
| Carrier | GLS | Recorded against every tracking number. |
| Mark the order completed | On | Completes the order after tracking is added. |
| Email the customer | On | Sends the completed-order email, which your tracking plugin fills with tracking details. |
| Clear the field after syncing | On | Keeps orders tidy. The value is always kept when syncing fails. |
| Allow a carrier prefix | Off | Lets the value choose the carrier, e.g. `GLS:1234567890`. |
| Accept several parcels | On | Treats commas and semicolons as separators. |
| Log every sync | Off | Records successes too. Failures are always recorded. |

## Why the field name cannot start with an underscore

WordPress treats underscore-prefixed meta as protected, and both WooCommerce mobile apps enforce that convention:

- Android rejects such keys in the editor — [`CustomFieldsEditorViewModel.kt`](https://github.com/woocommerce/woocommerce-android/blob/trunk/WooCommerce/src/main/kotlin/com/woocommerce/android/ui/customfields/editor/CustomFieldsEditorViewModel.kt) (`key.startsWith("_")`)
- iOS rejects them in `CustomFieldEditorViewModel.swift` and filters them out of the list in `Order.swift`

So a field named `_tracking_number` would be invisible and uneditable on a phone. TrackBridge defaults to `tracking_number` and rejects underscore-prefixed names in settings, explaining why.

## Behaviour worth knowing

- **Repeated saves are safe.** Tracking numbers already on the order are skipped, and the customer is not emailed again.
- **Failures never lose data.** If the tracking plugin rejects a write, the field is left in place and the order is not completed. The reason lands in the order notes and in **WooCommerce > Status > Logs**.
- **Completion and email are independent.** `update_status( 'completed' )` is a no-op on an already-completed order and sends no email, so a late tracking number on a completed order triggers the email directly instead.
- **Both storage engines are supported.** The single `woocommerce_update_order` hook fires under High-Performance Order Storage and the legacy post tables alike; the integration suite runs against both.

## Development

Requires PHP and Composer, plus Docker for the integration suite.

```bash
composer install
```

```bash
composer phpcs
```

```bash
composer test
```

Integration tests run against a real WordPress and WooCommerce install via [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
npx wp-env start
```

```bash
npx wp-env run tests-cli --env-cwd="wp-content/plugins/$(basename "$PWD")" bash -c "vendor/bin/phpunit -c phpunit.integration.xml.dist"
```

Set `TRACKBRIDGE_HPOS=1` in that command to run the same suite against High-Performance Order Storage. CI runs it both ways.

The integration environment installs WooCommerce **and** Advanced Shipment Tracking, so the AST adapter is tested against the real plugin rather than a double.

The plugin follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) rather than PSR-12, since it is a WordPress plugin; `phpcs.xml.dist` enforces this along with PHP 7.4+ compatibility.

## Releasing

Bump the version in three places — the `Version:` plugin header, the `TRACKBRIDGE_VERSION` constant, and `Stable tag:` in `readme.txt` — then push a tag:

```bash
git tag v1.0.1 && git push origin v1.0.1
```

The release workflow refuses to publish if those three disagree with the tag, then builds `trackbridge.zip` and attaches it to a GitHub release.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
