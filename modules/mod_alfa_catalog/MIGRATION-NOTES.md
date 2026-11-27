# From the items, categories and manufacturers modules to mod_alfa_catalog

Notes for a later step that converts existing module instances. Nothing here is done yet: the three modules are
untouched and no instance is converted.

## How an instance is converted

An instance is one row of `#__modules`. Converting it means changing two columns of that row and nothing else:

- `module`: the old element becomes `mod_alfa_catalog`;
- `params`: the old JSON becomes the JSON built from the maps below.

Everything else stays because it is not touched: `id`, `title`, `note`, `position`, `ordering`, `published`,
`publish_up`, `publish_down`, `access`, `language`, `showtitle`, `client_id`, the menu assignment (the rows of
`#__modules_menu`, keyed by the module's id) and the row's asset (`com_modules.module.<id>`).

Before: `mod_alfa_catalog` is installed (its row is in `#__extensions`). After: the cache groups of the old element
and of `mod_alfa_catalog` are cleaned. Keep the old `params` JSON of every converted row: with it and the old
element name the conversion is undone by writing both back.

A parameter the new module does not know is left out of the new JSON. A parameter the old module did not have takes
the new form's default.

## mod_alfa_items

Every parameter keeps its name and its value. The module's parameters:

| Old | New | Note |
| --- | --- | --- |
| `tabs` | `tabs` | row by row, see below |
| `card` | `card` | the same JSON (`base`, `bp`) |
| `style_surface`, `style_text`, `style_muted`, `style_hairline`, `style_accent` and their `_dark` twins | the same names | |
| `bestsellers_days`, `bestsellers_cache` | the same names | |
| `layout`, `moduleclass_sfx` | the same names | see "By hand" for a template override |
| `custom_css`, `custom_js` | the same names | the text is rewritten, see "By hand" |
| `owncache`, `cache_time` | the same names | |

Each row of `tabs`:

| Old | New | Note |
| --- | --- | --- |
| (none) | `kind` = `products` | |
| `label`, `source`, `categories`, `labels`, `ordering`, `limit`, `show_link`, `card` | the same names | |
| (none) | `manufacturers` = empty | new filter |
| (none) | `auto` = empty | one plain tab, as before |

By hand, because it is not in the row:

- a template override `html/mod_alfa_items/default_card.php` becomes `html/mod_alfa_catalog/default_card_product.php`;
  overrides of `default.php`, `default_group.php` and `default_slider.php` are rewritten against the new files
  (the group layout receives `$records` and draws a card with `AlfaCatalogHelper::renderCard(group, record, params,
  place, moduleId)`);
- in `custom_css` and in a site's stylesheet: `.mod-alfa-items` becomes `.mod-alfa-catalog`, `.mod-alfa-items-<id>`
  becomes `.mod-alfa-catalog-<id>`, and every custom property changes its prefix: `--alfa-items-*` and
  `--mod-alfa-items-*` both become `--mod-alfa-catalog-*`;
- in `custom_js`: the root attribute `data-mod-alfa-items` becomes `data-mod-alfa-catalog`, and a slider's instance
  on its element is `catalogSlider`.

No counterpart: nothing. What changes without being asked for: the module's script is no longer loaded for a
single tab that is a grid.

## mod_alfa_categories

The instance becomes a module of one tab: a list of the categories inside a parent, which is the navigation tree.

| Old | New (in the one row of `tabs`) | Note |
| --- | --- | --- |
| (none) | `kind` = `categories` | |
| (none) | `cat_select` = `children` | |
| `parent` | `cat_parent` | 0 = top level in both |
| `levels` | `cat_levels` | 1 to 10 in both |
| `open` | `cat_open` | `current` or `all` in both |
| `show_count` | `cat_show_count` | |
| (none) | `cat_ordering` = `ordering` | the tree's own order, as before |
| (none) | `cat_limit` = 0 | all, as before |
| (none) | `card` = `{"base":{"display":"list"}}` | the list presentation |
| `layout`, `moduleclass_sfx` | the module's `layout`, `moduleclass_sfx` | |

Its abilities as options of the list: start from a parent or the top level, levels, open the visitor's branch or
every branch, product counts, the visitor's category marked `aria-current="page"`, its branch `data-state="open"`,
a navigation landmark named after the module's title. New beside them: order by name, a number at the first level,
chosen categories as a flat list, the same categories as cards with image and description.

By hand:

- a template override `html/mod_alfa_categories/default.php` becomes `html/mod_alfa_catalog/default_list_category.php`
  and `default_level.php` becomes `default_list_category_level.php`. A node keeps `id`, `name`, `link`, `count`,
  `current`, `open`, `children`;
- site CSS: the root is `.mod-alfa-catalog` (a `div`; the `nav` inside it is `.tree-nav`); `.tree` and `.node` keep
  their names; `.link` becomes `.entry-link`, `.name` becomes `.entry-name`, `.count` becomes `.entry-count`;
- custom properties: `--mod-alfa-categories-indent` becomes `--mod-alfa-catalog-indent`, `-padding` becomes
  `--mod-alfa-catalog-list-padding`, `-color` becomes `--mod-alfa-catalog-surface-color`, `-current` becomes
  `--mod-alfa-catalog-accent`, `-muted` becomes `--mod-alfa-catalog-muted`, `-hairline` becomes
  `--mod-alfa-catalog-hairline`.

No counterpart: nothing. What changes without being asked for: the tree is read a level at a time (one statement
per level drawn, below the first level only for the categories that are open) where the old module read the shop's
whole stored tree; the records go through the module cache (one entry per visitor's category), which the Advanced
tab's Caching switches off.

## mod_alfa_manufacturers

The instance becomes a module of one tab of manufacturers, shown as a grid.

| Old | New (in the one row of `tabs`) | Note |
| --- | --- | --- |
| (none) | `kind` = `manufacturers` | |
| `manufacturers` | `man_ids` | empty = every manufacturer in both |
| `show` | `man_show` | `logo_name`, `logo`, `name` in both |
| `link` | `man_link` | `products` or `page` in both |
| `ordering` | `man_ordering` | `name` or `ordering` in both |
| `limit` | `man_limit` | 0 = all in both |
| (none) | `man_show_count` = 0 | new |
| (none) | `card` = `{"base":{"display":"grid"}}` | or `list` for a sidebar: name, small logo, count |
| `layout`, `moduleclass_sfx` | the module's `layout`, `moduleclass_sfx` | |

Its abilities as options: chosen manufacturers or all, logo and name or one of them (a manufacturer without a logo
shows its name), link to its products or to its page, order by name or as in the Manufacturers list, a number. New
beside them: product counts, a slider, a list of plain rows, the card settings of the Style tab.

By hand:

- a template override `html/mod_alfa_manufacturers/default.php` becomes
  `html/mod_alfa_catalog/default_card_manufacturer.php` (one card) or `default_list_manufacturer.php` (the list);
  an entry keeps `id`, `name`, `link`; its `logo` is now `image`, with `alt` and `count` beside it;
- site CSS: the root is `.mod-alfa-catalog`; an entry is `.item-card` with `.item-card-image` and
  `.item-card-title` (grid, slider) or `.entry` with `.entry-link`, `.entry-image`, `.entry-name` (list);
- custom properties: `--mod-alfa-manufacturers-logo-height` becomes `--mod-alfa-catalog-logo-height`, `-gap`
  becomes the grid's Gap setting, `-padding` the Card padding setting, `-radius` the Card corners setting,
  `-hairline` becomes `--mod-alfa-catalog-card-border-color`, `-accent` becomes `--mod-alfa-catalog-accent`.

No counterpart:

- the old grid held as many columns as fitted (`--mod-alfa-manufacturers-min`, 7rem). The new grid has a set
  number of columns per screen (4, 2 on tablets, 1 on mobiles until set, up to 8). A converter either sets Columns
  per screen in `card`, or keeps the old behaviour with one rule in `custom_css`:
  `.mod-alfa-catalog-<id> .grid { grid-template-columns: repeat(auto-fill, minmax(min(100%, 7rem), 1fr)); }`;
- the old entry had a ground (`--mod-alfa-manufacturers-surface`) and its border took the accent colour on hover.
  The card has no ground and no hover border; its hover effect is the Shadow on hover setting;
- the old entry's border was the hairline colour and its corners 4px. A card's border is black, 1px, square until
  set: a converter sets Card corners to 4 and `--mod-alfa-catalog-card-border-color` in `custom_css`.
