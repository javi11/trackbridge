=== TrackBridge for WooCommerce ===
Contributors: javi11
Tags: woocommerce, shipment tracking, mobile app, order tracking, gls
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add shipment tracking from the official WooCommerce mobile app by typing a tracking number into a plain order custom field.

== Description ==

The official WooCommerce mobile app cannot open plugin screens, so there is no way to add a tracking number to an order from your phone. It *can* edit order custom fields — and TrackBridge turns that into a working fulfilment flow.

Open an order in the app, add a custom field called `tracking_number`, type the number, and save. TrackBridge notices the value on the server, hands it to your shipment tracking plugin with the carrier you configured, optionally completes the order and emails the customer, then clears the field so it is ready for the next shipment.

= Supported tracking plugins =

* **Advanced Shipment Tracking** by zorem — the free version is enough
* **WooCommerce Shipment Tracking** — the official extension

Whichever is active is detected automatically.

= What it does =

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
4. In the WooCommerce mobile app, open an order, add that custom field, and enter a tracking number.

== Frequently Asked Questions ==

= Do I need the paid version of Advanced Shipment Tracking? =

No. TrackBridge uses an API that the free plugin provides.

= My mobile app has no Custom Fields section =

Update the app. Custom field editing was added to the WooCommerce iOS and Android apps in 2025; older builds cannot enter the value.

= The customer was not emailed =

Check that the **Completed order** email is enabled in **WooCommerce > Settings > Emails**, and that the order has a billing email address. TrackBridge sends that email; it does not create its own.

= What happens if I save the order twice? =

Nothing bad. TrackBridge compares against the tracking numbers already on the order and skips anything that is already there, so the customer is not emailed again.

= Where do I look when something goes wrong? =

Failures are always written to the order notes and to **WooCommerce > Status > Logs**, and the typed value is kept on the order so nothing is lost.

== Changelog ==

= 1.0.0 =
* Initial release.
* Syncs a configurable order custom field into Advanced Shipment Tracking or WooCommerce Shipment Tracking.
* Optional order completion, customer email and field clean-up.
* Multi-parcel values, optional carrier prefixes and duplicate protection.
* Compatible with High-Performance Order Storage.
