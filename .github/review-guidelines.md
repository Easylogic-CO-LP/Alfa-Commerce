# Alfa Commerce — PR Review Skill

Review PRs for **com_alfa** (Joomla 6/7 eCommerce component; PHP, no Composer deps) as a com_alfa
**and** Joomla specialist. Read `CLAUDE.md`
for architecture.

**How to review** — comment inline, concise, severity-tagged: 🔴 breaks installs/data/security · 🟡 likely bug or
convention · 🔵 minor. Never flag style (PHP CS Fixer auto-commits fixes) or anything PHPStan reports.
Only raise what you're confident about; acknowledge good work.

## Release & migrations
- Require a `<version>` bump in `alfa.xml` + a `changelog.xml` entry for any shipped-code change.
  Reject re-touching an already-published version (changes the sha256, breaks signed integrity). 🔴
- Require **all three** for a schema change: `sql/updates/mysql/<ver>.sql` (that delta only) + the same
  folded into `sql/install.mysql.utf8.sql` + the version bump. Files removed → `files/removed/<ver>.json`. 🔴
- Flag repo files placed at an **installed** path (e.g. `administrator/components/com_alfa/…`): the repo uses the **source** layout (`administrator/`, `site/`, `api/`, `media/com_alfa/`) that `alfa.xml` `<files folder>` maps on install — an installed-style path double-nests or won't package. Also flag new top-level files/folders not covered by any `alfa.xml` `<files>/<media>/<administration>` declaration (they silently won't ship). 🟡
- Require **one column per `ALTER`** (Joomla's Database-Fix parser reads only the first), idempotent
  deltas (`IF [NOT] EXISTS`), and `#__` + utf8mb4 + indexes on new tables; reject duplicate
  `CREATE TABLE` in the install schema. 🔴

## Database queries
- Reject SQL built from request data: require `quoteName()` on identifiers, bound/`quote()` values,
  whitelisted `ORDER BY` + direction, and `$db->escape($x, true)` for `LIKE`. 🔴
- Flag a `getQuery(true)` reused without `clear()`, an unguarded null `loadResult()`, and
  `insert/updateObject` properties that aren't real columns (+ missing key arg on update). 🟡
- Flag N+1 queries in loops (→ `whereIn()`/JOIN), non-atomic multi-row writes (→ transaction), and
  un-indexed new WHERE/JOIN/ORDER columns on big tables (`#__alfa_items|_orders|_media|_items_price_index`);
  require the price index synced on item writes. 🟡

## Joomla gotchas (the ones this app hits)
- Reject a custom `AbstractEvent` `setX`/`onSetX` named like a **constructor arg** — Joomla calls it as
  a mutator at construction and silently nulls the value; results use distinct keys. 🔴
- Require a custom controller `__construct($config)` to forward the injected MVCFactory, else
  `getModel()` returns false and list/save/checkin break. 🔴
- `LayoutHelper::render()` in an AJAX/JSON task needs an **explicit base path** (else it silently
  returns empty). 🟡
- `onBeforeCompileHead` → guard `getDocument() instanceof HtmlDocument`. 🟡
- Use only `icon-*` classes that exist in Joomla's icon set (`icon-unlink` doesn't); `Text::script()`
  for any key read in JS; exact `joomla.asset.json` names; don't `defer` `$(document).ready` binders. 🟡

## Multilingual fields
- Reject a base-table column for a `multilingual_table` field — a NOT-NULL one makes every save fail
  (*"doesn't have a default value"*). Values live in `#__alfa_<entity>_<langtag>`; saved via
  `saveMultilingualData()`, read via `addMultilingualJoinToQuery()`. 🔴

## Security
- No injection (above); no secrets in the repo (the signing key is server-only). Require
  `Session::checkToken()` on state-changing tasks and `authorise()` on privileged actions; reject
  sensitive IDs passed in URLs (verify server-side). The integrity baseline is captured only in
  `script.php` postflight and the "clean" verdict stays gated behind HMAC. 🔴

## Conventions
- Namespaces `Alfa\Component\Alfa\{Administrator|Site|Api}`; `{Name}Controller/Model`,
  `{Name}\HtmlView`; events `{Context}{Action}Event` in `src/Event/`. Require **named arguments** on
  calls (house style), professional PHPDoc on classes/methods, and Joomla built-ins only (no Composer);
  new plugin → `services/provider.php`. 🟡
- When a DB column or field read by a plugin changes/renames, require its consumers updated **in
  lockstep**. Event/plugin handlers must stay **pure side effects** — never hijack a response (e.g.
  the controller's JSON) that isn't theirs. 🟡
- `tmpl/` and `layouts/` are presentation-only **and excluded from PHPStan** (a blind spot). Flag
  heavy logic there — DB queries, pricing/business calculations, complex branching — it belongs in a
  model/helper/controller; the template should only loop, escape and format. 🟡

## i18n
- Flag hardcoded **user-facing** strings anywhere they're produced — controllers (`enqueueMessage`),
  models, views, helpers, plugins, templates: labels, buttons, headings, notices, user-shown
  exception/error text. Require `Text::_()` / `Text::sprintf()` keys defined in the `.ini`, never
  literals. Log lines, array/option keys, HTML/CSS attributes and class names are exempt. 🟡
- Every used `Text::` key must be defined; `.ini` = one `KEY="…"` per line, no newline inside a value,
  `&quot;` for embedded quotes. 🔵

## Don't flag
Style/formatting (CS Fixer), PHPStan findings, or dev-box integrity "drift" (normal for unreleased
files). Premium gateways/modules (Revolut, Viva, BoxNow, Klarna, PayPal, filters, media optimizer) are
**CDN-only by design** — never suggest bundling them or mention their removal publicly. PRs target
`developer`, never `main`.
