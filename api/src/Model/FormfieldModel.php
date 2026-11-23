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
use Alfa\Component\Alfa\Administrator\Model\FormfieldModel as AdminFormfieldModel;
use Alfa\Component\Alfa\Api\Helper\ApiInclude;

/**
 * API Formfield model: the admin model plus resolved translations.
 *
 * Admin getItem() leaves translatable fields to the form layer (per-language
 * keys); an API item response needs them resolved in the request language.
 *
 * @since  2.0.0
 */
class FormfieldModel extends AdminFormfieldModel
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
            foreach (['name', 'field_label', 'field_description'] as $field) {
                $item->$field = MultilingualHelper::getTranslatedValue(
                    id:                (int) $item->id,
                    tableName:         '#__alfa_form_fields',
                    primaryColumnName: 'id_formfield',
                    field:             $field,
                );
            }
        }

        if ($item && !empty($item->id)) {
            // Full per-language map ({"en_gb": {...}, "el_gr": {...}}) so API
            // clients can read every translation and PATCH back the same
            // per-language keys the admin form posts (name_el_gr, ...).
            // Every content language's texts, only when the read asks for them (include=translations).
            if (ApiInclude::asks(ApiInclude::TRANSLATIONS)) {
                $item->translations = MultilingualHelper::getMultilingualData(
                    currentId:         (int) $item->id,
                    primaryColumnName: 'id_formfield',
                    tableName:         '#__alfa_form_fields',
                );
            }

            // The field type's settings, as the form builds the field from them: a choice's options (each a
            // value and its text), min_selections and max_selections, a phone's default_region. A text stored per
            // language reads in the request's language, as the storefront form reads it (FormFieldsPlugin::prepareDom()).
            // Read only: the field's settings are written on its edit screen.
            $item->params = MultilingualHelper::collapseToCurrent(data: \is_array($item->params ?? null) ? $item->params : []);
        }

        return $item;
    }
}
