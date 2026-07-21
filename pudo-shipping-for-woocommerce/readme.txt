=== The Courier Guy Locker Shipping for WooCommerce ===
Tags: ecommerce, e-commerce, woocommerce, shipping, courier
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.7.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

This is the official WooCommerce extension to ship products using The Courier Guy Locker.

== Description ==

The Courier Guy Locker extension for WooCommerce enables you to ship products using The Courier Guy Locker.

= Why choose The Courier Guy Locker? =

The Courier Guy Locker has built a strong reputation through strong customer relations and effective personal service. Today The Courier Guy is trusted, recognised and the fastest growing courier company in South Africa.

== Features ==

* WooCommerce shipping integration
* Real-time shipping rates
* Shipment creation from order admin
* PUDO locker delivery support

== Installation ==

1. Upload plugin to `/wp-content/plugins/`
2. Activate plugin
3. Configure shipping settings

== Frequently Asked Questions ==

= Do I need a Courier Guy account? =
Yes.

== Screenshots ==

1. Shipping settings
2. Checkout option

== Changelog ==

= 1.7.0 - April 29, 2026
* Added ABSPATH constant checks to all plugin files for security hardening.
* Added nonce verification for admin form submissions.
* Added capability checks for post editing operations.
* Added translators' comments for better localisation support.
* Implemented proper input sanitization using `wp_unslash()` and `sanitize_text_field()`.
* Added sanitisation callbacks to `register_setting()` functions.
* Implemented proper output escaping using `esc_html()`, `esc_attr()`, and `esc_textarea()`.
* Replaced custom directory creation with `wp_mkdir_p()` for safer filesystem operations.
* Enhanced nonce verification in save post operations.
* Added permission checks before post updates.
* Fixed internationalisation by using the correct text domain (`pudo-shipping-for-woocommerce`) across all translatable strings.
* Fixed taxonomy label generation logic (inverted condition fix).
* Fixed unnecessary `__()` calls on non-dynamic strings.
* Fixed global variable naming to use plugin prefix (`pudo_custom_post_types`).
* Fixed form field template output structure and escaping.
* Fixed form field checkbox and radio implementations.
* Fixed undefined variable warnings in post-save operations.
* Fixed post type validation in meta box callbacks.
* Improved code formatting and structure across all template files.
* Updated form field templates with proper HTML5 structure.
* Enhanced code comments with PHPCS ignore directives where needed.
* Improved form field wrapper markup.
* Updated plugin asset manifest formatting.
* Reworked Courier Shipping form
* Ensured compliance with WordPress.org plugin marketplace coding standards.
* Resolved PHPCS warnings for security, localisation, and coding standards.

= 1.6.0 - April 09, 2026
* Added support for WooCommerce Blocks.
* Fixed session handling issues that could cause conflicts between different shipping method types.
* Fixed rate calculation and storage for different PUDO shipping variants (L2D, L2L, D2L).
* Removed unused code related to generic waybills.
* Updated shipping rate management to prevent conflicts between traditional and block-based checkouts.

= 1.5.3 - January 13, 2026
* Fixed compatibility issues between TCG Locker and TCG plugins when both are active.
* Improved session handling for chosen shipping methods and rates to prevent conflicts.
* Enhanced checkout behaviour by properly managing shipping method selection state.
* Replaced deprecated `FILTER_SANITIZE_STRING` with `htmlspecialchars()` for better security and PHP 8.x compatibility.
* Improved input sanitisation for locker names and shipping method data.
