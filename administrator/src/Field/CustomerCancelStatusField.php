<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

/**
 * The statuses a customer's cancel may move an order to: the published statuses of the cancelled family, by their
 * internal name, in the order staff set. With validate="options" a saved value must be one of them.
 *
 * @since  2.3.0
 */
class CustomerCancelStatusField extends ListField
{
    /**
     * The form field type.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'CustomerCancelStatus';

    /**
     * The XML's own options first (the empty choice), then each status that can take a customer's cancel.
     *
     * @return object[]
     *
     * @since  2.3.0
     */
    protected function getOptions()
    {
        $options = parent::getOptions();

        foreach (OrderStatusHelper::getAll() as $status) {
            if ((int) ($status->state ?? 0) !== 1 || (int) ($status->is_cancelled ?? 0) !== 1) {
                continue;
            }

            $name = MultilingualHelper::getTranslatedValue(
                id: (int) $status->id,
                tableName: '#__alfa_orders_statuses',
                primaryColumnName: 'id_orderstatus',
                field: 'name',
            );
            $options[] = HTMLHelper::_('select.option', (string) $status->id, $name !== '' ? $name : '#' . (int) $status->id);
        }

        return $options;
    }
}
