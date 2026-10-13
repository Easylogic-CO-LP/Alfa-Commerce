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
use Alfa\Component\Alfa\Administrator\Model\OrderstatusModel as AdminOrderstatusModel;

/**
 * API Orderstatus model: the admin model plus resolved translations.
 *
 * Admin getItem() leaves translatable fields to the form layer (per-language
 * keys); an API item response needs them resolved in the request language.
 *
 * @since  2.0.0
 */
class OrderstatusModel extends AdminOrderstatusModel
{
    /**
     * Get the item with translatable fields resolved to the current language.
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
            foreach (['name', 'name_customer'] as $field) {
                $item->$field = MultilingualHelper::getTranslatedValue(
                    id:                (int) $item->id,
                    tableName:         '#__alfa_orders_statuses',
                    primaryColumnName: 'id_orderstatus',
                    field:             $field,
                );
            }
        }

        if ($item && !empty($item->id)) {
            // Per-language map pruned to the status labels: the lang rows also
            // carry email subject/body templates: internal automation config
            // that must not ride along on API responses.
            $item->translations = array_map(
                static fn (array $row): array => array_intersect_key($row, array_flip(['name', 'name_customer'])),
                MultilingualHelper::getMultilingualData(
                    currentId:         (int) $item->id,
                    primaryColumnName: 'id_orderstatus',
                    tableName:         '#__alfa_orders_statuses',
                ),
            );
        }

        return $item;
    }
}
