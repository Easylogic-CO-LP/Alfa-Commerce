<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Log;

use Joomla\CMS\Log\Log;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Every log category com_alfa writes to, registered in one place.
 *
 * Why this exists
 * Joomla log categories are FLAT strings matched by exact `in_array` (Log.php
 * :379/:384): no hierarchy, no prefixes. An UNREGISTERED category is silently
 * dropped: `addLogEntry()` iterates the (possibly empty) result of
 * findLoggers() with no fallback and no error. On a default install
 * (`log_everything = 0`) that meant most of this component's audit trail went
 * nowhere, including lines that say "reconcile manually".
 *
 * The fix is central registration, not fewer categories. Registering costs one
 * array write (Log.php:257-273); the logger object is instantiated lazily on
 * the first matching entry (Log.php:325) and the FILE is created inside
 * addEntry() (FormattedtextLogger.php:179). A category that never fires costs
 * nothing and creates no file. So there is no reason to make an operator lose
 * a distinction to save a registration.
 *
 * The two traps this map is shaped around
 * 1. The signature is `md5(serialize($options))` and CATEGORIES ARE NOT IN IT
 *    (Log.php:257). `lookup[$signature]` is then ASSIGNED, not merged
 *    (Log.php:269). So two categories registered to the SAME `text_file` in
 *    two calls produce one signature and the second call REPLACES the first's
 *    category list, silently dropping it. Hence: one destination file per
 *    category, or one call listing every category that shares a file.
 * 2. Conversely, the same category registered twice with DIFFERENT options
 *    yields two signatures, findLoggers() returns both, and every line is
 *    written TWICE. Hence the two plugin-owned registrations below are
 *    matched here byte-for-byte.
 *
 * What is not here
 * `Plugin::registerLogger()` (the alfa-* plugin base) registers derived
 * per-plugin categories (`com_alfa.payments.mygateway`,
 * `com_alfa.shipments.standard`), which are not knowable statically. It keeps
 * sole ownership of those.
 *
 * @since  2.0.0
 */
final class AlfaLog
{
    /**
     * category => log file.
     *
     * A DISTINCT file per category, deliberately: it sidesteps trap 1 above
     * entirely, and it means an operator chasing stock movements opens the
     * inventory log rather than grepping a shared one.
     *
     * The five marked EXISTING already have registrations and real history on
     * live installs. Their filenames are reproduced exactly: renaming a
     * destination orphans the file a merchant has been reading.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        // Item fields
        // Not namespaced under com_alfa. The names are kept as the code
        // writes them; renaming a category is a separate decision from
        // giving it somewhere to land.
        'alfa' => 'alfa.php',
        'alfa-item-fields' => 'alfa_item_fields.php',

        // Component
        // Bare 'com_alfa' cannot take com_alfa.php: that file is
        // com_alfa.multilingual's, with history in it.
        'com_alfa' => 'com_alfa_general.php',
        'com_alfa.admin' => 'com_alfa_admin.php',
        'com_alfa.multilingual' => 'com_alfa.php',              // EXISTING
        'com_alfa.panel' => 'com_alfa_panel.php',
        'com_alfa.tools' => 'com_alfa_tools.php',
        'com_alfa.media' => 'com_alfa_media.php',
        'com_alfa.setup' => 'com_alfa_setup.php',
        'com_alfa.cards' => 'com_alfa_cards.php',

        // Catalogue & pricing
        'com_alfa.catalog' => 'com_alfa_catalog.php',
        'com_alfa.prices' => 'com_alfa_prices.php',

        // NOT a duplicate of com_alfa.prices, and deliberately not merged into
        // it. VariantHelper writes item price-CONTRIBUTION failures here, and
        // this map's own rule is that a category is registered as the code
        // spells it - renaming one is a separate decision from giving it
        // somewhere to land. The near-collision is recorded, not resolved.
        'com_alfa.pricing' => 'com_alfa_pricing.php',
        'com_alfa.search' => 'com_alfa_search.php',
        'com_alfa.cart' => 'com_alfa_cart.php',
        'com_alfa.cartrules' => 'com_alfa_cartrules.php',

        // Orders & money
        'com_alfa.orders' => 'com_alfa_orders.php',       // EXISTING
        'com_alfa.orderstatus' => 'com_alfa_orderstatus.php',
        'com_alfa.orderemail' => 'com_alfa_orderemail.php',
        'com_alfa.payments' => 'alfa_payments.php',         // EXISTING
        'com_alfa.credit' => 'com_alfa.credit.log.php',   // EXISTING
        'com_alfa.documents' => 'com_alfa_documents.php',
        'com_alfa.inventory' => 'com_alfa_inventory.php',
        'com_alfa.downloads' => 'com_alfa_downloads.php',

        // Plugin-owned categories
        // These two ALSO register themselves, because their requests can
        // run without com_alfa booting at all (a system plugin's event, a
        // com_ajax endpoint). Registered here as well so the same
        // categories still land when the request DOES boot com_alfa,and
        // with byte-identical options, so trap 2 cannot bite: same
        // signature, same categories, idempotent. An optional plugin that is
        // not listed here registers its own category when it boots.
        'alfa_sync' => 'alfa_sync.php',             // EXISTING
        'plg_alfa_subscriptions' => 'plg_alfa_subscriptions.log.php',

        // Other extensions in the family
        'plg_alfa-search_enterprise' => 'plg_alfa_search_enterprise.php',
        'mod_alfa_search' => 'mod_alfa_search.php',
    ];

    /**
     * Register every category. Idempotent, and safe to call from anywhere.
     *
     * Called from AlfaComponent::boot(), which runs once per com_alfa request
     * in BOTH clients: the single provider at services/provider.php is booted
     * from JPATH_ADMINISTRATOR for site and admin alike.
     *
     *
     * @since  2.0.0
     */
    public static function register(): void
    {
        // NO `static $registered` GUARD, deliberately. Such a guard asserts
        // "registered once, registered for the rest of the request", and
        // Log::setInstance() falsifies it: the registry is replaced while the
        // guard still says done, so every later category silently goes nowhere
        // - the exact failure this class exists to end. Re-registering is
        // already idempotent (identical options => identical signature => the
        // same lookup slot is reassigned, Log.php:257/:269), so the guard
        // bought a few microseconds at the cost of a silent hole.
        //
        // Cost when called repeatedly: 28 x (serialize + md5) of a two-key
        // array, ~50us total, on a path that runs a handful of times per
        // request. No query, no file, no I/O.
        foreach (self::CATEGORIES as $category => $file) {
            // One call per category = one signature per category. Options are
            // exactly ['text_file' => ...] and nothing else, which is what the
            // two plugin-owned registrations use; matching them is what
            // makes the double registration collapse instead of doubling.
            Log::addLogger(['text_file' => $file], Log::ALL, [$category]);
        }
    }

    /**
     * The category => file map, for probes and diagnostics.
     *
     * @return array<string, string>
     *
     * @since  2.0.0
     */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }
}
