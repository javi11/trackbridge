=== TrackBridge for WooCommerce ===
Contributors: javi11
Tags: woocommerce, shipment tracking, mobile app, order tracking, gls
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add shipment tracking from the official WooCommerce mobile app by typing a tracking number into a plain order custom field.

== Description ==

The official WooCommerce mobile app cannot open plugin screens, so there is no way to add a tracking number to an order from your phone. It *can* edit order custom fields — and TrackBridge turns that into a working fulfilment flow.

Open an order in the app, put a tracking number in the `tracking_number` custom field, and save. TrackBridge notices the value on the server, hands it to your shipment tracking plugin with the carrier you configured, optionally completes the order and emails the customer, then clears the field so it is ready for the next shipment.

By default the field is already waiting on every new order, empty, so shipping from your phone is just tapping in the number.

= Supported tracking plugins =

* **Advanced Shipment Tracking** by zorem — the free version is enough
* **WooCommerce Shipment Tracking** — the official extension

Whichever is active is detected automatically.

= What it does =

* Adds the field to every new order, empty and ready to fill in
* Watches one order custom field, whatever you choose to call it
* Writes the tracking number and carrier into your tracking plugin
* Optionally marks the order completed
* Optionally sends the completed-order email, which your tracking plugin fills with tracking details
* Optionally clears the field after syncing, and always keeps it when syncing fails
* Accepts several parcels in one value, such as `111111, 222222`
* Optionally lets the value pick the carrier, such as `GLS:1234567890`
* Never adds the same tracking number twice, so a repeated save is harmless
* Works with both High-Performance Order Storage and the legacy order tables

= Why the field name cannot start with an underscore =

WordPress treats underscore-prefixed meta as protected, and the WooCommerce mobile apps follow that convention: the custom field editor refuses such keys, and the custom fields list hides them. A field named `_tracking_number` would therefore be invisible and uneditable on your phone. TrackBridge uses `tracking_number` by default and rejects underscore-prefixed names in its settings.

== Installation ==

1. Install and activate Advanced Shipment Tracking or WooCommerce Shipment Tracking.
2. Upload and activate TrackBridge.
3. Go to **WooCommerce > Settings > Shipping > TrackBridge**, pick your carrier, and note the field name.
4. In the WooCommerce mobile app, open a new order and put a tracking number in that custom field.

Orders that already existed before you installed TrackBridge will not have the field; add it by hand in the app, or just place the next order.

== Frequently Asked Questions ==

= Do I need the paid version of Advanced Shipment Tracking? =

No. TrackBridge uses an API that the free plugin provides.

= My mobile app has no Custom Fields section =

Update the app. Custom field editing was added to the WooCommerce iOS and Android apps in 2025; older builds cannot enter the value.

= The customer was not emailed =

Check that the **Completed order** email is enabled in **WooCommerce > Settings > Emails**, and that the order has a billing email address. TrackBridge sends that email; it does not create its own.

= What happens if I save the order twice? =

Nothing bad. TrackBridge compares against the tracking numbers already on the order and skips anything that is already there, so the customer is not emailed again.

= The settings screen says no tracking plugin is active, but mine is =

Update to 1.0.1. Version 1.0.0 looked for Advanced Shipment Tracking's API on the wrong object and never detected it.

= The carrier dropdown is empty or shows a text box =

Advanced Shipment Tracking downloads its carrier list in the background after you activate it, so the list can be briefly empty on a new install. Wait a minute and reload the settings screen. TrackBridge still works meanwhile — it just asks you to type the carrier name instead of picking it.

= Where do I look when something goes wrong? =

Failures are always written to the order notes and to **WooCommerce > Status > Logs**, and the typed value is kept on the order so nothing is lost.

= The field is missing on an older order =

Only orders created after you installed TrackBridge get the field automatically. Add it by hand in the app for older ones — the app can create custom fields itself. You can also turn the automatic behaviour off in the settings if you would rather keep order meta clean.

== Changelog ==

= 1.1.0 =
* New - Add the tracking field to every new order, empty and ready to fill in, so shipping from your phone no longer means typing the field name. Can be switched off in the settings.
* Fix - Do not add a second tracking item when an order is saved again from a stale order object held elsewhere in the same request.

= 1.0.1 =
* Fix - Advanced Shipment Tracking was never detected, so every sync reported "No supported shipment tracking plugin is active". The adapter looked for `add_tracking_item()` on the object returned by `wc_advanced_shipment_tracking()`, but that is the main plugin class; the method lives on `WC_Advanced_Shipment_Tracking_Actions`.
* Fix - Always send a shipped date to Advanced Shipment Tracking, which calls `strtotime()` on it without checking that it was supplied.
* Improve - Read the carrier list through Advanced Shipment Tracking's own `get_providers()` accessor instead of querying its table directly.
* Tests - The integration suite now runs against the real Advanced Shipment Tracking plugin, which is what would have caught this before release.

= 1.0.0 =
* Initial release.
* Syncs a configurable order custom field into Advanced Shipment Tracking or WooCommerce Shipment Tracking.
* Optional order completion, customer email and field clean-up.
* Multi-parcel values, optional carrier prefixes and duplicate protection.
* Compatible with High-Performance Order Storage.
