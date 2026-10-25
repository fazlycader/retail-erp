=== MVRK Woo Connector ===
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Live "Available at our showrooms" on product pages and "Reserve to view in showroom" requests, from your MVRK+ ERP.

== Description ==

Your ERP is the source of truth for products, prices and stock, and pushes them to WooCommerce itself (REST API). Web orders reach the ERP through WooCommerce webhooks. This plugin only adds the storefront parts:

* **Available at our showrooms**: per-showroom availability of the product, or of the chosen variation, fetched live after the page loads (page caches can't show stale stock; answers are cached 5 minutes).
* **Reserve to view in showroom** (optional): a short form that sends a visit request to the ERP.

No business data is stored in WordPress. Compatible with WooCommerce High-Performance Order Storage.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `mvrk-woo-connector-x.y.z.zip` (from the ERP's Online store page) → Install → Activate.
2. On the ERP server: `php artisan woo:storefront-token` and copy the token it prints.
3. WordPress → Settings → MVRK Connector: App address (https://…), paste the token, Save, then **Test connection**.
4. Availability appears under the Add to cart button. To place it yourself, untick the automatic option and use the block **Available at our showrooms** or the shortcode `[mvrk_showroom_availability]` (optionally `sku="…"`).

== Frequently Asked Questions ==

= The block says "Availability is not available right now" =
WordPress couldn't reach the ERP. Settings → MVRK Connector shows the last error; admins also see a notice.

= How do I remove it? =
Deactivate and delete: only the plugin's own settings are removed.

== Changelog ==

= 1.0.0 =
* First release.
