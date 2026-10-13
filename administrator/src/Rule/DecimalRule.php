<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * A typed amount its DECIMAL(24,8) column holds as it is, or a refusal that
 * names it.
 *
 * The pair of {@see \Alfa\Component\Alfa\Administrator\Filter\DecimalFilter}:
 * the filter hands a figure the column holds as its exact text and passes any
 * other value on untouched; this rule refuses that value by name. A figure
 * finer than the column's eight places, or with more whole digits than it
 * holds, is therefore never rounded or cut by the column.
 *
 * @since  2.0.0
 */
class DecimalRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The filtered value.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data.
     * @param Form|null $form The form.
     *
     * @return bool True when the column holds the value as it is.
     *
     * @throws UnexpectedValueException With the operator's words, naming the figure.
     *
     * @since  2.0.0
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // Emptiness is the required= attribute's business, not this rule's.
        if ($value === null || $value === '') {
            return true;
        }

        $typed = \is_string($value) ? trim($value) : $value;

        if (!\is_int($typed) && !\is_float($typed) && !(\is_string($typed) && is_numeric($typed))) {
            throw new UnexpectedValueException(Text::sprintf('COM_ALFA_ERROR_DECIMAL_NOT_A_NUMBER', \is_scalar($value) ? (string) $value : \gettype($value)));
        }

        $exact = ExactAmount::ofNumber($typed);

        return match (StoredDecimal::refusal(value: $exact)) {
            StoredDecimal::FINER => throw new UnexpectedValueException(Text::sprintf('COM_ALFA_ERROR_DECIMAL_TOO_FINE', (string) $typed, StoredDecimal::SCALE)),
            StoredDecimal::LARGER => throw new UnexpectedValueException(Text::sprintf('COM_ALFA_ERROR_DECIMAL_TOO_LARGE', (string) $typed)),
            default => true,
        };
    }
}
