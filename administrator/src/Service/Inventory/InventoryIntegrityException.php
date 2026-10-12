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
 * A stock command refused on a unit whose stock figures claim quantity the stock
 * records do not hold: the unit is stock-managed, has no stock record at all, and
 * its stored stock or available figure is non-zero (either sign); or the unit is
 * held ($held) because a return or cancellation recorded stock on it while it was
 * in that state, which gave it records but did not repair its figures.
 *
 * Nothing is written when this is thrown. The unit stays refused until its figures
 * are repaired through the audited inventory repair; the kernel never adopts the
 * stale figure as an opening balance.
 *
 * getMessage() is the English text for logs; operatorMessage() is the translated
 * sentence for a screen, built by refusalText(), which also words the same refusal
 * when a dry run of a stock change reports it.
 *
 * @since  1.14.0
 */
final class InventoryIntegrityException extends RuntimeException
{
    /**
     * @param int $itemId The item of the refused unit.
     * @param int $combinationId The combination of the refused unit, 0 for the item unit.
     * @param float|null $stock The stored stock figure (for a held unit: the one it
     *                          showed when the hold began).
     * @param float|null $available The stored available figure (likewise).
     * @param bool $held The unit has stock records but is held until its
     *                   figures are repaired.
     *
     * @since  1.14.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly int $combinationId,
        public readonly ?float $stock,
        public readonly ?float $available,
        public readonly bool $held,
    ) {
        parent::__construct(sprintf(
            $held
                ? 'Inventory: item #%d%s is held: stock was recorded on it while it showed stock %s / available %s with no stock records;'
                    . ' its stock cannot move until its figures are repaired through the inventory audit.'
                : 'Inventory: item #%d%s is stock-managed and shows stock %s / available %s but has no stock records;'
                    . ' its stock cannot move until its figures are repaired through the inventory audit.',
            $itemId,
            $combinationId > 0 ? ' combination #' . $combinationId : '',
            self::figure(value: $stock),
            self::figure(value: $available),
        ));
    }

    /**
     * Render a stored figure for the message; an empty figure reads as such.
     *
     * @param float|null $value The stored figure.
     *
     *
     * @since  1.14.0
     */
    private static function figure(?float $value): string
    {
        return $value === null ? 'empty' : InventoryAvailabilityService::formatQuantity(value: $value);
    }

    /**
     * The refusal as the operator reads it, in the current language, with the figure
     * the unit shows (or, for a held unit, showed) without stock records behind it
     * ({@see self::refusalText()}, which loads the administrator strings when the
     * running application has not: plugin screens and other extensions' requests
     * report these refusals too).
     *
     *
     * @since  1.14.0
     */
    public function operatorMessage(): string
    {
        return self::refusalText(stock: $this->stock, available: $this->available, held: $this->held);
    }

    /**
     * The refusal of a unit in this state as the operator reads it, in the current
     * language: the one text builder for this refusal, whether a command threw it or
     * a dry run reported it. It names the figure that claims the stock: the stock
     * figure, or the available figure when the stock figure shows none.
     *
     * The administrator strings are loaded here when the running application has not
     * loaded them.
     *
     * @param float|null $stock The stored stock figure (for a held unit: the one it
     *                          showed when the hold began).
     * @param float|null $available The stored available figure (likewise).
     * @param bool $held The unit has stock records but is held until its
     *                   figures are repaired.
     *
     *
     * @since  1.14.0
     */
    public static function refusalText(?float $stock, ?float $available, bool $held): string
    {
        self::loadAdministratorStrings();

        return Text::sprintf(
            $held ? 'COM_ALFA_INVENTORY_HELD_REFUSED' : 'COM_ALFA_INVENTORY_LEDGER_MISSING_REFUSED',
            InventoryAvailabilityService::formatQuantity(value: self::shownFigure(stock: $stock, available: $available)),
        );
    }

    /**
     * The figure that claims the stock: the stored stock figure, or the stored
     * available figure when the stock figure shows none.
     *
     * @param float|null $stock The stored stock figure.
     * @param float|null $available The stored available figure.
     *
     *
     * @since  1.14.0
     */
    private static function shownFigure(?float $stock, ?float $available): float
    {
        return InventoryAvailabilityService::projectionNonZero(stock: $stock, available: null)
            ? (float) $stock
            : (float) ($available ?? 0.0);
    }

    /**
     * Load the component's administrator strings into the application language when
     * they are not there yet. A failure is logged; the message then shows its key.
     *
     *
     * @since  1.14.0
     */
    private static function loadAdministratorStrings(): void
    {
        try {
            $language = Factory::getApplication()->getLanguage();

            if (!$language->hasKey(string: 'COM_ALFA_INVENTORY_LEDGER_MISSING_REFUSED')) {
                $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'Stock integrity refusal: the administrator strings could not be loaded: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }
}
