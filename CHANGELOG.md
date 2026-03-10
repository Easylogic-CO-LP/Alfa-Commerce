# Changelog

All notable changes to Alfa Commerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Security
- Escape all user-facing template output to prevent XSS vulnerabilities
- Add CSRF token verification to `clearCart` controller action
- Remove `continue-on-error` from PHPStan security workflow step
- Escape CSS color values in admin templates to prevent CSS injection

### Fixed
- Fix `$errorOccured` typo to `$errorOccurred` throughout CartController
- Restore error handling (try-catch) in `addToCart` method
- Remove debug `print_r` statements from admin payments template

### Added
- Foreign key constraints for all database relationships (migration 1.0.3)
- PHPUnit test infrastructure (`phpunit.xml.dist`, bootstrap, example test)
- CHANGELOG.md for tracking project changes
- GitHub Actions workflow for asset minification
- Extended `.gitignore` with IDE files, build artifacts, environment files, and logs

## [1.0.2] - 2024-12-01

### Changed
- Rewrite README and update Postman collection link

## [1.0.1] - 2024-11-01

### Added
- Initial pre-alpha release
- Product catalog (items, categories, manufacturers)
- Cart and multi-step checkout
- Order management with status tracking
- Payment plugins (Standard, Revolut, Viva Wallet)
- Shipment plugins (Standard, BoxNow)
- Pricing engine with taxes, discounts, coupons
- Multi-currency support
- REST API with 18 JSON-API endpoints
- Admin panel with full CRUD for all entities
- Custom form fields plugin system
- SEF URL routing
- Code quality and security CI workflows
