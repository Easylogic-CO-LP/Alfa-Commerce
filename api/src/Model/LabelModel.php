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
use Alfa\Component\Alfa\Administrator\Model\LabelModel as AdminLabelModel;
use Alfa\Component\Alfa\Api\Helper\ApiInclude;

/**
 * API Label model: the admin model plus the name resolved in the request language, and every language's name when the
 * read asks for it (include=translations). A write posts the name per language as the admin form does (name_en_gb).
 *
 * @since  2.3.0
 */
class LabelModel extends AdminLabelModel
{
    /**
     * Get the label with its name resolved to the current language.
     *
     * @param int|null $pk The record id.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since  2.3.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && !empty($item->id)) {
            $item->name = MultilingualHelper::getTranslatedValue(
                id:                (int) $item->id,
                tableName:         '#__alfa_labels',
                primaryColumnName: 'id_label',
                field:             'name',
            );

            if (ApiInclude::asks(ApiInclude::TRANSLATIONS)) {
                $item->translations = MultilingualHelper::getMultilingualData(
                    currentId:         (int) $item->id,
                    primaryColumnName: 'id_label',
                    tableName:         '#__alfa_labels',
                );
            }
        }

        return $item;
    }
}
