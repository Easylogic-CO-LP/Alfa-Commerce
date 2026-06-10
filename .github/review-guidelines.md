# Alfa Commerce — PR Review

Review PRs for **com_alfa**, a Joomla 6/7 eCommerce component (PHP, no Composer deps), as a Joomla + com_alfa specialist.

**How to review** — inline, concise, severity-first: 🔴 breaks installs/data/security · 🟡 likely bug or convention · 🔵 minor. Never flag style (CS Fixer) or PHPStan findings. Raise only what you're sure of; acknowledge good work; if it's clean, say so.

## Project structure

Source layout (not the installed layout — `alfa.xml` `<files folder>` maps it on install):

```
Alfa-Commerce/
├── administrator/              # Backend admin panel
│   ├── src/
│   │   ├── Extension/          # Component bootstrap (AlfaComponent.php)
│   │   ├── Controller/         # Admin controllers (Form + List)
│   │   ├── Model/              # Admin models (CRUD, queries)
│   │   ├── View/               # Admin views (36+ view classes)
│   │   ├── Table/              # Database table classes
│   │   ├── Field/              # Custom form field types
│   │   ├── Helper/             # Business logic helpers
│   │   ├── Service/            # Services (PriceIndexSyncService)
│   │   ├── Event/              # Event classes (40+ events)
│   │   └── Plugin/             # Base plugin classes
│   ├── forms/                  # XML form definitions (39 forms)
│   ├── tmpl/                   # Admin HTML templates
│   ├── sql/                    # Database schemas & migrations
│   │   ├── install.mysql.utf8.sql
│   │   ├── uninstall.mysql.utf8.sql
│   │   └── updates/mysql/      # Version migration scripts (e.g. 1.0.9.sql)
│   ├── services/               # DI container (provider.php)
│   ├── layouts/                # Reusable template layouts
│   ├── languages/              # Localization (en-GB)
│   ├── config.xml              # Component configuration form
│   └── access.xml              # ACL permissions
│
├── site/                       # Frontend customer-facing
│   ├── src/
│   │   ├── Controller/         # Frontend controllers
│   │   ├── Model/              # Frontend models
│   │   ├── View/               # Frontend views
│   │   ├── Service/            # Pricing engine
│   │   │   └── Pricing/        # Money, PriceResult, PriceContext, etc.
│   │   ├── Helper/             # CartHelper, OrderPlaceHelper, PriceSettings
│   │   └── Dispatcher/         # Request dispatcher
│   ├── forms/                  # Frontend forms
│   ├── tmpl/                   # Frontend templates
│   └── languages/              # Frontend localization
│
├── api/                        # REST JSON-API
│   └── src/
│       ├── Controller/         # REST API controllers
│       └── View/               # JSON response views
│
├── plugins/                    # Core ships only the `standard` reference plugins;
│   │                           # real gateways/carriers are premium (distributed separately)
│   ├── alfa-payments/
│   │   └── standard/           # Offline payment (bank transfer / cash on delivery)
│   ├── alfa-shipments/
│   │   └── standard/           # Standard shipping (flat / zone rates)
│   ├── alfa-fields/            # Form field type plugins
│   │   ├── text/
│   │   ├── textarea/
│   │   ├── tel/
│   │   └── choice/
│   ├── webservices/alfa/       # API route registration
│   └── system/alfasync/        # Post-install integrity & per-language schema sync
│
├── modules/
│   ├── mod_alfa_cart/           # Shopping cart widget module
│   └── mod_alfa_search/         # Product search module
│
├── media/com_alfa/              # Static assets
│   ├── css/                     # Stylesheets (admin + site)
│   ├── js/                      # JavaScript (admin + site)
│   ├── images/                  # Component images
│   └── joomla.asset.json        # Joomla asset registry
│
├── alfa.xml                     # Package manifest
├── script.php                   # Install/update/uninstall script
├── .php-cs-fixer.php            # Code style configuration
├── phpstan.neon                 # Static analysis configuration
├── CONTRIBUTING.md              # Contribution guide
└── .github/workflows/           # CI/CD automation
```

### Namespaces (PSR-4, Joomla 6/7)

| Part | Namespace |
|------|-----------|
| Admin | `Alfa\Component\Alfa\Administrator\{Controller,Model,View,...}` |
| Site | `Alfa\Component\Alfa\Site\{Controller,Model,View,...}` |
| API | `Alfa\Component\Alfa\Api\{Controller,View}` |
| Payment plugins | `Joomla\Plugin\AlfaPayments\{PluginName}\Extension` |
| Shipment plugins | `Joomla\Plugin\AlfaShipments\{PluginName}\Extension` |
| Field plugins | `Joomla\Plugin\AlfaFields\{PluginName}\Extension` |
| Cart module | `Alfa\Module\AlfaCart` |
| Search module | `Alfa\Module\AlfaSearch` |

### Naming

| Pattern | Example |
|---------|---------|
| Controllers | `ItemController`, `ItemsController` |
| Models | `ItemModel`, `CategoriesModel` |
| Views | `Items\HtmlView`, `Items\JsonapiView` |
| Tables | `ItemTable` |
| Events | `AdminOrderViewEvent`, `PaymentResponseEvent` |
| Helpers | `CartHelper`, `OrderPaymentHelper` |

### Key files

| File | Purpose |
|------|---------|
| `administrator/services/provider.php` | DI container — registers all component services |
| `administrator/src/Extension/AlfaComponent.php` | Component bootstrap class |
| `site/src/Helper/CartHelper.php` | Shopping cart logic |
| `site/src/Helper/OrderPlaceHelper.php` | Order placement flow |
| `site/src/Service/Pricing/` | Complete pricing engine |
| `administrator/src/Event/` | All event classes |
| `administrator/src/Plugin/` | Base plugin classes (Plugin, PaymentsPlugin, ShipmentsPlugin) |
| `administrator/sql/install.mysql.utf8.sql` | Complete database schema |

## Release & migrations
- 🔴 Any shipped-code change needs an `alfa.xml` `<version>` bump + a `changelog.xml` entry. Never re-touch a published version (changes the sha256, breaks signed integrity).
- 🔴 A schema change needs **all three**: `sql/updates/mysql/<ver>.sql` (that delta) + the same folded into `sql/install.mysql.utf8.sql` + the version bump. Removed files → `files/removed/<ver>.json`.
- 🔴 One column per `ALTER` (Joomla's parser reads only the first); idempotent (`IF [NOT] EXISTS`); new tables use `#__` + utf8mb4 + indexes; no duplicate `CREATE TABLE` in the install schema.
- 🟡 Flag files at an **installed** path (`administrator/components/com_alfa/…`) — the repo uses the source layout above; installed paths double-nest or won't package. Flag new top-level files not covered by any `alfa.xml` `<files>/<media>/<administration>` (they silently won't ship).

## Database
- 🔴 No SQL from request data: `quoteName()` identifiers, bound/`quote()` values, whitelisted `ORDER BY` + direction, `$db->escape($x, true)` for `LIKE`.
- 🟡 `getQuery(true)` reused without `clear()`; unguarded null `loadResult()`; `insert/updateObject` props that aren't columns (+ missing key arg on update).
- 🟡 N+1 in loops (→ `whereIn()`/JOIN); non-atomic multi-row writes (→ transaction); un-indexed new WHERE/JOIN/ORDER columns on big tables (`#__alfa_items|_orders|_media|_items_price_index`). The price index must be synced on item writes.

## Joomla gotchas
- 🔴 Custom `AbstractEvent` `setX`/`onSetX` named like a constructor arg — Joomla calls it as a mutator at construction and nulls the value; use distinct keys.
- 🔴 A custom controller `__construct($config)` must forward the injected MVCFactory, else `getModel()` returns false and list/save/checkin break.
- 🟡 `LayoutHelper::render()` in an AJAX/JSON task needs an explicit base path (else empty); `onBeforeCompileHead` → guard `getDocument() instanceof HtmlDocument`; only real `icon-*` classes; `Text::script()` for JS-read keys; exact `joomla.asset.json` names; don't `defer` `$(document).ready` binders.

## Multilingual
- 🔴 No base-table column for a `multilingual_table` field — a NOT-NULL one fails every save (*"doesn't have a default value"*). Values live in `#__alfa_<entity>_<langtag>`: written via `saveMultilingualData()`, read via `addMultilingualJoinToQuery()`.

## Security
- 🔴 No injection (above); no secrets in the repo (signing key is server-only); `Session::checkToken()` on state-changing tasks, `authorise()` on privileged actions; no sensitive IDs in URLs (verify server-side).

## Conventions
- 🟡 `{Name}Controller/Model`, `{Name}\HtmlView`, events `{Context}{Action}Event` in `src/Event/`. House style: named arguments on calls, professional PHPDoc, Joomla built-ins only; a new plugin needs `services/provider.php`.
- 🟡 When a DB column/field a plugin reads is renamed, update its consumers in lockstep. Event/plugin handlers stay pure side effects — never hijack a response (e.g. the controller's JSON) that isn't theirs.
- 🟡 `tmpl/` and `layouts/` are presentation-only and excluded from PHPStan — flag heavy logic there (queries, pricing, complex branching); they should only loop, escape, format.

## i18n
- 🟡 Hardcoded **user-facing** strings anywhere they're produced (controllers/models/views/helpers/plugins/templates) — labels, buttons, notices, shown errors — must use `Text::_()`/`sprintf()` keys. Exempt: logs, array/option keys, HTML attrs/classes.
- 🔵 Every used `Text::` key must be defined; `.ini` = one `KEY="…"` per line, `&quot;` for embedded quotes.

## Don't flag
Style (CS Fixer), PHPStan findings, or dev-box integrity "drift". PRs target `developer`, never `main`.
