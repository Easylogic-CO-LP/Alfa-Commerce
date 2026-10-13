<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Joomla\CMS\Form\Field\NumberField;

/**
 * An amount of an order's money: a number input that steps by the order's smallest unit.
 *
 * The order is the one the form holds (id_order), as validate="orderMoney"
 * ({@see \Alfa\Component\Alfa\Administrator\Rule\OrderMoneyRule}) reads it, and
 * its currency is taken at the scale the order keeps: the step is 1 at no
 * decimal places, 0.01 at two, 0.001 at three. The browser then takes every
 * exact amount of that money; the rule refuses a finer one on the server.
 *
 * The value is shown as the exact text it is bound with (form/field/ordermoney,
 * Joomla's number input without its float cast): a float prints fourteen
 * digits, and a Save that changed nothing else would write back what it showed.
 *
 * @since  2.0.0
 */
class OrderMoneyField extends NumberField
{
    /**
     * The form field type.
     *
     * @var string
     *
     * @since  2.0.0
     */
    protected $type = 'OrderMoney';

    /**
     * The layout: the number input, its value as text.
     *
     * @var string
     *
     * @since  2.0.0
     */
    protected $layout = 'form.field.ordermoney';

    /**
     * Where the layout is found: the component's layouts first, then the default paths.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    protected function getLayoutPaths()
    {
        return array_merge([JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'], parent::getLayoutPaths());
    }

    /**
     * The number input's data, its step the order's smallest unit.
     *
     * @return array
     *
     * @since  2.0.0
     */
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();
        $scale = OrderCurrency::of(orderId: (int) $this->form->getValue('id_order'))->getDecimalPlaces();

        $data['step'] = $scale === 0 ? '1' : '0.' . str_repeat('0', $scale - 1) . '1';

        return $data;
    }
}
