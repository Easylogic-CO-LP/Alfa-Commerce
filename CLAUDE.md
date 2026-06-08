# Alfa Commerce - CLAUDE.md

## Project Overview

Alfa Commerce (`com_alfa`) is a full-featured eCommerce component for Joomla 6/7, built by Easylogic CO LP. It is distributed as a Joomla package containing a core component, plugins, and modules.

## Architecture

### Extension Type
Joomla component (type="component", method="upgrade") with bundled plugins and modules.

### Namespace
- Component: `Alfa\Component\Alfa\{Administrator|Site|Api}`
- Plugins: `Joomla\Plugin\{AlfaPayments|AlfaShipments|AlfaFields}\{PluginName}`
- Modules: `Alfa\Module\{ModuleName}`

### Directory Structure
```
/administrator/     - Backend admin interface (MVC, forms, SQL, services, events)
/site/              - Frontend customer-facing views and logic
/api/               - REST JSON-API controllers (18 endpoints)
/plugins/           - payment, shipment, field (tel/text/textarea/choice), webservices, system
/modules/           - mod_alfa_cart, mod_alfa_search
/media/com_alfa/    - CSS, JS, images, joomla.asset.json
/alfa.xml           - Package manifest
/script.php         - Install/update/uninstall script
```

### Key Patterns
- **MVC:** `FormController` / `AdminModel` / `HtmlView` (admin); `ComponentDispatcher` (site)
- **Events:** Custom event classes in `/administrator/src/Event/` for payment, shipment, form field, and order lifecycle hooks
- **DI:** Service container via `administrator/services/provider.php`
- **Database:** Joomla QueryBuilder; schema in `/administrator/sql/`; migrations in `/administrator/sql/updates/`
- **Assets:** Registered via `joomla.asset.json`; managed with `$this->getWebAssetManager()`
- **Forms:** XML-based definitions in `/forms/` directories with custom field plugins
- **Languages:** en-GB; `.ini` and `.sys.ini` files

## Coding Conventions
- Follow Joomla coding standards (PSR-4 autoloading, traits, DI)
- Controllers: `{Name}Controller` — Models: `{Name}Model` — Views: `{Name}\HtmlView`
- Events: `{Context}{Action}Event`
- Use Joomla's built-in APIs (FormFactory, MVCFactory, RouterService, CategoryService)
- PHP with no external Composer dependencies

## Database
- MySQL (utf8mb4); all tables prefixed `#__alfa_`
- Price index table (`#__alfa_items_price_index`) synced on item save/publish/delete
- Key tables: cart, cart_items, categories, items, orders, coupons, currencies, taxes, discounts, payments, shipments, manufacturers, places, users, usergroups

## Build & Deployment
- No build step required — install directly as a ZIP via Joomla Extension Manager
- Update server: `https://cdn.alfacommerce.gr/com_alfa/update.xml` (generated; signed integrity checksums alongside)
- `script.php` handles automatic plugin/module installation on component install

## Testing
- No automated test suite yet; CI runs PHP CS Fixer + PHPStan + CodeQL/security scans on PRs

## Common Tasks
- **Add a new admin view:** Create Controller, Model, View, Table, form XML under `/administrator/`, add menu entry in `alfa.xml`
- **Add a payment gateway:** Create a new plugin under `/plugins/alfa-payments/`, implement payment event handlers
- **Add a shipping method:** Create a new plugin under `/plugins/alfa-shipments/`, implement shipment event handlers
- **Modify pricing logic:** See `PriceIndexSyncService` (admin) and `Pricing` service (site)
- **Add API endpoint:** Create controller in `/api/src/Controller/`, view in `/api/src/View/`
