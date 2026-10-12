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
use Alfa\Component\Alfa\Administrator\Model\FormfieldgroupModel as AdminFormfieldgroupModel;

/**
 * API Formfieldgroup model: the admin model plus resolved translations.
 *
 * Admin getItem() leaves translatable fields to the form layer (per-language
 * keys); an API item response needs them resolved in the request language.
 *
 * @since  1.9.0
 */
class FormfieldgroupModel extends AdminFormfieldgroupModel
{
    /**
     * Get the item with translatable fields resolved to the current language.
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
            foreach (['title', 'description'] as $field) {
                $item->$field = MultilingualHelper::getTranslatedValue(
                    id:                (int) $item->id,
                    tableName:         '#__alfa_form_field_groups',
                    primaryColumnName: 'id_form_field_group',
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
                primaryColumnName: 'id_form_field_group',
                tableName:         '#__alfa_form_field_groups',
            );
        }

        return $item;
    }
}
