<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The visitor's recently viewed products: a session-held list of item ids, most
 * recent first, written by the product page and read by the items module.
 *
 * Session-scoped like the pricing context: it follows the browsing session and
 * holds nothing but ids. Visibility is never decided here: whoever displays the
 * list hands the ids to the listing model, which applies the catalogue's access,
 * publish and stock rules as for any other listing.
 *
 * @since  2.0.0
 */
final class RecentlyViewed
{
    /**
     * @var string Session key holding the id list.
     * @since  2.0.0
     */
    public const SESSION_KEY = 'alfa.recently_viewed';

    /**
     * @var int How many ids the list keeps; the oldest drop off.
     * @since  2.0.0
     */
    public const MAX = 24;

    /**
     * Record a product view: the id moves to the front of the list.
     *
     * @param int $itemId The viewed item's id.
     *
     *
     * @since  2.0.0
     */
    public static function record(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        $ids = array_values(array_diff(self::ids(), [$itemId]));
        array_unshift($ids, $itemId);

        Factory::getApplication()->getSession()->set(self::SESSION_KEY, \array_slice($ids, 0, self::MAX));
    }

    /**
     * The recorded ids, most recent first.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public static function ids(): array
    {
        $ids = Factory::getApplication()->getSession()->get(self::SESSION_KEY, []);

        return array_values(array_filter(array_map('intval', (array) $ids)));
    }
}
