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
 * The place hierarchy, read once per request.
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
 * @since  2.0.0
 */
final class PlaceTree
{
    /**
     * The place -> parent map, loaded on first use and kept for the request.
     *
     * @var array<int, int>|null
     *
     * @since  2.0.0
     */
    private static ?array $parents = null;

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
        // a NULL column would hand back the whole row as its parent.
        return self::$parents = $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    'COALESCE(' . $db->quoteName('parent_id') . ', 0) AS ' . $db->quoteName('parent_id'),
                ])
                ->from($db->quoteName('#__alfa_places')),
        )->loadAssocList('id', 'parent_id') ?: [];
    }
}
