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
use Alfa\Component\Alfa\Administrator\Model\PaymentModel as AdminPaymentModel;

/**
 * API payment method model: the admin model plus the full translation map.
 *
 * Admin getItem() already resolves the translated name; the API item
 * response additionally carries every language for round-trippable PATCH.
 *
 * @since  1.9.0
 */
class PaymentmethodModel extends AdminPaymentModel
{
    /**
     * Get the item with the per-language translation map attached.
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
            // Full per-language map ({"en_gb": {...}, "el_gr": {...}}) so API
            // clients can read every translation and PATCH back the same
            // per-language keys the admin form posts (name_el_gr, ...).
            $item->translations = MultilingualHelper::getMultilingualData(
                currentId:         (int) $item->id,
                primaryColumnName: 'id_payment',
                tableName:         '#__alfa_payments',
            );
        }

        return $item;
    }
}
