<?php

/**
 * OrderItemsField — Configurable multi-select of order items
 *
 * A reusable field that shows order items filtered by a nullable FK column.
 * Configuration is entirely driven by XML attributes — the field itself
 * knows nothing about shipments, payments, or any specific entity.
 *
 * XML attributes:
 *   input_id        Which URL param holds the order ID     (default: "id_order")
 *   entity_id       Which URL param holds the entity PK    (default: "id")
 *   assign_column   The FK column on order_items to filter  (default: none = show all)
 *   show_quantity   Show "×N" after item name              (default: "true")
 *   show_sku        Show SKU in brackets after name        (default: "false")
 *
 * Usage examples:
 *
 *   <!-- Shipment: filter by id_order_shipment -->
 *   <field name="items" type="orderItems"
 *          input_id="id_order"
 *          entity_id="id"
 *          assign_column="id_order_shipment"
 *          multiple="true"
 *          label="COM_ALFA_SHIPMENT_ITEMS" />
 *
 *   <!-- Refund: filter by id_refund -->
 *   <field name="refund_items" type="orderItems"
 *          input_id="id_order"
 *          entity_id="id"
 *          assign_column="id_refund"
 *          multiple="true"
 *          label="COM_ALFA_REFUND_ITEMS" />
 *
 *   <!-- No filter: show ALL order items (e.g. invoice) -->
 *   <field name="invoice_items" type="orderItems"
 *          input_id="id_order"
 *          multiple="true"
 *          label="COM_ALFA_INVOICE_ITEMS" />
 *
 * @package    Com_Alfa
 * @subpackage Administrator
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

class OrderItemsField extends ListField
{
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
     * @return array Options for the select field
     */
    protected function getOptions()
    {
        $options = parent::getOptions();

        $app = Factory::getApplication();
        $input = $app->input;

        // Read configuration from XML attributes
        $orderIdParam = $this->getAttribute('input_id', 'id_order');
        $entityIdParam = $this->getAttribute('entity_id', 'id');
        $assignColumn = $this->getAttribute('assign_column', '');
        $showQuantity = $this->getAttribute('show_quantity', 'true') === 'true';
        $showSku = $this->getAttribute('show_sku', 'false') === 'true';

        $orderId = $input->getInt($orderIdParam, 0);
        $entityId = $input->getInt($entityIdParam, 0);

        if ($orderId <= 0) {
            return $options;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // Select columns needed for display
            $columns = ['id', 'name', 'quantity'];
            if ($showSku) {
                $columns[] = 'reference';
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
            // No assign_column → no filter → all items shown

            $query->order('id ASC');
            $db->setQuery($query);
            $items = $db->loadObjectList();

            foreach ($items as $item) {
                // Build display text
                $text = $item->name;
                if ($showSku && !empty($item->reference)) {
                    $text .= ' [' . $item->reference . ']';
                }
                if ($showQuantity) {
                    $text .= ' (×' . (int) $item->quantity . ')';
                }

                $options[] = (object) [
                    'value' => (int) $item->id,
                    'text' => $text,
                ];
            }
        } catch (Exception $e) {
            // Silently fail — field shows no options
        }

        return $options;
    }
}
