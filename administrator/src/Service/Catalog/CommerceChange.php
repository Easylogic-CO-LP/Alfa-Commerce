<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * When what a product sells for or has to sell last changed: #__alfa_items.commerce_changed_at, a UTC time to the
 * microsecond, moved by every write of a product's stock, its price rows, a price period that begins and the product's
 * own save, each in the same transaction as the write. A stock move does not touch the product's modified, so an
 * integrator that keeps stock and prices in step reads the products changed since its last read with
 * filter[changed_since] instead of every product.
 *
 * The writers: InventoryService (the statement that moves a product's stock figures, and a move into or out of the
 * archive), the product's save, a change of a product's state from the list (ItemModel::publish()), the combination's
 * own save (its prices) and its removal (CombinationService::remove()), the REST API's units batch (UnitBatch, a unit's
 * prices), and the price index's announcement of the periods that begin.
 *
 * @api READ ONLY: the time a product's stock or prices last changed, and the reading of a changed_since bound.
 *
 * @since  2.2.2
 */
final class CommerceChange
{
    /**
     * The column.
     *
     * @since  2.2.2
     */
    public const COLUMN = 'commerce_changed_at';

    /**
     * Now, in UTC to the microsecond, as the column stores it.
     *
     * @since  2.2.2
     */
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /**
     * Mark products changed now, in the caller's transaction.
     *
     * @param DatabaseInterface $db The connection of the write.
     * @param int[] $itemIds The products.
     *
     * @since  2.2.2
     */
    public static function mark(DatabaseInterface $db, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($itemIds === []) {
            return;
        }

        $now = self::now();
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__alfa_items'))
                ->set($db->quoteName(self::COLUMN) . ' = :now')
                ->whereIn($db->quoteName('id'), $itemIds)
                ->bind(':now', $now, ParameterType::STRING),
        )->execute();
    }

    /**
     * A changed_since bound as the column compares it: UTC to the microsecond. A bound that states no zone is a UTC
     * time, as every date the REST API publishes; one with Z or an offset is read in its own zone. A day alone is that
     * day's start.
     *
     * @param string $bound The bound as sent.
     *
     * @return string|null The bound, or null when it is not a date and time.
     *
     * @since  2.2.2
     */
    public static function bound(string $bound): ?string
    {
        $bound = trim($bound);

        if ($bound === '' || preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $bound) !== 1) {
            return null;
        }

        try {
            return (new DateTimeImmutable($bound, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (Exception) {
            return null;
        }
    }
}
