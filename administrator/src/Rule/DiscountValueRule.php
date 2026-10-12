<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * Validates one discount value against the discount contract.
 *
 * ONE COLUMN, TWO MEANINGS. `#__alfa_discounts.value` holds a percentage or a
 * currency amount depending on `is_amount`, so a plain max="100" on the field
 * would also cap fixed amounts at 100 currency units. Only the percentage has an
 * upper bound; both have a floor of zero, because a discount reduces
 * consideration and is never a surcharge.
 *
 * A form RULE, not model code: the admin form controller and the REST controller
 * both reach the value through Form::validate(), so one rule covers both writers
 * with one message. {@see \Alfa\Component\Alfa\Administrator\Table\DiscountTable::check()}
 * says the same thing for a writer that never reaches a form, and the pricing
 * engine keeps its own defensive clamp for values that never pass a save at all.
 *
 * @since  1.10.0
 */
class DiscountValueRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The posted value.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data.
     * @param Form|null $form The form.
     *
     * @return bool True when the value is inside the contract.
     *
     * @throws UnexpectedValueException With a translated message the operator reads.
     *
     * @since  1.10.0
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // Emptiness is the required= attribute's business, not this rule's.
        if ($value === null || $value === '') {
            return true;
        }

        // A crafted POST can hand an array where a scalar belongs.
        if (!\is_scalar($value) || !is_numeric($value)) {
            throw new UnexpectedValueException(Text::_('COM_ALFA_ERROR_DISCOUNT_VALUE_NOT_A_NUMBER'));
        }

        $number = (float) $value;

        if ($number < 0) {
            throw new UnexpectedValueException(Text::_('COM_ALFA_ERROR_DISCOUNT_VALUE_NEGATIVE'));
        }

        // A missing or NULL type reads as Percentage: the column's own default, the
        // engine's own reading, and the stricter of the two.
        $isAmount = (int) ($input?->get('is_amount') ?? 0) === 1;

        if (!$isAmount && $number > 100) {
            throw new UnexpectedValueException(Text::_('COM_ALFA_ERROR_DISCOUNT_PERCENT_RANGE'));
        }

        return true;
    }
}
