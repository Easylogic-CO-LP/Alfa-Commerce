<?php

namespace Alfa\Plugin\AlfaFormFields\Choice\Rule;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use RuntimeException;
use SimpleXMLElement;

defined('_JEXEC') or die;

// Resolved by Joomla when validate="choice" on the field node, which every choice mode carries (2.2.2).
// Every value sent must be one of the field's choices; a multi-mode field also keeps its min/max selection limits.
class ChoiceRule extends FormRule
{
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // THE VALUES MUST BE THE FIELD'S OWN (2.2.2). Joomla's list, radio and checkbox fields take any text posted,
        // so a value that is none of the options ("invoice", or a choice's label typed in place of its value) was
        // stored as sent. The storefront's controls offer only the options; a hand-made post or an API client met
        // nothing. An empty answer is the required rule's business, not this one's.
        $choices = [];

        foreach ($element->option as $option) {
            $choices[] = (string) $option['value'];
        }

        foreach (is_array($value) ? $value : [$value] as $picked) {
            if ($picked === '' || $picked === null) {
                continue;
            }

            if (!is_scalar($picked) || !in_array((string) $picked, $choices, true)) {
                throw new RuntimeException(Text::sprintf(
                    'PLG_ALFA_FORM_FIELDS_CHOICE_ERR_NOT_A_CHOICE',
                    (string) $element['name'],
                    is_scalar($picked) ? (string) $picked : get_debug_type($picked),
                    implode(', ', array_filter($choices, static fn (string $choice): bool => $choice !== '')),
                ));
            }
        }

        $min = (int) ($element['data-min-selections'] ?? 0);
        $max = (int) ($element['data-max-selections'] ?? 0);

        if ($min <= 0 && $max <= 0) {
            return true;
        }

        $count = is_array($value) ? count(array_filter($value, static fn ($v) => $v !== '' && $v !== null)) : 0;

        if ($min > 0 && $count < $min) {
            throw new RuntimeException(Text::sprintf('PLG_ALFA_FORM_FIELDS_CHOICE_ERR_MIN', $min));
        }

        if ($max > 0 && $count > $max) {
            throw new RuntimeException(Text::sprintf('PLG_ALFA_FORM_FIELDS_CHOICE_ERR_MAX', $max));
        }

        return true;
    }
}
