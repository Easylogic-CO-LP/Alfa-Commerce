<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Place;

use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The place hierarchy, read once per process (one request on the web).
 *
 * A DESTINATION IS A LINEAGE, NOT AN ID. A cost zone named for a country has
 * to match an order going to a city inside it, and a tax rule written for a
 * region has to match its towns, so every question about "where is this
 * going" is answered with the place AND its ancestors, nearest first.
 *
 * WHY THIS IS THE COMPONENT'S JOB. Walking the tree needs the whole
 * parent map, and reading it is one query whose cost must be paid once and
 * shared. When that walk lived in a general-purpose helper, every caller that
 * reached for it loaded the table behind its own back, and a carrier plugin
 * doing so recomputed a chain the cart had already resolved for the very
 * destination it had just been filtered by. Ownership lives here so that the
 * cost is paid once and the plugin API never has to mention it.
 *
 * ELIGIBLE FOR PRICING (2.2.0). The same read carries each place's state, so
 * eligible() answers whether a place may price a visitor: it and every place
 * above it published. PriceContext asks it where a buyer's place enters the
 * goods' pricing, and ShippingTaxResolver for a shipment's destination; an
 * unpublished country, or a town inside one, prices as an unknown place, while
 * its price rows, discounts and taxes stay as they are.
 *
 * DISCOUNTS AND TAXES FOLLOW THE CHAIN (2.2.1). A discount or a tax scoped to
 * a place reaches every place inside it: the goods' rules (PriceDataLoader) and
 * the shipping taxes (ShippingTaxResolver) match the buyer's chain, and one
 * order decides which of them applies first (RulePrecedence). A product's price
 * rows stay exact to their place.
 *
 * HOW LONG THE MAPS LIVE. The parent and state maps are statics, read once per
 * PHP process: on the web that is one request, so a publish, an unpublish or a
 * new parent is seen by the next request. A process that prices in a loop (the
 * price index writer, run by the command-line task runner for up to 25 seconds)
 * forgets them before each chunk it prices (forget()), so it prices with the
 * tree as the database holds it then.
 *
 * @since  2.0.0
 */
final class PlaceTree
{
    /**
     * The place -> parent map, loaded on first use and kept for the process
     * (one request on the web).
     *
     * @var array<int, int>|null
     *
     * @since  2.0.0
     */
    private static ?array $parents = null;

    /**
     * The place -> state map, read with the parents.
     *
     * @var array<int, int>
     *
     * @since  2.2.0
     */
    private static array $states = [];

    /**
     * A place and the places it belongs to, nearest first.
     *
     * @param int $placeId The `#__alfa_places` id.
     *
     * @return int[] The place and its parents; empty for a zero or unknown id.
     *
     * @since  2.0.0
     */
    public static function placesFor(int $placeId): array
    {
        if ($placeId <= 0) {
            return [];
        }

        $parents = self::parents();
        $places = [];
        $current = $placeId;

        // GUARDED AGAINST A CYCLE, not because the schema permits one but
        // because a corrupted parent_id would otherwise hang the request
        // rather than mis-price it, and a hang is the worse failure.
        while ($current > 0 && isset($parents[$current]) && !\in_array($current, $places, true)) {
            $places[] = $current;
            $current = (int) $parents[$current];
        }

        return $places;
    }

    /**
     * Whether a place may price a visitor: it exists, and it and every place
     * above it are published. A town inside an unpublished country is not,
     * and neither is a place that no longer exists.
     *
     * @param int $placeId The `#__alfa_places` id.
     *
     * @return bool True when the place and its parents are published.
     *
     * @since  2.2.0
     */
    public static function eligible(int $placeId): bool
    {
        $places = self::placesFor(placeId: $placeId);

        if ($places === []) {
            return false;
        }

        foreach ($places as $place) {
            if ((self::$states[$place] ?? 0) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Forget the parent and state maps: the next question reads the tree again.
     *
     * For a process that prices in a loop, before each round: a place published,
     * unpublished or moved under another parent meanwhile is then seen.
     *
     * @since  2.2.1
     */
    public static function forget(): void
    {
        self::$parents = null;
        self::$states = [];
    }

    /**
     * The parent map, loaded once.
     *
     * @return array<int, int>
     *
     * @since  2.0.0
     */
    private static function parents(): array
    {
        if (self::$parents !== null) {
            return self::$parents;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // A place with no parent stores 0 or NULL; both read as 0 here, because
        // a NULL column would hand back the whole row as its parent. The state
        // rides on the same read (eligible()).
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    'COALESCE(' . $db->quoteName('parent_id') . ', 0) AS ' . $db->quoteName('parent_id'),
                    $db->quoteName('state'),
                ])
                ->from($db->quoteName('#__alfa_places')),
        )->loadAssocList('id') ?: [];

        self::$states = array_map(static fn (array $row): int => (int) $row['state'], $rows);

        return self::$parents = array_map(static fn (array $row): int => (int) $row['parent_id'], $rows);
    }
}
