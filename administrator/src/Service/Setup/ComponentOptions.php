<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\PageCache;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use RuntimeException;
use Throwable;

/**
 * The component Options of Alfa Commerce as an installation writes them: the one list of their
 * defaults, and the one writer the installation steps use.
 *
 * The installer seeds these on every install and update (missing keys only), Clear everything
 * writes them as they are, and Reset to default saves them through Joomla's own Options save. A
 * default is changed here and nowhere else.
 *
 * Keys follow the `name` of the fields in config.xml, and every field that has a default is listed
 * with that default, so a shop that never saved its Options stores what the Options form shows. A
 * few keys have no field and are engine state (setup_completed, setup_version) or are read by
 * Joomla's versioning (save_history, history_limit). An Option that is not listed here is a field
 * with no default (the layout choices, the stock messages): the code that reads it falls back to
 * its own default. One field is stored differently from its form default on purpose: media_mime's
 * form default is empty, which means the default image types (MediaHelper::resolveAllowedMimes()),
 * and the installation stores those types as its list.
 *
 * The subset in SyncHelper::PRICES_DISPLAY_KEYS also builds the price display of each customer
 * group (SyncHelper::buildDefaultPricesDisplay()).
 *
 * @since  2.1.0
 */
final class ComponentOptions
{
    /**
     * The Options an installation writes.
     *
     * Multi-select values are plain PHP arrays: they are stored as real JSON arrays, the shape the
     * Options form saves, never as a string-encoded copy.
     *
     * @var array<string, scalar|array<string>>
     *
     * @since  2.1.0
     */
    public const DEFAULTS = [
        // General / catalog
        'include_subcategories' => 1,
        'category_filter_subtree' => 0,
        'show_items_filters' => 0,
        'show_items_field_facets' => 1,
        'show_manufacturers_filters' => 0,

        // Listings (floor of the category -> menu -> global chain). items_limits
        // deliberately EMPTY: the storefront keeps its standard page sizes, and
        // "All" stays unavailable until a merchant adds a 0 entry themselves.
        'items_limit' => 25,
        'items_limits' => '',
        'items_ordering' => 'a.id DESC',
        // The category's title and description above its listing (shown unless a level says No).
        'show_category_title' => 1,
        'show_category_description' => 1,
        // A filtered listing: the subcategory strip left out, the filters folded.
        'hide_categories_when_filtered' => 1,
        'collapse_items_filters_when_filtered' => 1,

        // The product page: how many related products it shows (0 = none), where the product, its category and the
        // menu item set no count of their own.
        'item_related_count' => -1,

        // Pricing
        'combination_adjust_target' => 'final',

        // Stock
        'stock_action' => 0,
        'stock_low' => 5,
        'backorder' => 'deny',
        'negative_physical' => 0,
        'over_shipment' => 'strict',
        'min_qty' => 0,

        // Notifications
        'notifications_history_days' => 7,

        // Cart: guest checkout and the "added to cart" message.
        'checkout_guest' => 1,
        'cart_added' => 1,
        'cart_added_pos' => 'top-right',
        'cart_added_offset_vertical' => 16,
        'cart_added_offset_vertical_unit' => 'px',
        'cart_added_offset_horizontal' => 16,
        'cart_added_offset_horizontal_unit' => 'px',
        'cart_added_effect' => 'slide',
        'cart_added_timeout' => 6,

        // Setup checklist / first-run wizard. Only store_name / store_email have
        // config.xml fields; the rest is engine state (deliberately no field,
        // like save_history). Guided tours are seeded from tours/*.json in
        // postflight (TourSeeder: data rows in core tables, no sql/updates).
        'store_name' => '',
        'store_email' => '',
        'setup_completed' => 0,
        'setup_version' => '',

        // Search: the engine ('' = the built-in one) and its indexing.
        'search_engine' => '',
        'search_index_aliases' => 1,
        'search_index_manufacturers' => 1,
        'search_index_categories' => 0,
        'search_index_short_desc' => 0,
        'search_index_full_desc' => 0,
        'search_index_meta_text' => 0,

        // Documents
        'documents_issuer' => 'com_alfa',

        // Downloads: the store's folder ('' = downloads inside the site, which the installation
        // provisions and records) and its direct-download protection check, on.
        'downloads_storage_path' => '',
        'downloads_protection_check' => 1,

        // Price display. SyncHelper::PRICES_DISPLAY_KEYS builds the price display
        // of each customer group from these keys.
        'base_price_show' => 0,
        'base_price_show_label' => 0,
        'discount_amount_show' => 0,
        'discount_amount_show_label' => 0,
        'base_price_with_discounts_show' => 0,
        'base_price_with_discounts_show_label' => 0,
        'tax_amount_show' => 0,
        'tax_amount_show_label' => 0,
        'base_price_with_tax_show' => 0,
        'base_price_with_tax_show_label' => 0,
        'final_price_show' => 1,
        'final_price_show_label' => 1,
        'price_breakdown_show' => 0,

        // Online sales: sell online. Catalogue only is a shop's own choice; an install, Clear
        // everything and Reset to default write Sell online.
        'catalogue_only' => 0,

        // Quantities of measured units: as many decimals as the value needs.
        'unit_display_precision' => 'auto',

        // Currency: the ISO-4217 NUMBER (the `number` column, the Options field's
        // key_field, and what Pricing\Currency / OrderCreator read). NEVER the row id.
        'default_currency' => 978,  // EUR

        // Media (main-image format/quality/dimensions live in the optimizer plugin)
        // The thumbnail box: 500 x 500; a site that stores its own keeps it, but the update raises a side
        // stored below 500 to 500 (ThumbnailBoxUpdate) and MediaHelper::thumbnailBox() reads a side as 100 to 5000.
        'media_thumbnail_width' => 500,
        'media_thumbnail_height' => 500,
        'media_save_location' => '/images/commerce',
        'media_placeholder' => 'media/com_alfa/images/placeholders/no-image.webp',
        'media_placeholder_items' => 1,
        'media_placeholder_categories' => 1,
        'media_placeholder_manufacturers' => 1,
        'media_url_thumbnail' => 'media/com_alfa/images/placeholders/url.webp',
        'media_full_deletion' => 1,
        // The images a listing card shows (0 = all) and the product page gallery's columns.
        'items_card_images_count' => 0,
        'item_images_grid_columns' => 2,
        'media_mime' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'image/webp',
            'image/avif',
        ],

        // History: read by Joomla CORE versioning (VersionableModelTrait /
        // Versioning), not by com_alfa code; deliberately no config.xml field.
        'save_history' => 0,
        'history_limit' => 5,

        // Cache
        'enable_cache' => 1,
        'cache_categories' => 1,
    ];

    /**
     * Keys kept in the Options that are records, not settings: Reset to default and Clear
     * everything carry them over unchanged. `setup_created_menu_ids` names the menu items the setup
     * created (Setup\MenuProvisioningService), which stay Joomla rows: once the record is lost, which
     * of them Alfa made can no longer be told.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const CARRIED = ['setup_created_menu_ids'];

    /**
     * The records among stored Options that a reset carries over ({@see self::CARRIED}), as stored.
     *
     * @param array<string, mixed> $stored The Options as stored.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    public static function carried(array $stored): array
    {
        return array_intersect_key($stored, array_flip(self::CARRIED));
    }

    /**
     * The Options as stored, read from the extension row (not from the request's cached copy,
     * which an earlier write of the same request may have made stale).
     *
     * @param DatabaseInterface|null $db The connection; the container's by default.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    public static function stored(?DatabaseInterface $db = null): array
    {
        $db ??= Factory::getContainer()->get(DatabaseInterface::class);

        $stored = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component')),
        )->loadResult();

        $params = !empty($stored) ? json_decode((string) $stored, true) : [];

        return \is_array($params) ? $params : [];
    }

    /**
     * Stored Options with every default they lack added: existing values always win, so an
     * update never changes a choice the shop made.
     *
     * @param array<string, mixed> $stored The Options as stored.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    public static function withDefaults(array $stored): array
    {
        return array_merge(self::DEFAULTS, $stored);
    }

    /**
     * Write the Options of the extension row as given, in one statement, then drop Joomla's cached
     * copy of the component records in both clients and give this request the new values.
     *
     * The installation steps' writer: an install, an update and Clear everything write through
     * here. A shop's own Options save goes through Joomla's Options save, and so does Reset to
     * default.
     *
     * Array values nest as real JSON arrays through the single json_encode below, the shape the
     * Options form writes. They must never be pre-encoded into strings: Registry->get() would
     * return the string, and MediaHelper::resolveAllowedMimes() would read it as one bogus MIME.
     *
     * @param array<string, mixed> $params The Options to store.
     * @param DatabaseInterface|null $db The connection; the container's by default.
     *
     * @throws RuntimeException When the row cannot be written.
     *
     * @since  2.1.0
     */
    public static function write(array $params, ?DatabaseInterface $db = null): void
    {
        $db ??= Factory::getContainer()->get(DatabaseInterface::class);
        $before = self::stored(db: $db);
        $json = json_encode($params);

        if ($json === false) {
            throw new RuntimeException('The Options of Alfa Commerce could not be encoded: ' . json_last_error_msg());
        }

        $db->setQuery(
            $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote($json))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component')),
        )->execute();

        self::forgetCachedRecords();
        self::followOnlineSales(before: $before, after: $params);

        try {
            ComponentHelper::getComponent('com_alfa')->setParams(new Registry($params));
        } catch (Throwable) {
            // A request that never loaded the component records has nothing to refresh.
        }
    }

    /**
     * The one rule for online sales switched by a write of the Options: when Online sales changed between the
     * two, the pages Joomla's page cache stored are dropped ({@see PageCache::forget()}), so no stored guest page keeps
     * prices and buy buttons the shop no longer shows, or the reverse. Every path that writes the Options asks it: write()
     * (an install, an update, Clear everything), Reset to default, and a shop's own Options save, which Joomla writes
     * itself, not through write() (System - Alfa Commerce Sync asks it after that save).
     *
     * @param array<string, mixed> $before The Options before the write.
     * @param array<string, mixed> $after The Options written.
     *
     * @since  2.3.0
     */
    public static function followOnlineSales(array $before, array $after): void
    {
        $sellsOnline = static fn (array $params): bool => (int) ($params['catalogue_only'] ?? 0) !== 1;

        if ($sellsOnline($before) !== $sellsOnline($after)) {
            PageCache::forget();
        }
    }

    /**
     * Drop Joomla's cached component records (the `_system` cache group), so the next request of the site or the
     * administrator reads the row as it is now. Both keep them in Joomla's cache folder (the Global Configuration's
     * cache path, administrator/cache when none is set). Joomla's own Options save cleans them itself; every other write
     * of the Options asks this.
     *
     * @since  2.1.0
     */
    public static function forgetCachedRecords(): void
    {
        try {
            Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => '_system'])
                ->clean();
        } catch (Throwable) {
            // A cache that cannot be cleaned expires on its own; the row is right.
        }
    }
}
