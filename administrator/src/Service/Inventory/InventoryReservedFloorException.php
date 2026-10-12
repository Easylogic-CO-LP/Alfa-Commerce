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
 * A stock command that would lower a stock record's quantity on hand below what
 * active reservations hold on that record.
 *
 * The reserved quantity of a record (item, combination, location) is a claim on
 * that record's stock: while it stands, the record's on-hand may not go below it,
 * and a record already below it (stock reserved beyond the shelf) may not go lower
 * at all. The smallest on-hand the record may keep is therefore the lower of its
 * on-hand now and its reserved quantity ({@see self::minimumFor()}). Nothing is
 * written when this is thrown; the operator resolves the reservations first (by
 * lowering or cancelling the order lines that hold them), then records the stock.
 *
 * A fulfilment that would ship the stock backing reservations ahead of the ones it
 * converts, in the order in which a record's reservations are served
 * ({@see ReservationQueue}), is refused the same way: then the reserved quantity is
 * what the reservations ahead hold, and $ahead names their sources.
 *
 * getMessage() is the English text for logs; operatorMessage() is the translated
 * sentence for a screen, built by refusalText(), which also words the same refusal
 * when a dry run of a stock change reports it, or, for reservations ahead, by
 * claimOrderText().
 *
 * @since  1.14.0
 */
final class InventoryReservedFloorException extends RuntimeException
{
    /**
     * @param int $itemId The item of the refused record.
     * @param int $combinationId The combination of the refused record, 0 for the item unit.
     * @param int $locationId The location of the refused record.
     * @param float $onHand The record's quantity on hand now.
     * @param float $reserved The quantity active reservations hold on the record; for a
     *                        fulfilment refused for reservations ahead, what those hold.
     * @param float $requested The on-hand the command would have left.
     * @param float $minimum The smallest on-hand the record may keep while those
     *                       reservations stand.
     * @param string $command The refused command, for the log.
     * @param array $ahead For a fulfilment refused for reservations ahead of its own: their
     *                     sources, in the order they are served, each {source_type, source_id};
     *                     empty for every other refusal.
     *
     * @since  1.14.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly int $combinationId,
        public readonly int $locationId,
        public readonly float $onHand,
        public readonly float $reserved,
        public readonly float $requested,
        public readonly float $minimum,
        public readonly string $command,
        public readonly array $ahead = [],
    ) {
        $sources = implode(separator: ', ', array: array_map(
            callback: static fn (array $source): string => $source['source_type'] . ' #' . (int) $source['source_id'],
            array: $ahead,
        ));

        parent::__construct(sprintf(
            'Inventory: %s of item #%d at location #%d holds %s on hand with %s reserved by '
                . ($ahead === [] ? 'active reservations' : 'earlier reservations (' . $sources . ') served before this one')
                . '; %s would leave %s, below the %s those reservations need. Nothing was changed.',
            $combinationId > 0 ? 'combination #' . $combinationId : 'the product itself',
            $itemId,
            $locationId,
            InventoryAvailabilityService::formatQuantity(value: $onHand),
            InventoryAvailabilityService::formatQuantity(value: $reserved),
            $command,
            InventoryAvailabilityService::formatQuantity(value: $requested),
            InventoryAvailabilityService::formatQuantity(value: $minimum),
        ));
    }

    /**
     * The smallest on-hand a record may keep while its reservations stand: its
     * reserved quantity, or its on-hand now when that is already below it.
     *
     * @param float $onHand The record's quantity on hand now.
     * @param float $reserved The quantity active reservations hold on the record.
     *
     *
     * @since  1.14.0
     */
    public static function minimumFor(float $onHand, float $reserved): float
    {
        return min($onHand, $reserved);
    }

    /**
     * The refusal as the operator reads it, in the current language
     * ({@see self::refusalText()}).
     *
     *
     * @since  1.14.0
     */
    public function operatorMessage(): string
    {
        if ($this->ahead !== []) {
            return self::claimOrderText(
                itemId: $this->itemId,
                combinationId: $this->combinationId,
                locationId: $this->locationId,
                onHand: $this->onHand,
                reserved: $this->reserved,
                requested: $this->requested,
                ahead: $this->ahead,
            );
        }

        return self::refusalText(
            itemId: $this->itemId,
            combinationId: $this->combinationId,
            locationId: $this->locationId,
            onHand: $this->onHand,
            reserved: $this->reserved,
            requested: $this->requested,
        );
    }

    /**
     * The refusal of a stock change below a record's reservations as the operator
     * reads it, in the current language: the one text builder for this refusal,
     * whether a command threw it or a dry run reported it. It names the unit, the
     * location, the reserved quantity, the smallest on-hand allowed, the on-hand now
     * and the figure asked for. The text is raw; a caller that renders it as HTML
     * escapes it.
     *
     * The unit's names and the location's title are read, read-only, when the text is
     * asked for. The administrator strings are loaded here when the running
     * application has not loaded them.
     *
     * @param int $itemId The item of the record.
     * @param int $combinationId The combination of the record, 0 for the item unit.
     * @param int $locationId The location of the record.
     * @param float $onHand The record's quantity on hand now.
     * @param float $reserved The quantity active reservations hold on the record.
     * @param float $requested The on-hand asked for.
     *
     *
     * @since  1.14.0
     */
    public static function refusalText(
        int $itemId,
        int $combinationId,
        int $locationId,
        float $onHand,
        float $reserved,
        float $requested,
    ): string {
        self::loadAdministratorStrings();

        return Text::sprintf(
            'COM_ALFA_INVENTORY_RESERVED_FLOOR_REFUSED',
            InventoryUnitException::labelFor(itemId: $itemId, combinationId: $combinationId),
            self::locationTitle(locationId: $locationId),
            InventoryAvailabilityService::formatQuantity(value: $reserved),
            InventoryAvailabilityService::formatQuantity(value: self::minimumFor(onHand: $onHand, reserved: $reserved)),
            InventoryAvailabilityService::formatQuantity(value: $onHand),
            InventoryAvailabilityService::formatQuantity(value: $requested),
        );
    }

    /**
     * The refusal of a shipment that would take the stock backing reservations
     * served before its own, as the operator reads it, in the current language. It
     * names the unit, the location, the sources of those reservations, what they
     * hold there, the smallest on-hand they allow, the on-hand now and the figure
     * the shipment would leave, and says how to go on: ship those first, lower or
     * cancel them, or have an administrator serve this order first on the inventory
     * audit page. The text is raw; a caller that renders it as HTML escapes it.
     *
     * @param int $itemId The item of the record.
     * @param int $combinationId The combination of the record, 0 for the item unit.
     * @param int $locationId The location of the record.
     * @param float $onHand The record's quantity on hand now.
     * @param float $reserved What the reservations ahead hold on the record.
     * @param float $requested The on-hand the shipment would leave.
     * @param array $ahead Their sources, each {source_type, source_id}.
     *
     *
     * @since  1.14.0
     */
    public static function claimOrderText(
        int $itemId,
        int $combinationId,
        int $locationId,
        float $onHand,
        float $reserved,
        float $requested,
        array $ahead,
    ): string {
        self::loadAdministratorStrings();

        $sources = implode(separator: ', ', array: array_map(
            callback: static fn (array $source): string => (string) $source['source_type'] === 'order'
                ? Text::sprintf('COM_ALFA_INVENTORY_CLAIM_AHEAD_ORDER', (int) $source['source_id'])
                : Text::sprintf('COM_ALFA_INVENTORY_CLAIM_AHEAD_SOURCE', (string) $source['source_type'], (int) $source['source_id']),
            array: $ahead,
        ));

        return Text::sprintf(
            'COM_ALFA_INVENTORY_CLAIM_ORDER_REFUSED',
            InventoryUnitException::labelFor(itemId: $itemId, combinationId: $combinationId),
            self::locationTitle(locationId: $locationId),
            $sources,
            InventoryAvailabilityService::formatQuantity(value: $reserved),
            InventoryAvailabilityService::formatQuantity(value: self::minimumFor(onHand: $onHand, reserved: $reserved)),
            InventoryAvailabilityService::formatQuantity(value: $onHand),
            InventoryAvailabilityService::formatQuantity(value: $requested),
        );
    }

    /**
     * The location's title, read-only; its id stands in when the location is gone
     * or carries no title.
     *
     * @param int $locationId The location.
     *
     *
     * @since  1.14.0
     */
    private static function locationTitle(int $locationId): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $title = (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('title'))
                ->from($db->quoteName('#__alfa_inventory_locations'))
                ->where($db->quoteName('id') . ' = ' . $locationId),
        )->loadResult();

        return trim($title) !== '' ? $title : '#' . $locationId;
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

            if (!$language->hasKey(string: 'COM_ALFA_INVENTORY_RESERVED_FLOOR_REFUSED')) {
                $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'Stock reservation floor refusal: the administrator strings could not be loaded: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }
}
