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
use Alfa\Component\Alfa\Administrator\Model\LocationModel as AdminLocationModel;

/**
 * API Location model: the admin model plus resolved translations.
 *
 * Admin getItem() leaves translatable fields to the form layer (per-language
 * keys); an API item response needs them resolved in the request language.
 *
 * @since  1.9.0
 */
class LocationModel extends AdminLocationModel
{
    /**
     * Get the location with translatable fields resolved to the current language.
     *
     * @param int|null $pk The record id.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  1.9.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && !empty($item->id)) {
            foreach (['name'] as $field) {
                $item->$field = MultilingualHelper::getTranslatedValue(
                    id:                (int) $item->id,
                    tableName:         '#__alfa_inventory_locations',
                    primaryColumnName: 'id_inventory_location',
                    field:             $field,
                );
            }
        }

        if ($item && !empty($item->id)) {
            // Full per-language map ({"en_gb": {...}, "el_gr": {...}}) so API
            // clients can read every translation and PATCH back the same
            // per-language keys the admin form posts (name_el_gr, ...).
            $item->translations = MultilingualHelper::getMultilingualData(
                currentId:         (int) $item->id,
                primaryColumnName: 'id_inventory_location',
                tableName:         '#__alfa_inventory_locations',
            );
        }

        return $item;
    }
}
