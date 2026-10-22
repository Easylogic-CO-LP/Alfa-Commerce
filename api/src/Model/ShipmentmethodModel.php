<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Model\ShipmentModel as AdminShipmentModel;
use Exception;
use Joomla\CMS\Table\Table;

/**
 * API shipment method model: the admin model plus the full translation map.
 *
 * Admin getItem() already resolves the translated name; the API item
 * response additionally carries every language for round-trippable PATCH.
 *
 * @since  2.0.0
 */
class ShipmentmethodModel extends AdminShipmentModel
{
    /**
     * The shipping method's table: the administrator's Shipment table.
     *
     * Joomla names the table after the model, and no table is called Shipmentmethod: without this
     * every read of one method and every write (a method created, changed or deleted) failed with
     * "Table shipmentmethod not supported".
     *
     * @param string $name The table name.
     * @param string $prefix The table class prefix.
     * @param array $options Configuration array for the table.
     *
     * @return Table
     *
     * @throws Exception When the table cannot be created.
     *
     * @since  2.1.0
     */
    public function getTable($name = 'Shipment', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    /**
     * Get the item with the per-language translation map attached.
     *
     * @param int|null $pk The record id.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  2.0.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && !empty($item->id)) {
            // Full per-language map ({"en_gb": {...}, "el_gr": {...}}) so API
            // clients can read every translation and PATCH back the same
            // per-language keys the admin form posts (name_el_gr, ...).
            $item->translations = MultilingualHelper::getMultilingualData(
                currentId:         (int) $item->id,
                primaryColumnName: 'id_shipment',
                tableName:         '#__alfa_shipments',
            );
        }

        return $item;
    }
}
