<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * A price row of a deleted place is never widened to every place by a save (2.2.1).
 *
 * A place can be deleted while a price row still names it (PlaceModel::delete() strips the rules' scopes, not the
 * price rows). Such a row is inert: no buyer's place matches it. The prices editor lists the deleted place, so a form
 * saved unchanged writes the row back as it was; a save that would set the row to every place (place 0) is refused
 * here, naming the row, since that would give one country's price to every buyer.
 *
 * @since  2.2.1
 */
final class PriceRowPlaces
{
    /**
     * The posted rows that would turn the price of a deleted place into a price for every place.
     *
     * @param int $itemId The item the rows belong to.
     * @param int|null $combinationId The combination, or null for the item's own rows.
     * @param array $rows The posted rows (arrays or objects with id and country_id).
     *
     * @return list<array{row: int, place: int}> Each refused row and the deleted place it names.
     *
     * @since  2.2.1
     */
    public static function widenedFromDeleted(int $itemId, ?int $combinationId, array $rows): array
    {
        $posted = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (int) ($row['id'] ?? 0);

            if ($id > 0 && (int) ($row['country_id'] ?? 0) === 0) {
                $posted[] = $id;
            }
        }

        if ($posted === [] || $itemId <= 0) {
            return [];
        }

        /** @var DatabaseInterface $db */
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName(['p.id', 'p.country_id']))
            ->from($db->quoteName('#__alfa_items_prices', 'p'))
            ->join('LEFT', $db->quoteName('#__alfa_places', 'pl'), $db->quoteName('pl.id') . ' = ' . $db->quoteName('p.country_id'))
            ->whereIn($db->quoteName('p.id'), $posted)
            ->where($db->quoteName('p.item_id') . ' = :item')
            ->where($db->quoteName('p.country_id') . ' > 0')
            ->where($db->quoteName('pl.id') . ' IS NULL')
            ->bind(':item', $itemId, ParameterType::INTEGER)
            ->order($db->quoteName('p.id'));

        if ($combinationId === null) {
            $query->where($db->quoteName('p.combination_id') . ' IS NULL');
        } else {
            $query->where($db->quoteName('p.combination_id') . ' = :combination')->bind(':combination', $combinationId, ParameterType::INTEGER);
        }

        return array_map(
            static fn (array $found): array => ['row' => (int) $found['id'], 'place' => (int) $found['country_id']],
            $db->setQuery($query)->loadAssocList() ?: [],
        );
    }

    /**
     * The refusal of those rows, one sentence each.
     *
     * @param list<array{row: int, place: int}> $widened From widenedFromDeleted().
     *
     * @since  2.2.1
     */
    public static function refusal(array $widened): string
    {
        return implode(' ', array_map(
            static fn (array $refused): string => Text::sprintf('COM_ALFA_PRICE_PLACE_DELETED_WIDENED', $refused['row'], $refused['place']),
            $widened,
        ));
    }
}
