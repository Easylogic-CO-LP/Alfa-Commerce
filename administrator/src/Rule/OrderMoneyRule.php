<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * A typed amount of an order's money, as typed, or a refusal that names it.
 *
 * The order screen's payment and parcel popups name it on their money fields
 * (validate="orderMoney"). The posted figure is read by {@see ExactMoney} in
 * the currency of the order the popup posts (id_order), at the scale that
 * order keeps. A figure with more decimal places than that money holds is
 * refused in plain words naming the field, the currency and its decimals, and
 * nothing reaches the ledger; a figure it holds reaches the ledger as typed and
 * is written on its own digits. The decimal mark is a point: the ledger reads
 * the posted text itself. The field's sign attribute states what the record
 * takes: sign="positive" (a payment: more than zero) or sign="nonnegative"
 * (a parcel's cost: zero or more).
 *
 * @since  1.14.0
 */
class OrderMoneyRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The posted value.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data; its id_order names the order.
     * @param Form|null $form The form.
     *
     * @return bool True when the order's money holds the value as typed.
     *
     * @throws UnexpectedValueException With the operator's words, naming the field and the figure: finer than
     *                                  the order's money, not an amount, or of a sign the record does not take.
     *
     * @since  1.14.0
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // Emptiness is the required= attribute's business, not this rule's.
        if ($value === null || $value === '') {
            return true;
        }

        $label = (string) $element['label'];
        $field = Text::_($label !== '' ? $label : (string) $element['name']);
        $currency = OrderCurrency::of(orderId: (int) ($input?->get('id_order') ?? 0));

        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            throw new UnexpectedValueException((new ExactMoneyRefusal(reason: ExactMoney::NOT_A_NUMBER, typed: \gettype($value), currency: $currency))->words(field: $field));
        }

        try {
            $amount = ExactMoney::of(value: $value, currency: $currency);
        } catch (ExactMoneyRefusal $e) {
            throw new UnexpectedValueException($e->words(field: $field), 0, $e);
        }

        return match (true) {
            (string) $element['sign'] === 'positive' && !$amount->isPositive() => throw new UnexpectedValueException(Text::sprintf('COM_ALFA_MONEY_INPUT_NOT_POSITIVE', $field, (string) $value)),
            (string) $element['sign'] === 'nonnegative' && $amount->isNegative() => throw new UnexpectedValueException(Text::sprintf('COM_ALFA_MONEY_INPUT_NEGATIVE', $field, (string) $value)),
            default => true,
        };
    }
}
