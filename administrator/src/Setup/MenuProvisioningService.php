<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Creates the storefront's menu items, the gap every competitor wizard leaves open:
 * a fully configured store whose shop, cart and account pages exist for NOBODY
 * because no menu item points at them.
 *
 * Deliberately conservative, and APP-OWNED end to end (never squat a
 * menu the site already had): only the views that are MISSING site-wide are
 * created (a merchant's own menu item for a view always wins), items land in the
 * component's OWN menu type ({@see self::MENUTYPE}, created on first need), and
 * every created id is recorded in the `setup_created_menu_ids` param: we may later
 * remove what we created, never what we didn't.
 *
 * Writes go through {@see Table\Menu} (a nested set, never raw INSERTs):
 * setLocation + store + rebuildPath keep lft/rgt/path sane.
 *
 * @since  1.10.0
 */
final class MenuProvisioningService
{
    /**
     * The component's own site menu: where everything we provision lives, clearly
     * labelled as ours in Menus. Nothing is ever created in a menu the site
     * already had.
     *
     * @var string
     * @since  1.10.0
     */
    public const MENUTYPE = 'alfa-storefront';

    /**
     * Every storefront page we know how to create, and how its menu item is born.
     *
     * Two tiers, and the difference is only what the setup check DEMANDS: shop and
     * cart are what "reachable" means (cart hosts checkout as layouts, so one item
     * covers both), while account and registration are the customer-facing pages a
     * merchant most often forgets: provisioned all the same, so forgetting costs
     * nothing, but never gating the checklist.
     *
     * @var array<string, array{title: string, alias: string}>
     * @since  1.10.0
     */
    public const STOREFRONT = [
        'items' => ['title' => 'COM_ALFA_SETUP_MENU_TITLE_SHOP',     'alias' => 'shop'],
        'cart' => ['title' => 'COM_ALFA_SETUP_MENU_TITLE_CART',     'alias' => 'cart'],
        'userinfo' => ['title' => 'COM_ALFA_SETUP_MENU_TITLE_ACCOUNT',  'alias' => 'my-account'],
        'registration' => ['title' => 'COM_ALFA_SETUP_MENU_TITLE_REGISTER', 'alias' => 'register'],
    ];

    /**
     * The views the storefront is judged on. Everything else in {@see self::STOREFRONT}
     * is created as a convenience and never reported as missing.
     *
     * @var string[]
     * @since  1.10.0
     */
    public const REQUIRED = ['items', 'cart'];

    /**
     * Joomla's association context for menu items: the same string com_menus
     * writes, or the switcher would not read what we create.
     *
     * @var string
     * @since  1.10.0
     */
    public const ASSOCIATIONS_CONTEXT = 'com_menus.item';

    /**
     * Create whichever storefront menu items are missing.
     *
     * @param string|null $menutype Target menu; null (the product path) uses the
     *                              component's OWN {@see self::MENUTYPE}, creating
     *                              it on first need.
     * @param bool $ignoreExisting TEST SEAM ONLY: skip the "view already reachable"
     *                             probe so a harness can exercise creation without
     *                             touching real menus.
     * @param string[]|null $views Which storefront pages to create; null = all of
     *                             {@see self::STOREFRONT}. Lets the shop pages and
     *                             the customer pages land in DIFFERENT menus, which
     *                             is how most sites actually want them.
     *
     * @return array<int, array{id: int, view: string, title: string}> What was created.
     *
     * @throws RuntimeException When the site has no menu to create items in, or a row
     *                          refuses to store: surfaced, never swallowed: a half
     *                          provisioned storefront must be visible.
     *
     * @since  1.10.0
     */
    public static function provisionStorefront(?string $menutype = null, bool $ignoreExisting = false, ?array $views = null): array
    {
        // An unknown menu is a typo, not a menu: fall back to our own rather than
        // writing items into a menutype that does not exist.
        if ($menutype !== null && ($menutype === '' || !self::menuExists($menutype))) {
            $menutype = null;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $componentId = self::componentId($db);

        // Multilingual site => one item PER published content language (a shopper's
        // language without a route is a storefront that doesn't exist for them);
        // monolingual => a single all-languages item.
        $languages = SetupHelper::contentLanguages();
        $targets = $languages === [] ? ['*' => ''] : $languages;

        // The shop landing gets its identity category ONLY when the tree makes it
        // unambiguous (exactly one published root); guessing between roots would be
        // inventing the merchant's information architecture.
        $rootId = self::soleRootCategoryId($db);
        $created = [];

        $wanted = $views === null ? self::STOREFRONT : array_intersect_key(self::STOREFRONT, array_flip($views));

        foreach ($wanted as $view => $item) {
            // A sign-up page on a site that refuses sign-ups is a dead end: the form
            // renders and the controller then refuses the submit. Skip it entirely
            // rather than hand the merchant a page that cannot work.
            if ($view === 'registration' && !ComponentHelper::getParams('com_users')->get('allowUserRegistration', 1)) {
                continue;
            }

            foreach ($targets as $language => $sef) {
                if (!$ignoreExisting && self::viewReachable($db, $view, $language === '*' ? null : $language)) {
                    continue;
                }

                // Lazily, so a run with nothing missing leaves no empty menu behind.
                $menutype ??= self::ensureOwnMenu($db);

                $title = Text::_($item['title']);
                $alias = self::uniqueAlias($db, $item['alias'] . ($sef !== '' ? '-' . $sef : ''));

                /** @var \Joomla\CMS\Table\Menu $table */
                $table = Table::getInstance('Menu');

                $table->setLocation(1, 'last-child');

                $data = [
                    'menutype' => $menutype,
                    'title' => $title,
                    'alias' => $alias,
                    'note' => 'Created by the Alfa setup checklist',
                    'path' => $alias,
                    'link' => 'index.php?option=com_alfa&view=' . $view
                        . ($view === 'items' && $rootId > 0 ? '&category_id=' . $rootId : ''),
                    'type' => 'component',
                    'published' => 1,
                    'parent_id' => 1,
                    'level' => 1,
                    'component_id' => $componentId,
                    'access' => 1,
                    'img' => '',
                    'template_style_id' => 0,
                    'params' => '{}',
                    'home' => 0,
                    'language' => $language,
                    'client_id' => 0,
                    'browserNav' => 0,
                ];

                if (!$table->bind($data) || !$table->check() || !$table->store()) {
                    throw new RuntimeException('Could not create the "' . $title . '" menu item: ' . $table->getError());
                }

                $table->rebuildPath((int) $table->id);

                $created[] = ['id' => (int) $table->id, 'view' => $view, 'title' => $title, 'language' => $language];
            }
        }

        if ($created !== []) {
            self::trackCreated(array_column($created, 'id'));

            // Creating the language copies is only half the job: unassociated
            // copies leave the switcher with nowhere to send the shopper.
            self::associateStorefront();
        }

        return $created;
    }

    /**
     * The site's menus, menutype => title, for the "where should these go?" question.
     * The empty key offers our own menu, created on demand.
     *
     * @return array<string, string>
     *
     * @since  1.10.0
     */
    public static function menuOptions(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $options = ['' => Text::_('COM_ALFA_SETUP_MENU_TARGET_OWN')];

        try {
            foreach ((array) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['menutype', 'title']))
                    ->from($db->quoteName('#__menu_types'))
                    ->where($db->quoteName('client_id') . ' = 0')
                    ->order($db->quoteName('title') . ' ASC'),
            )->loadObjectList() as $row) {
                $options[(string) $row->menutype] = (string) $row->title;
            }
        } catch (Throwable) {
            // A site with no menus at all still gets the "create ours" option.
        }

        return $options;
    }

    /**
     * Whether the site has this menu.
     *
     * @param string $menutype The menu type.
     *
     *
     * @since  1.10.0
     */
    private static function menuExists(string $menutype): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (string) $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__menu_types'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('menutype') . ' = :menutype')
                ->bind(':menutype', $menutype, ParameterType::STRING),
            0,
            1,
        )->loadResult() !== '';
    }

    /**
     * The single published root category's id, or 0 when the tree has none or
     * several (ambiguity means we attach nothing rather than guess).
     *
     * @param DatabaseInterface $db The database.
     *
     *
     * @since  1.10.0
     */
    private static function soleRootCategoryId(DatabaseInterface $db): int
    {
        try {
            $roots = (array) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__alfa_categories'))
                    ->where('(' . $db->quoteName('parent_id') . ' = 0 OR ' . $db->quoteName('parent_id') . ' IS NULL)')
                    ->where($db->quoteName('state') . ' = 1'),
                0,
                2,
            )->loadColumn();

            return \count($roots) === 1 ? (int) $roots[0] : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The component's own menu type, created when first needed, never a menu the
     * site already had (everything we provision is OURS, recognisable
     * in Menus as "Alfa Commerce").
     *
     * @param DatabaseInterface $db The database.
     *
     * @return string The {@see self::MENUTYPE} value.
     *
     * @throws RuntimeException When the menu type cannot be stored.
     *
     * @since  1.10.0
     */
    private static function ensureOwnMenu(DatabaseInterface $db): string
    {
        $menutype = self::MENUTYPE;
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu_types'))
            ->where($db->quoteName('menutype') . ' = :menutype')
            ->bind(':menutype', $menutype, ParameterType::STRING);

        if ((int) $db->setQuery($query, 0, 1)->loadResult() > 0) {
            return self::MENUTYPE;
        }

        /** @var \Joomla\CMS\Table\MenuType $table */
        $table = Table::getInstance('MenuType');

        $data = [
            'menutype' => self::MENUTYPE,
            'title' => Text::_('COM_ALFA_SETUP_MENU_TYPE_TITLE'),
            'description' => Text::_('COM_ALFA_SETUP_MENU_TYPE_DESC'),
            'client_id' => 0,
        ];

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw new RuntimeException('Could not create the Alfa Commerce menu: ' . $table->getError());
        }

        return self::MENUTYPE;
    }

    /**
     * Whether a published site menu item already reaches this com_alfa view, in
     * the given language when one is named (an all-languages `*` item counts). The
     * same truth the `storefront.menu` detector reads, so provisioning and the
     * checklist can never disagree.
     *
     * @param DatabaseInterface $db The database.
     * @param string $view The storefront view.
     * @param string|null $language A content language tag, or null for any.
     *
     *
     * @since  1.10.0
     */
    public static function viewReachable(DatabaseInterface $db, string $view, ?string $language = null): bool
    {
        $option = '%option=com_alfa%';
        $target = '%view=' . $view . '%';
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('link') . ' LIKE :optionLike')
            ->where($db->quoteName('link') . ' LIKE :viewLike')
            ->bind(':optionLike', $option, ParameterType::STRING)
            ->bind(':viewLike', $target, ParameterType::STRING);

        if ($language !== null) {
            $query->whereIn($db->quoteName('language'), ['*', $language], ParameterType::STRING);
        }

        $db->setQuery($query, 0, 1);

        return (string) $db->loadResult() !== '';
    }

    /**
     * Whether a published site menu item points at this storefront view, in the
     * given language, when one is named (an all-languages item counts for every
     * language).
     *
     * @param string $view A key of {@see self::STOREFRONT}.
     * @param string|null $language Content language tag, or null for any.
     *
     *
     * @since  1.10.0
     */
    public static function reachable(string $view, ?string $language = null): bool
    {
        return self::viewReachable(Factory::getContainer()->get(DatabaseInterface::class), $view, $language);
    }

    /**
     * The storefront pages this site should have but has not, as view => the
     * languages it is missing in ('*' on a monolingual site).
     *
     * Registration answers only while Joomla accepts sign-ups: a shop that has
     * turned registration off is not missing a registration page, it decided
     * against one.
     *
     * @param string[]|null $views Which views to judge; null = all of {@see self::STOREFRONT}.
     *
     * @return array<string, string[]> view => missing language tags
     *
     * @since  1.10.0
     */
    public static function missingViews(?array $views = null): array
    {
        $languages = SetupHelper::contentLanguages();
        $targets = $languages === [] ? ['*'] : array_keys($languages);
        $missing = [];

        foreach ($views ?? array_keys(self::STOREFRONT) as $view) {
            if ($view === 'registration' && !ComponentHelper::getParams('com_users')->get('allowUserRegistration', 1)) {
                continue;
            }

            foreach ($targets as $language) {
                if (!self::reachable($view, $language === '*' ? null : $language)) {
                    $missing[$view][] = $language;
                }
            }
        }

        return $missing;
    }

    /**
     * The storefront pages whose per-language menu items are not tied together.
     *
     * Reachable in every language is only half of multilingual: without an
     * association the language switcher cannot map the page a shopper is reading
     * onto its twin, and drops them on the front page instead. Monolingual sites
     * and views covered by a single all-languages item have nothing to associate
     * and never appear here.
     *
     * @return array<string, array<string, int>> view => language => menu item id
     *
     * @since  1.10.0
     */
    public static function unassociatedViews(): array
    {
        $languages = SetupHelper::contentLanguages();

        if ($languages === []) {
            return [];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $result = [];

        foreach (array_keys(self::STOREFRONT) as $view) {
            $rows = self::storefrontRows($db, $view);

            // An all-languages item serves every language on its own.
            if (isset($rows['*'])) {
                continue;
            }

            $rows = array_intersect_key($rows, $languages);

            // One language only: that is a reachability gap, not an association one.
            if (\count($rows) < 2) {
                continue;
            }

            $keys = self::associationKeys($db, array_values($rows));

            // Tied together = every id carries the SAME non-empty key.
            if (\count($keys) === \count($rows) && \count(array_unique($keys)) === 1) {
                continue;
            }

            $result[$view] = $rows;
        }

        return $result;
    }

    /**
     * Tie each storefront page's language copies together.
     *
     * Additive and non-destructive on purpose: a view where ANY copy already
     * carries an association key is left alone: that grouping is the merchant's,
     * and rewriting it would silently break associations we know nothing about.
     *
     * @return string[] The views that were associated.
     *
     * @since  1.10.0
     */
    public static function associateStorefront(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $done = [];

        foreach (self::unassociatedViews() as $view => $rows) {
            if (self::associationKeys($db, array_values($rows)) !== []) {
                continue;
            }

            // Joomla's own contract (com_menus ItemModel): one row per member,
            // context com_menus.item, key = md5 of the language => id map.
            $key = md5(json_encode($rows));
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__associations'))
                ->columns($db->quoteName(['id', 'context', 'key']));

            foreach ($rows as $id) {
                $query->values(
                    implode(',', $query->bindArray([$id, self::ASSOCIATIONS_CONTEXT, $key], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING])),
                );
            }

            $db->setQuery($query)->execute();

            $done[] = $view;
        }

        return $done;
    }

    /**
     * Published site menu items pointing at one storefront view, language => id.
     * The lowest id wins a language, so a merchant's own item is preferred over a
     * later duplicate.
     *
     * @param DatabaseInterface $db The database.
     * @param string $view The storefront view.
     *
     * @return array<string, int>
     *
     * @since  1.10.0
     */
    private static function storefrontRows(DatabaseInterface $db, string $view): array
    {
        $option = '%option=com_alfa%';
        $target = '%view=' . $view . '%';
        $rows = (array) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'language']))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('link') . ' LIKE :optionLike')
                ->where($db->quoteName('link') . ' LIKE :viewLike')
                ->bind(':optionLike', $option, ParameterType::STRING)
                ->bind(':viewLike', $target, ParameterType::STRING)
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->language] ??= (int) $row->id;
        }

        return $map;
    }

    /**
     * The association keys those menu items already carry (empty when none do).
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $ids Menu item ids.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    private static function associationKeys(DatabaseInterface $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return (array) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('key'))
                ->from($db->quoteName('#__associations'))
                ->where($db->quoteName('context') . ' = ' . $db->quote(self::ASSOCIATIONS_CONTEXT))
                ->whereIn($db->quoteName('id'), $ids),
        )->loadColumn();
    }

    /**
     * An alias no other root-level site item carries; Joomla treats duplicate
     * sibling aliases as a routing conflict.
     *
     * @param DatabaseInterface $db The database.
     * @param string $base The wanted alias.
     *
     * @return string `shop`, or `shop-2` ... when taken.
     *
     * @since  1.10.0
     */
    private static function uniqueAlias(DatabaseInterface $db, string $base): string
    {
        $taken = array_map('strval', (array) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('alias'))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('parent_id') . ' = 1'),
        )->loadColumn());

        if (!\in_array($base, $taken, true)) {
            return $base;
        }

        $suffix = 2;

        while (\in_array($base . '-' . $suffix, $taken, true)) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }

    /**
     * Record what we created, merged into the ids already tracked: the ONLY rows a
     * future cleanup may ever touch.
     *
     * @param int[] $ids The created menu ids.
     *
     *
     * @since  1.10.0
     */
    private static function trackCreated(array $ids): void
    {
        $known = SetupHelper::createdMenuIds();

        SetupHelper::mergeParams([
            'setup_created_menu_ids' => json_encode(array_values(array_unique(array_merge($known, array_map('intval', $ids))))),
        ]);
    }

    /**
     * The site com_alfa component id (menu rows reference it).
     *
     * @param DatabaseInterface $db The database.
     *
     *
     * @since  1.10.0
     */
    private static function componentId(DatabaseInterface $db): int
    {
        $element = 'com_alfa';
        $type = 'component';
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':element', $element, ParameterType::STRING);

        return (int) $db->setQuery($query, 0, 1)->loadResult();
    }
}
