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
 * Stock held on a stock identity kept for order history was not written off
 * because open order lines still rely on that identity to deduct it.
 *
 * Such a line was placed as a stock claim and its fulfilment acts on exactly that
 * identity's stock records (the product itself of a product that now has
 * combinations, an archived combination, or any identity of an archived product).
 * Nothing is written when this is thrown: the operator fulfils or cancels those
 * orders first.
 *
 * getMessage() is the English text for logs; operatorMessage() is the translated
 * sentence for a screen.
 *
 * @since  2.0.0
 */
final class InventoryOpenLinesException extends RuntimeException
{
    /**
     * @param int $itemId The product.
     * @param int $combinationId The combination, 0 for the product itself.
     * @param array $lines One entry per relying line: order_id, line_id, ordered, outstanding.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly int $combinationId,
        public readonly array $lines,
    ) {
        $named = array_map(
            static fn (array $line): string => sprintf(
                'order #%d line #%d (%s to deduct)',
                (int) ($line['order_id'] ?? 0),
                (int) ($line['line_id'] ?? 0),
                InventoryAvailabilityService::formatQuantity(value: (float) ($line['outstanding'] ?? 0)),
            ),
            $lines,
        );

        parent::__construct(sprintf(
            'Inventory: the stock held on %s of item #%d cannot be written off while %d open order lines still rely on it: %s.',
            $combinationId > 0 ? 'combination #' . $combinationId : 'the product itself',
            $itemId,
            \count($lines),
            implode(', ', $named),
        ));
    }

    /**
     * The refusal as the operator reads it, in the current language: the stock
     * identity by its product's name (and its variant's label), and every order
     * line that still relies on it with the quantity it has left to deduct.
     *
     *
     * @since  2.0.0
     */
    public function operatorMessage(): string
    {
        return (string) InventoryUnitRetirementException::openLinesText(
            itemId: $this->itemId,
            blockers: [['combination_id' => $this->combinationId, 'open_lines' => $this->lines]],
        );
    }
}
