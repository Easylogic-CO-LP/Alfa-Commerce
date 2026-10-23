<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * OrderItemsField: Configurable multi-select of order items
 *
 * A reusable field that shows order items filtered by a nullable FK column.
 * Configuration is entirely driven by XML attributes; the field itself
 * knows nothing about shipments, payments, or any specific entity.
 *
 * XML attributes:
 *   input_id        Which URL param holds the order ID     (default: "id_order")
 *   entity_id       Which URL param holds the entity PK    (default: "id")
 *   assign_column   The FK column on order_items to filter  (default: none = show all)
 *   assign_mode     "shipment" = availability comes from the shipment-items
 *                   join table and counts UNITS, not lines (default: none)
 *   show_quantity   Show "xN" after item name              (default: "true")
 *   show_sku        Show SKU in brackets after name        (default: "false")
 *
 * Usage examples:
 *
 *   <!-- Shipment: units still unshipped, plus whatever this parcel holds -->
 *   <field name="items" type="orderItems"
 *          input_id="id_order"
 *          entity_id="id"
 *          assign_mode="shipment"
 *          multiple="true"
 *          label="COM_ALFA_SHIPMENT_ITEMS" />
 *
 *   <!-- Plain FK: show items whose column is empty or points at this entity -->
 *   <field name="some_items" type="orderItems"
 *          input_id="id_order"
 *          entity_id="id"
 *          assign_column="id_some_entity"
 *          multiple="true"
 *          label="COM_ALFA_SOME_ITEMS" />
 *
 *   <!-- No filter: show ALL order items (e.g. invoice) -->
 *   <field name="invoice_items" type="orderItems"
 *          input_id="id_order"
 *          multiple="true"
 *          label="COM_ALFA_INVOICE_ITEMS" />
 *
 * @package    Alfa Commerce
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\Traits\HasFailClosedOptionsTrait;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\ShipmentLedger;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Throwable;

class OrderItemsField extends ListField
{
    use HasFailClosedOptionsTrait;

    protected $type = 'orderItems';

    /**
     * Build option list filtered by configurable FK column
     *
     * When assign_column is set:
     *   - Shows items where that column IS NULL or = 0 (available)
     *   - Shows items where that column = current entity ID (already assigned)
     *   - Hides items assigned to OTHER entities
     *
     * When assign_column is empty: shows ALL items for the order.
     *
     * Items that cannot be read are said on the form, and the field refuses the
     * save (HasFailClosedOptionsTrait): saving would take every item off the parcel.
     *
     * @return array Options for the select field
     * @since  1.0.0
     */
    protected function getOptions()
    {
        $options = parent::getOptions();
        $rows = $this->loadModelRows();

        if ($rows === null) {
            $this->reportOptionsNotLoaded();

            return $options;
        }

        return array_merge($options, $rows);
    }

    /**
     * The order's items as options, or null when they could not be read.
     *
     * @return array|null The options, empty when the order has none (or none is on offer).
     *
     * @since  2.1.0
     */
    protected function loadModelRows(): ?array
    {
        $options = [];

        $app = Factory::getApplication();
        $input = $app->input;

        // Read configuration from XML attributes
        $orderIdParam = $this->getAttribute('input_id', 'id_order');
        $entityIdParam = $this->getAttribute('entity_id', 'id');
        $assignColumn = $this->getAttribute('assign_column', '');
        $assignMode = $this->getAttribute('assign_mode', '');
        $showQuantity = $this->getAttribute('show_quantity', 'true') === 'true';
        $showSku = $this->getAttribute('show_sku', 'false') === 'true';

        $orderId = $input->getInt($orderIdParam, 0);
        $entityId = $input->getInt($entityIdParam, 0);

        if ($orderId <= 0) {
            return $options;
        }

        // Shipments count UNITS: a line stays on offer while any of it is
        // still unshipped, so the same line can be added to a second parcel.
        if ($assignMode === 'shipment') {
            return $this->getShipmentOptions($options, $orderId, $entityId, $showQuantity, $showSku);
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // Select columns needed for display
            $columns = ['id', 'name', 'quantity'];
            if ($showSku) {
                $columns[] = 'sku';
            }
            if (!empty($assignColumn)) {
                $columns[] = $db->qn($assignColumn);
            }

            $query = $db->getQuery(true)
                ->select($columns)
                ->from('#__alfa_order_items')
                ->where('id_order = ' . intval($orderId));

            // Apply FK filter when assign_column is configured
            if (!empty($assignColumn)) {
                $qnCol = $db->qn($assignColumn);

                if ($entityId > 0) {
                    // Edit: show unassigned + items in THIS entity
                    $query->where(
                        "({$qnCol} IS NULL OR {$qnCol} = 0 OR {$qnCol} = " . intval($entityId) . ')',
                    );
                } else {
                    // New: show only unassigned items
                    $query->where("({$qnCol} IS NULL OR {$qnCol} = 0)");
                }
            }
            // No assign_column -> no filter -> all items shown

            $query->order('id ASC');
            $db->setQuery($query);
            $items = $db->loadObjectList();

            foreach ($items as $item) {
                // Build display text
                $text = $item->name;
                if ($showSku && !empty($item->sku)) {
                    $text .= ' [' . $item->sku . ']';
                }
                if ($showQuantity) {
                    $text .= ' (×' . (int) $item->quantity . ')';
                }

                $options[] = (object) [
                    'value' => (int) $item->id,
                    'text' => $text,
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return $options;
    }

    /**
     * Options for a shipment parcel, counted in units.
     *
     * A line is on offer while ANY of it is still unshipped, so the same line
     * can go on a second parcel; that is what makes "3 now, 2 Monday"
     * expressible. When editing a parcel, the units it already holds are its
     * own and are added back on top of what is free.
     *
     * The "xN" is what is AVAILABLE, not what was ordered, so the admin can
     * see at a glance how much of the line is left.
     *
     * @param array $options Options built so far
     * @param int $orderId Order PK
     * @param int $shipmentId Parcel being edited (0 = new)
     * @param bool $showQuantity Append the available quantity
     * @param bool $showSku Append the SKU
     *
     * @return array|null Options for the select field; null when the items cannot be read (since 2.1.0)
     * @since  2.0.0
     */
    private function getShipmentOptions(
        array $options,
        int $orderId,
        int $shipmentId,
        bool $showQuantity,
        bool $showSku,
    ): ?array {
        try {
            // exceptShipmentId already sets THIS parcel's units aside, so what
            // comes back is "free elsewhere, plus whatever this parcel holds"
            // - do not add its own contents again, that double-counts them.
            $available = ShipmentLedger::remainingQuantities(
                orderId: $orderId,
                exceptShipmentId: $shipmentId,
            );

            if (empty($available)) {
                return $options;
            }

            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([$db->qn('id'), $db->qn('name'), $db->qn('sku')])
                ->from($db->qn('#__alfa_order_items'))
                ->where($db->qn('id_order') . ' = ' . (int) $orderId)
                ->whereIn($db->qn('id'), array_keys($available))
                ->order($db->qn('id') . ' ASC');

            $db->setQuery($query);

            foreach ($db->loadObjectList() ?: [] as $item) {
                $text = MultilingualHelper::resolveLangMap($item->name ?? '');

                if ($showSku && !empty($item->sku)) {
                    $text .= ' [' . $item->sku . ']';
                }

                if ($showQuantity) {
                    $text .= ' (×' . (int) $available[(int) $item->id] . ')';
                }

                $options[] = (object) [
                    'value' => (int) $item->id,
                    'text' => $text,
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return $options;
    }
}
