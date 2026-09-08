=== Manual Phone Orders ===
Contributors: iffe
Tags: woocommerce, phone orders, manual orders, cart
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: 
License URI: 

Fast AJAX phone/manual order entry for WooCommerce with an isolated cart session per customer.

== Description ==

Manual Phone Orders is an operator console for taking phone and manual orders.

* No page reloads — customer search, product search, cart, shipping, and checkout are all REST/AJAX.
* Isolated cart per customer (and per operator). Never reuses the admin storefront session.
* Quantity edits are debounced and sent as a single batch, so totals calculate once.
* Uses the real WooCommerce cart so YITH Dynamic Pricing, Account Funds, shipping methods, coupons, and most other cart plugins apply as they would on checkout.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate Manual Phone Orders.
3. Open **Phone Orders** in the WordPress admin menu.

== Changelog ==

= 1.0.0 =
* Initial release.
