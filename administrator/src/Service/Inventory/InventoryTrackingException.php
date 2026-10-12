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
 * A stock-tracking switch was refused before anything was written.
 *
 * Reasons:
 *   reserved          switching off while open reservations hold the unit's stock
 *                     ($count = how many);
 *   open_lines        switching off while open order lines placed as stock claims
 *                     still rely on a unit going off ($count = those lines, $orders =
 *                     the orders they belong to);
 *   opening_required  switching on without an explicit quantity on hand for every
 *                     location that needs one;
 *   variants_inherit  switching the product on while combinations inherit its setting
 *                     ($count = how many);
 *   target            an opening quantity the unit cannot take ($code = the
 *                     validateTargets() code, $locationId = the location).
 *
 * getMessage() is the English text for logs. The open_lines refusal has a
 * translated sentence for a screen, built by openLinesText(), which also words the
 * same refusal when a check made before a save reports it.
 *
 * @since  1.14.0
 */
final class InventoryTrackingException extends RuntimeException
{
    public const RESERVED = 'reserved';
    public const OPEN_LINES = 'open_lines';
    public const OPENING_REQUIRED = 'opening_required';
    public const VARIANTS_INHERIT = 'variants_inherit';
    public const TARGET = 'target';

    /**
     * The validateTargets() code of a TARGET refusal. Named apart from the
     * exception's own integer code, which it must not shadow.
     *
     * @since  1.14.0
     */
    public readonly ?string $targetCode;

    /**
     * @param int $itemId The item.
     * @param int|null $combinationId The combination switched, or null for the item.
     * @param string $reason One of the class constants.
     * @param int $count Reservations, relying order lines or inheriting
     *                   combinations counted.
     * @param string|null $code The target refusal code, for TARGET (exposed as $targetCode).
     * @param int|null $locationId The refused location, for TARGET.
     * @param int $orders The orders the relying lines belong to, for OPEN_LINES.
     *
     * @since  1.14.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly ?int $combinationId,
        public readonly string $reason,
        public readonly int $count,
        ?string $code,
        public readonly ?int $locationId = null,
        public readonly int $orders = 0,
    ) {
        $this->targetCode = $code;

        $unit = ($combinationId ?? 0) > 0
            ? sprintf('combination #%d of item #%d', $combinationId, $itemId)
            : sprintf('item #%d', $itemId);

        parent::__construct(match ($reason) {
            self::RESERVED => sprintf(
                'Inventory: stock tracking of %s cannot be switched off while %d open reservations hold its stock.',
                $unit,
                $count,
            ),
            self::OPEN_LINES => sprintf(
                'Inventory: stock tracking of %s cannot be switched off while %d open order lines of %d orders placed as stock claims still rely on it.',
                $unit,
                $count,
                $orders,
            ),
            self::OPENING_REQUIRED => sprintf(
                'Inventory: stock tracking of %s is being switched on without the quantity on hand for every location.',
                $unit,
            ),
            self::VARIANTS_INHERIT => sprintf(
                'Inventory: stock tracking of %s cannot be switched on while %d combinations inherit it.',
                $unit,
                $count,
            ),
            default => sprintf(
                'Inventory: the opening quantity of %s at location #%d cannot be applied (%s).',
                $unit,
                (int) $locationId,
                (string) $code,
            ),
        });
    }

    /**
     * The open_lines refusal as the operator reads it, in the current language: the
     * one text builder for it, whether the switch threw it or a check made before a
     * save reported it. It names how many open order lines, of how many orders,
     * still rely on the stock. Two numbers and no name: nothing in it needs escaping.
     *
     * The administrator strings are loaded here when the running application has not
     * loaded them.
     *
     * @param int $lines The open order lines placed as stock claims that still rely on a unit going off.
     * @param int $orders The orders those lines belong to.
     *
     *
     * @since  1.14.0
     */
    public static function openLinesText(int $lines, int $orders): string
    {
        self::loadAdministratorStrings();

        return Text::sprintf('COM_ALFA_INVENTORY_TRACKING_OFF_OPEN_LINES', $lines, $orders);
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

            if (!$language->hasKey(string: 'COM_ALFA_INVENTORY_TRACKING_OFF_OPEN_LINES')) {
                $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'Stock tracking refusal: the administrator strings could not be loaded: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }
}
