<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use RuntimeException;
use Throwable;

/**
 * A structural change was refused because stock units it would remove, or the
 * item's own stock, still hold stock: a stock record not at zero, reserved
 * quantity, an open reservation, a pending stock effect, or an open order line
 * that still relies on the unit to deduct its stock.
 *
 * Every record is judged on its own, so +3 at one location and -3 at another is
 * not clear. Nothing is written or written off when this is thrown; the operator
 * resolves the stock, or fulfils or cancels those orders, explicitly first.
 *
 * getMessage() is the English text for logs; openLinesMessage() is the translated
 * sentence naming the relying order lines for a screen.
 *
 * @since  2.0.0
 */
final class InventoryUnitRetirementException extends RuntimeException
{
    /** @var int Stock records whose quantity or reserved quantity is not zero. */
    public readonly int $rowsNotZero;

    /** @var float On-hand quantity over the blocked units. */
    public readonly float $onHand;

    /** @var float Reserved quantity over the blocked units. */
    public readonly float $reserved;

    /** @var int Open reservations naming the blocked units. */
    public readonly int $activeReservations;

    /** @var int Pending stock effects of orders holding a line on the blocked units. */
    public readonly int $pendingEffects;

    /**
     * @var array Open order lines that still rely on the blocked units, over all of them:
     *            order_id, line_id, ordered, outstanding and combination_id (the unit).
     */
    public readonly array $openLines;

    /**
     * @param int $itemId The item.
     * @param array $blockers One entry per blocked unit: combination_id, rows_not_zero,
     *                        on_hand, reserved, active_reservations, pending_effects,
     *                        rows (stock_id, location_id, quantity, reserved) and
     *                        open_lines (order_id, line_id, ordered, outstanding).
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly array $blockers,
    ) {
        $rows = 0;
        $onHand = 0.0;
        $reserved = 0.0;
        $active = 0;
        $pending = 0;
        $openLines = [];

        foreach ($blockers as $blocker) {
            $rows += (int) ($blocker['rows_not_zero'] ?? 0);
            $onHand += (float) ($blocker['on_hand'] ?? 0);
            $reserved += (float) ($blocker['reserved'] ?? 0);
            $active += (int) ($blocker['active_reservations'] ?? 0);
            $pending += (int) ($blocker['pending_effects'] ?? 0);

            foreach ($blocker['open_lines'] ?? [] as $line) {
                $openLines[] = [
                    'order_id' => (int) ($line['order_id'] ?? 0),
                    'line_id' => (int) ($line['line_id'] ?? 0),
                    'ordered' => (float) ($line['ordered'] ?? 0),
                    'outstanding' => (float) ($line['outstanding'] ?? 0),
                    'combination_id' => (int) ($blocker['combination_id'] ?? 0),
                ];
            }
        }

        $this->rowsNotZero = $rows;
        $this->onHand = $onHand;
        $this->reserved = $reserved;
        $this->activeReservations = $active;
        $this->pendingEffects = $pending;
        $this->openLines = $openLines;

        parent::__construct(sprintf(
            'Inventory: item #%d still holds stock: %d stock records not at zero, %s reserved,'
            . ' %d open reservations, %d pending stock effects, %d open order lines still relying on it.',
            $itemId,
            $rows,
            InventoryAvailabilityService::formatQuantity(value: $reserved),
            $active,
            $pending,
            \count($openLines),
        ));
    }

    /**
     * The combination ids of the blocked units (0 for the item's own stock).
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public function combinationIds(): array
    {
        return array_map(static fn (array $blocker): int => (int) $blocker['combination_id'], $this->blockers);
    }

    /**
     * The translated sentence naming the open order lines that still rely on the
     * blocked units, one sentence per such unit; null when no line relies on them.
     *
     *
     * @since  2.0.0
     */
    public function openLinesMessage(): ?string
    {
        return self::openLinesText(itemId: $this->itemId, blockers: $this->blockers);
    }

    /**
     * The translated sentence naming the open order lines that still rely on the
     * blocked units of an item, for any list of blockers shaped like the kernel's
     * retirement blockers: per unit with relying lines, the unit's label, how many
     * lines, and each order, line and outstanding quantity. Null when no blocker
     * carries a relying line.
     *
     * The administrator strings are loaded here when the running application has not
     * loaded them.
     *
     * @param int $itemId The item.
     * @param array $blockers Its blockers: combination_id and open_lines (order_id,
     *                        line_id, ordered, outstanding).
     *
     *
     * @since  2.0.0
     */
    public static function openLinesText(int $itemId, array $blockers): ?string
    {
        $sentences = [];

        foreach ($blockers as $blocker) {
            $lines = $blocker['open_lines'] ?? [];

            if ($lines === []) {
                continue;
            }

            if ($sentences === []) {
                self::loadAdministratorStrings();
            }

            $sentences[] = Text::sprintf(
                'COM_ALFA_INVENTORY_UNIT_OPEN_LINES',
                InventoryUnitException::labelFor(itemId: $itemId, combinationId: (int) ($blocker['combination_id'] ?? 0)),
                \count($lines),
                implode(', ', array_map(
                    static fn (array $line): string => Text::sprintf(
                        'COM_ALFA_INVENTORY_AUDIT_OPEN_LINE_ITEM',
                        (int) ($line['order_id'] ?? 0),
                        (int) ($line['line_id'] ?? 0),
                        InventoryAvailabilityService::formatQuantity(value: (float) ($line['outstanding'] ?? 0)),
                    ),
                    $lines,
                )),
            );
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    /**
     * Load the component's administrator strings into the application language when
     * they are not there yet. A failure is logged; the message then shows its keys.
     *
     *
     * @since  2.0.0
     */
    private static function loadAdministratorStrings(): void
    {
        try {
            $language = Factory::getApplication()->getLanguage();

            if (!$language->hasKey(string: 'COM_ALFA_INVENTORY_UNIT_OPEN_LINES')) {
                $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'Stock retirement refusal: the administrator strings could not be loaded: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }
}
