# Changelog

## [1.7.0](https://github.com/The-Courier-Guy/Locker-WooCommerce/releases/tag/1.7.0)

### Added

- Added ABSPATH constant checks to all plugin files for security hardening.
- Added nonce verification for admin form submissions.
- Added capability checks for post editing operations.
- Added translators' comments for better localisation support.

### Security

- Implemented proper input sanitization using `wp_unslash()` and `sanitize_text_field()`.
- Added sanitisation callbacks to `register_setting()` functions.
- Implemented proper output escaping using `esc_html()`, `esc_attr()`, and `esc_textarea()`.
- Replaced custom directory creation with `wp_mkdir_p()` for safer filesystem operations.
- Enhanced nonce verification in save post operations.
- Added permission checks before post updates.

### Fixed

- Fixed internationalisation by using the correct text domain (`pudo-shipping-for-woocommerce`) across all translatable strings.
- Fixed taxonomy label generation logic (inverted condition fix).
- Fixed unnecessary `__()` calls on non-dynamic strings.
- Fixed global variable naming to use plugin prefix (`pudo_custom_post_types`).
- Fixed form field template output structure and escaping.
- Fixed form field checkbox and radio implementations.
- Fixed undefined variable warnings in post-save operations.
- Fixed post type validation in meta box callbacks.

### Changed

- Improved code formatting and structure across all template files.
- Updated form field templates with proper HTML5 structure.
- Enhanced code comments with PHPCS ignore directives where needed.
- Improved form field wrapper markup.
- Updated plugin asset manifest formatting.
- Reworked Courier Shipping form

### Compliance

- Ensured compliance with WordPress.org plugin marketplace coding standards.
- Resolved PHPCS warnings for security, localisation, and coding standards.

## [1.6.0]

### Added

- Added support for WooCommerce Blocks.

### Fixed

- Fixed session handling issues that could cause conflicts between different shipping method types.
- Fixed rate calculation and storage for different PUDO shipping variants (L2D, L2L, D2L).

### Changed

- Removed unused code related to generic waybills.
- Updated shipping rate management to prevent conflicts between traditional and block-based checkouts.

## [1.5.3]

### Fixed

- Fixed compatibility issues between TCG Locker and TCG plugins when both are active.
- Improved session handling for chosen shipping methods and rates to prevent conflicts.
- Enhanced checkout behaviour by properly managing shipping method selection state.

### Security

- Replaced deprecated `FILTER_SANITIZE_STRING` with `htmlspecialchars()` for better security and PHP 8.x compatibility.
- Improved input sanitisation for locker names and shipping method data.

## [1.5.2]

### Fixed

- Fixed duplicate shipping rates appearing at checkout by implementing a debounce mechanism to prevent rapid successive
  calls.
- Fixed broken html in admin settings page.
- Prevented duplicate action hooks from being registered for shipping rate options.
- Improved product meta box initialisation by wrapping in WordPress 'init' action to ensure proper loading order.
- Prevented memory leaks where multiple instances of the PudoApi class were created.

### Added

- Added functionality to clear override settings.

## [1.5.1]

### Fixed

- Removed the unnecessary "pack as a single parcel" product functionality to prevent errors during parcel packing.
- Trimmed leading/trailing whitespace from API Key and URL fields to prevent errors and multiple rates at checkout.

### Changed

- Update Branding from "The Courier Guy Lockers" to "The Courier Guy Locker".

## [1.5.0]

### Added

- Introduce new create-shipment screen.
- Add map locker search by area.

### Changed

- Update Branding from "PUDO" to "The Courier Guy Lockers".

## [1.4.2]

### Fixed

- Fixed free shipping error where shipping options displayed as "Free" but still charged shipping costs when cart total
  exceeded free shipping threshold.

## [1.4.1]

### Fixed

- Fixed an issue where an incorrect PUDO API URL caused the plugin to fail to load.

## [1.4.0]

### Added

- Add Ability to override Label and Pricing.
- Upgrade Pudo Common library.

## [1.3.0]

### Added

- Print label feature.

## [1.2.0]

### Added

- Support for Door to Door Requests.

### Changed

- Switched to new PUDO locker rates endpoints.

### Fixed

- Order action compatibility with TCG plugin.

### Tested

- Tested with WooCommerce 9.3.3 and WordPress 6.6.2.

## [1.1.0]

### Added

- Dynamic rates at checkout.
- New PUDO API endpoints.

### Changed

- **BREAKING CHANGES**: Introduced new PUDO API; be sure to configure this.
- PUDO waybill changes.

### Fixed

- Bug fixes and improvements.

### Tested

- Tested with WooCommerce 9.1.3 and WordPress 6.6.1.

## [1.0.2]

### Changed

- Updated Locker to Locker pricing.
- Disabled locker-to-locker special option.

## [1.0.1]

### Fixed

- Bug fixes and improvements.

### Changed

- DomPDF upgrade.
- HPOS compatibility.

### Tested

- Tested with WooCommerce 8.7.0 and WordPress 6.5.3.

## [1.0.0]

### Added

- Initial release.
