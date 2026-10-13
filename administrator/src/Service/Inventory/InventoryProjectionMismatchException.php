<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

use RuntimeException;

\defined('_JEXEC') or die;

/**
 * A structural inventory change was refused because the item's stored stock
 * figures disagree with what its stock records say.
 *
 * The figures are repaired only through the audited inventory repair; nothing is
 * written when this is thrown.
 *
 * @since  2.0.0
 */
final class InventoryProjectionMismatchException extends RuntimeException
{
    /**
     * @param int $itemId The item.
     * @param array $rows One entry per disagreeing figure row: table, id, stock,
     *                    stock_target, available, available_target.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly array $rows,
    ) {
        $parts = [];

        foreach ($rows as $row) {
            $parts[] = sprintf(
                '%s #%d stock %s (records %s), available %s (records %s)',
                str_contains((string) $row['table'], 'combinations') ? 'combination' : 'item',
                (int) $row['id'],
                self::figure(value: $row['stock']),
                self::figure(value: $row['stock_target']),
                self::figure(value: $row['available']),
                self::figure(value: $row['available_target']),
            );
        }

        parent::__construct(sprintf(
            'Inventory: the stock figures of item #%d disagree with its stock records (%s); repair them through the inventory audit first.',
            $itemId,
            implode('; ', $parts),
        ));
    }

    /**
     * Render a figure for the message; an empty figure reads as such.
     *
     * @param mixed $value The figure.
     *
     *
     * @since  2.0.0
     */
    private static function figure(mixed $value): string
    {
        return $value === null ? 'empty' : InventoryAvailabilityService::formatQuantity(value: (float) $value);
    }
}
