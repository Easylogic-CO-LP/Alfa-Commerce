<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Setup\CollectSetupChecksEvent;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Alfa\Component\Alfa\Administrator\Setup\MenuProvisioningService;
use Alfa\Component\Alfa\Administrator\Setup\SetupCheck;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The component's own setup checks, registered through the same event plugins use,
 * and, like {@see \Alfa\Component\Alfa\Administrator\Service\Tools\CoreToolProvider},
 * NOT as a plugin: "store cannot take an order" must not be disableable.
 *
 * Every probe tests CORRECTNESS, not presence: a payment method row whose backing
 * plugin is disabled is not an active payment method, an unpublished category is not
 * a category customers can browse. (Presence-only probes are how another shop system shows
 * "Your Store Is Ready" on a store that cannot check out.)
 *
 * Probes are existence reads (LIMIT 1 on indexed columns) or params reads: cheap
 * enough that the cache in {@see SetupHelper} exists for politeness, not survival.
 *
 * @since  1.10.0
 */
final class CoreSetupProvider
{
    /**
     * Reserved owner identity; mirrors CoreToolProvider so ownership rendering and
     * routing treat core and plugins uniformly.
     *
     * @var string
     * @since  1.10.0
     */
    public const OWNER_GROUP = 'core';
    public const OWNER_NAME = 'com_alfa';

    /**
     * Register the core checklist. Priorities encode the natural DOING order, higher
     * first: identity before currency before tax before methods before catalogue.
     *
     * @param CollectSetupChecksEvent $event The collecting event.
     *
     *
     * @since  1.10.0
     */
    public function onAlfaCollectSetupChecks(CollectSetupChecksEvent $event): void
    {
        $event->add('store.identity', 'COM_ALFA_SETUP_CHECK_IDENTITY_LABEL')
            ->tier(SetupCheck::TIER_REQUIRED)
            ->reason('COM_ALFA_SETUP_CHECK_IDENTITY_REASON')
            ->cta('index.php?option=com_config&view=component&component=com_alfa')
            ->tour('com_alfa.store-options')
            ->priority(950)
            ->probe(static function (): bool {
                $params = ComponentHelper::getParams('com_alfa');

                return trim((string) $params->get('store_name', '')) !== ''
                    && trim((string) $params->get('store_email', '')) !== '';
            });

        $event->add('store.currency', 'COM_ALFA_SETUP_CHECK_CURRENCY_LABEL')
            ->tier(SetupCheck::TIER_REQUIRED)
            ->reason('COM_ALFA_SETUP_CHECK_CURRENCY_REASON')
            ->cta('index.php?option=com_config&view=component&component=com_alfa')
            ->tour('com_alfa.store-options')
            ->priority(900)
            ->probe(static function (): bool {
                // The param carries the ISO-4217 NUMBER (`number` column): the
                // contract of the Options field and of Pricing\Currency, never
                // the row id.
                $number = (int) ComponentHelper::getParams('com_alfa')->get('default_currency', 0);

                return $number > 0 && self::exists(
                    table: '#__alfa_currencies',
                    where: ['number' => $number, 'state' => 1],
                );
            });

        $event->add('tax.configured', 'COM_ALFA_SETUP_CHECK_TAX_LABEL')
            ->tier(SetupCheck::TIER_REQUIRED)
            ->reason('COM_ALFA_SETUP_CHECK_TAX_REASON')
            ->cta('index.php?option=com_alfa&view=taxes')
            ->tour('com_alfa.taxes')
            ->priority(850)
            ->probe(static fn (): bool => self::exists(table: '#__alfa_taxes', where: ['state' => 1]));

        // The identity fields (name, email) ship seeded and locked, so a store
        // can take orders from day one; what this checks is whether the
        // merchant added their own checkout fields (an address). RECOMMENDED,
        // not required: a digital-goods store legitimately runs on name + email.
        $event->add('checkout.form', 'COM_ALFA_SETUP_CHECK_CHECKOUT_FORM_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_CHECKOUT_FORM_REASON')
            ->cta('index.php?option=com_alfa&view=formfields')
            ->tour('com_alfa.form-fields')
            ->priority(800)
            ->probe(static fn (): bool => self::hasMerchantCheckoutFields());

        // Payment/shipping methods are RECOMMENDED, not
        // required: a store can legitimately run without them (catalogue mode,
        // quote/offline flows), so they never block "ready" or feed the notification.
        $event->add('payments.active', 'COM_ALFA_SETUP_CHECK_PAYMENTS_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_PAYMENTS_REASON')
            ->cta('index.php?option=com_alfa&view=payments')
            ->tour('com_alfa.payments')
            ->priority(750)
            ->probe(static fn (): bool => self::methodBackedByPlugin(table: '#__alfa_payments', folder: 'alfa-payments'));

        $event->add('shipping.active', 'COM_ALFA_SETUP_CHECK_SHIPPING_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_SHIPPING_REASON')
            ->cta('index.php?option=com_alfa&view=shipments')
            ->tour('com_alfa.shipping')
            ->priority(700)
            ->probe(static fn (): bool => self::methodBackedByPlugin(table: '#__alfa_shipments', folder: 'alfa-shipments'));

        $event->add('storefront.menu', 'COM_ALFA_SETUP_CHECK_MENU_LABEL')
            ->tier(SetupCheck::TIER_REQUIRED)
            ->reason('COM_ALFA_SETUP_CHECK_MENU_REASON')
            ->cta('index.php?option=com_menus&view=items')
            ->tour(self::menuTourFor(MenuProvisioningService::REQUIRED))
            ->priority(650)
            // Configured is not the same as reachable: without published menu items the
            // storefront exists for nobody (the gap every competitor wizard leaves open),
            // and on a multilingual site, "reachable" means reachable in EVERY language.
            ->probe(static fn (): bool => self::storefrontReachable())
            // Where they go is the merchant's call, not ours: the row asks before it
            // builds anything, and the shop pages may live in a different menu from
            // the customer ones. Created ids are tracked so only OUR rows are ever
            // cleanup candidates.
            ->choice('menutype', 'COM_ALFA_SETUP_MENU_TARGET_LABEL', static fn (): array => MenuProvisioningService::menuOptions())
            ->provision(static fn (array $answers = []) => MenuProvisioningService::provisionStorefront(
                menutype: $answers['menutype'] ?? null,
                views:    MenuProvisioningService::REQUIRED,
            ));

        // Recommended, never required: the pages a shop works without but a CUSTOMER
        // does not. Same one-click fix: provisionStorefront() creates only what is
        // missing, so pressing it here cannot disturb what the merchant already made.
        $event->add('storefront.customer', 'COM_ALFA_SETUP_CHECK_CUSTOMER_PAGES_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_CUSTOMER_PAGES_REASON')
            ->cta('index.php?option=com_menus&view=items')
            ->tour(self::menuTourFor(self::customerViews()))
            ->priority(645)
            ->probe(static fn (): bool => MenuProvisioningService::missingViews(self::customerViews()) === [])
            ->choice('menutype', 'COM_ALFA_SETUP_MENU_TARGET_LABEL', static fn (): array => MenuProvisioningService::menuOptions())
            ->provision(static fn (array $answers = []) => MenuProvisioningService::provisionStorefront(
                menutype: $answers['menutype'] ?? null,
                views:    self::customerViews(),
            ));

        // Multilingual only: reachable in every language is half the contract: the
        // language switcher needs the copies ASSOCIATED or it drops the shopper on
        // the front page instead of the page they were reading. Silent on a
        // monolingual site (nothing to associate) and never destructive.
        $event->add('storefront.associations', 'COM_ALFA_SETUP_CHECK_MENU_ASSOC_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_MENU_ASSOC_REASON')
            ->cta('index.php?option=com_menus&view=items')
            ->tour(self::menuTourFor(array_keys(MenuProvisioningService::unassociatedViews()) ?: MenuProvisioningService::REQUIRED))
            ->priority(640)
            ->probe(static fn (): bool => MenuProvisioningService::unassociatedViews() === [])
            ->provision(static fn () => MenuProvisioningService::associateStorefront());

        $event->add('catalog.category', 'COM_ALFA_SETUP_CHECK_CATEGORY_LABEL')
            ->tier(SetupCheck::TIER_REQUIRED)
            ->reason('COM_ALFA_SETUP_CHECK_CATEGORY_REASON')
            ->cta('index.php?option=com_alfa&view=categories')
            ->tour('com_alfa.categories')
            ->priority(600)
            ->probe(static fn (): bool => self::exists(table: '#__alfa_categories', where: ['state' => 1]));

        $event->add('catalog.item', 'COM_ALFA_SETUP_CHECK_ITEM_LABEL')
            ->tier(SetupCheck::TIER_REQUIRED)
            ->reason('COM_ALFA_SETUP_CHECK_ITEM_REASON')
            ->cta('index.php?option=com_alfa&view=items')
            ->tour('com_alfa.product')
            ->priority(550)
            ->probe(static fn (): bool => self::exists(table: '#__alfa_items', where: ['state' => 1]));

        // Item fields drive the product form's own tabs AND variant combinations
        // (options are fields flagged to build them). A store that never touched
        // them gets a gentle nudge; one that STARTED defining fields but has none
        // published is mid-task: that gap blocks the product form, so it promotes
        // itself to required. Derived, never asked (no interviews).
        $anyFields = self::exists(table: '#__alfa_item_fields', where: []);
        $liveFields = self::exists(table: '#__alfa_item_fields', where: ['state' => 1]);

        $event->add('catalog.itemfields', 'COM_ALFA_SETUP_CHECK_ITEMFIELDS_LABEL')
            ->tier($anyFields && !$liveFields ? SetupCheck::TIER_REQUIRED : SetupCheck::TIER_OPTIONAL)
            ->reason('COM_ALFA_SETUP_CHECK_ITEMFIELDS_REASON')
            ->cta('index.php?option=com_alfa&view=itemfields')
            ->tour('com_alfa.item-fields')
            ->priority(540)
            ->probe(static fn (): bool => self::exists(table: '#__alfa_item_fields', where: ['state' => 1]));

        $event->add('docs.series', 'COM_ALFA_SETUP_CHECK_SERIES_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_SERIES_REASON')
            ->cta('index.php?option=com_alfa&view=documentseries')
            ->tour('com_alfa.document-series')
            ->priority(400)
            ->probe(static fn (): bool => self::exists(table: '#__alfa_document_series', where: ['state' => 1]));

        $event->add('mail.configured', 'COM_ALFA_SETUP_CHECK_MAIL_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_MAIL_REASON')
            ->cta('index.php?option=com_config')
            ->tour('com_alfa.joomla-basics')
            ->priority(350)
            ->probe(static function (): bool {
                $app = Factory::getApplication();

                return (int) $app->get('mailonline', 1) === 1
                    && trim((string) $app->get('mailfrom', '')) !== '';
            });

        $event->add('media.writable', 'COM_ALFA_SETUP_CHECK_MEDIA_LABEL')
            ->tier(SetupCheck::TIER_RECOMMENDED)
            ->reason('COM_ALFA_SETUP_CHECK_MEDIA_REASON')
            ->cta('index.php?option=com_config&view=component&component=com_alfa')
            ->tour('com_alfa.store-options')
            ->priority(300)
            ->probe(static function (): bool {
                $location = trim((string) ComponentHelper::getParams('com_alfa')->get('media_save_location', '/images/commerce'));
                $path = JPATH_ROOT . '/' . ltrim($location, '/');

                // A missing folder is fine when its parent can create it on first upload.
                return is_dir($path) ? is_writable($path) : is_writable(\dirname($path));
            });

        $event->add('sef.enabled', 'COM_ALFA_SETUP_CHECK_SEF_LABEL')
            ->tier(SetupCheck::TIER_OPTIONAL)
            ->reason('COM_ALFA_SETUP_CHECK_SEF_REASON')
            ->cta('index.php?option=com_config')
            ->tour('com_alfa.joomla-basics')
            ->priority(200)
            ->probe(static fn (): bool => (int) Factory::getApplication()->get('sef', 0) === 1);

        $event->add('https.enforced', 'COM_ALFA_SETUP_CHECK_HTTPS_LABEL')
            ->tier(SetupCheck::TIER_OPTIONAL)
            ->reason('COM_ALFA_SETUP_CHECK_HTTPS_REASON')
            ->cta('index.php?option=com_config')
            ->tour('com_alfa.joomla-basics')
            ->priority(150)
            ->probe(static function (): bool {
                return (int) Factory::getApplication()->get('force_ssl', 0) === 2
                    || str_starts_with(Uri::root(), 'https://');
            });
    }

    /**
     * Whether at least one row matches all equality conditions. A point read on
     * indexed columns: the probe shape every detector prefers.
     *
     * @param string $table Table name (with #__ prefix placeholder).
     * @param array<string, mixed> $where Column => value equality conditions.
     *
     *
     * @since  1.10.0
     */
    private static function exists(string $table, array $where): bool
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName($table));

        // bind() holds the value BY REFERENCE: binding the foreach variable makes
        // every placeholder point at the LAST condition's value (number=978 AND
        // state=1 silently became number=1 AND state=1). Each param gets its own
        // array slot so the references stay distinct.
        $bound = [];
        $index = 0;

        foreach ($where as $column => $value) {
            $param = ':w' . $index;
            $query->where($db->quoteName($column) . ' = ' . $param);

            if (\is_int($value)) {
                $bound[$index] = $value;
                $query->bind($param, $bound[$index], ParameterType::INTEGER);
            } else {
                $bound[$index] = (string) $value;
                $query->bind($param, $bound[$index], ParameterType::STRING);
            }

            $index++;
        }

        $db->setQuery($query, 0, 1);

        return (string) $db->loadResult() !== '';
    }

    /**
     * Whether the merchant added a published checkout field of their own
     * (shown in the delivery or invoice section) beyond the seeded identity rows.
     *
     *
     * @since  1.10.0
     */
    private static function hasMerchantCheckoutFields(): bool
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_form_fields'))
            ->where($db->quoteName('state') . ' = 1')
            // A row the product ships carries a role (locked or not); a row the
            // merchant authored carries none. Without this the seeded phone and
            // country would answer the question the merchant is being asked.
            ->where($db->quoteName('is_system') . ' = 0')
            ->where($db->quoteName('role') . ' IS NULL')
            ->where('(' . $db->quoteName('billing') . ' >= 1 OR ' . $db->quoteName('shipping') . ' >= 1)');

        $db->setQuery($query, 0, 1);

        return (string) $db->loadResult() !== '';
    }

    /**
     * Whether a PUBLISHED method row exists whose `type` names an ENABLED plugin of
     * the given capability group: the correctness join. `state = 1` alone lies: a
     * method whose gateway plugin was disabled still looks configured but cannot
     * process anything (the type contract already fails such methods closed at
     * runtime; the checklist must agree with it).
     *
     * @param string $table `#__alfa_payments` or `#__alfa_shipments`.
     * @param string $folder The plugin group the method's `type` boots into.
     *
     *
     * @since  1.10.0
     */
    private static function methodBackedByPlugin(string $table, string $folder): bool
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName($table, 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__extensions', 'e'),
                $db->quoteName('e.element') . ' = ' . $db->quoteName('m.type')
                . ' AND ' . $db->quoteName('e.type') . ' = ' . $db->quote('plugin')
                . ' AND ' . $db->quoteName('e.folder') . ' = :folder'
                . ' AND ' . $db->quoteName('e.enabled') . ' = 1',
            )
            ->where($db->quoteName('m.state') . ' = 1')
            ->bind(':folder', $folder, ParameterType::STRING);

        $db->setQuery($query, 0, 1);

        return (string) $db->loadResult() !== '';
    }

    /**
     * Whether the storefront's essential pages (shop, cart) are reachable; for a
     * multilingual site, in every published content language (an all-languages `*`
     * item covers each of them).
     *
     *
     * @since  1.10.0
     */
    private static function storefrontReachable(): bool
    {
        $languages = array_keys(SetupHelper::contentLanguages());

        foreach (MenuProvisioningService::REQUIRED as $view) {
            if ($languages === []) {
                if (!MenuProvisioningService::reachable(view: $view)) {
                    return false;
                }

                continue;
            }

            foreach ($languages as $language) {
                if (!MenuProvisioningService::reachable(view: $view, language: $language)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Which page a storefront tour should build, per view.
     *
     * @var array<string, string>
     *
     * @since  1.10.0
     */
    private const MENU_TOURS = [
        'items' => 'com_alfa.menu-shop',
        'cart' => 'com_alfa.menu-cart',
        'userinfo' => 'com_alfa.menu-account',
        'registration' => 'com_alfa.menu-register',
    ];

    /**
     * The tour to offer on a storefront row: the one that BUILDS the first page
     * still missing, because "show me" should start where the work is. A row whose
     * pages all exist keeps the first tour of its group as a launcher, and anything
     * unmapped falls back to the chooser, which explains the types without building.
     *
     * @param string[] $views The row's pages, most important first.
     *
     * @return string A guided-tour uid.
     *
     * @since  1.10.0
     */
    private static function menuTourFor(array $views): string
    {
        $views = array_values($views);

        if ($views === []) {
            return 'com_alfa.storefront-menu';
        }

        $missing = MenuProvisioningService::missingViews($views);

        foreach ($views as $view) {
            if (isset($missing[$view])) {
                return self::MENU_TOURS[$view] ?? 'com_alfa.storefront-menu';
            }
        }

        return self::MENU_TOURS[$views[0]] ?? 'com_alfa.storefront-menu';
    }

    /**
     * The storefront pages that are not required: the customer's own.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    private static function customerViews(): array
    {
        return array_values(array_diff(array_keys(MenuProvisioningService::STOREFRONT), MenuProvisioningService::REQUIRED));
    }

    /**
     * The component database driver.
     *
     *
     * @since  1.10.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
