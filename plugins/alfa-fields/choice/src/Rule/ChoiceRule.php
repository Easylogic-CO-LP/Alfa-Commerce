<?php

namespace Alfa\Plugin\AlfaFields\Choice\Rule;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use RuntimeException;
use SimpleXMLElement;

defined('_JEXEC') or die;

// Resolved by Joomla when validate="choice" on the field node.
// Enforces min/max selection limits for multi-mode choice fields.
class ChoiceRule extends FormRule
{
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        $min = (int) ($element['data-min-selections'] ?? 0);
        $max = (int) ($element['data-max-selections'] ?? 0);

        if ($min <= 0 && $max <= 0) {
            return true;
        }

        $count = is_array($value) ? count(array_filter($value, static fn ($v) => $v !== '' && $v !== null)) : 0;

        if ($min > 0 && $count < $min) {
            throw new RuntimeException(Text::sprintf('PLG_ALFA_FIELDS_CHOICE_ERR_MIN', $min));
        }

        if ($max > 0 && $count > $max) {
            throw new RuntimeException(Text::sprintf('PLG_ALFA_FIELDS_CHOICE_ERR_MAX', $max));
        }

        return true;
    }
}
