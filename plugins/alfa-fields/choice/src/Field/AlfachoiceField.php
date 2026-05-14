<?php

namespace Joomla\Plugin\AlfaFields\Choice\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Unified choice field. One JFormField for every render mode.
 * Always submits as array (name="foo[]"); storage layer should JSON-encode.
 *
 * Supported render_as values:
 *   select | multiselect | radio | checkbox |
 *   button | button-multi | chip | chip-multi
 */
class AlfachoiceField extends FormField
{
    protected $type = 'Alfachoice';

    //    public function getInputName($asArray = true)
    //    {
    //        return parent::getInputName(true);
    //    }

    protected function getInput()
    {
        $renderAs = (string) ($this->element['render_as'] ?: 'select');
        $layout = (string) ($this->element['layout'] ?: 'vertical');
        $placeholder = (string) ($this->element['placeholder'] ?: '');
        $minSel = (int) ($this->element['min_selections'] ?: 0);
        $maxSel = (int) ($this->element['max_selections'] ?: 0);

        $name = $this->getNameWithBrackets();
        $id = $this->id;
        $required = $this->required ? ' required' : '';
        $disabled = $this->disabled ? ' disabled' : '';

        $options = $this->parseOptions();
        $selected = $this->parseValue();
        $multi = $this->isMulti($renderAs);

        if ($multi) {
            $optCount = count($options);

            if ($minSel > $optCount) {
                $minSel = $optCount;
            }

            if ($maxSel > 0 && $maxSel < $minSel) {
                $maxSel = $minSel;
            }

            if ($maxSel >= $optCount) {
                $maxSel = 0;
            }
        } else {
            $minSel = $maxSel = 0;
        }

        return match ($renderAs) {
            'select', 'multiselect'
                => $this->renderSelect($name, $id, $options, $selected, $multi, $placeholder, $required, $disabled, $minSel, $maxSel),
            'radio'
                => $this->renderInputs($name, $id, $options, $selected, 'radio', $layout, 'radio', $required, $disabled, 0, 0),
            'checkbox'
                => $this->renderInputs($name, $id, $options, $selected, 'checkbox', $layout, 'checkbox', $required, $disabled, $minSel, $maxSel),
            'button'
                => $this->renderInputs($name, $id, $options, $selected, 'radio', $layout, 'button', $required, $disabled, 0, 0),
            'button-multi'
                => $this->renderInputs($name, $id, $options, $selected, 'checkbox', $layout, 'button', $required, $disabled, $minSel, $maxSel),
            'chip'
                => $this->renderInputs($name, $id, $options, $selected, 'radio', $layout, 'chip', $required, $disabled, 0, 0),
            'chip-multi'
                => $this->renderInputs($name, $id, $options, $selected, 'checkbox', $layout, 'chip', $required, $disabled, $minSel, $maxSel),
            default
            => $this->renderSelect($name, $id, $options, $selected, false, $placeholder, $required, $disabled, 0, 0),
        };
    }

    private function isMulti(string $renderAs): bool
    {
        return in_array($renderAs, ['multiselect', 'checkbox', 'button-multi', 'chip-multi'], true);
    }

    private function getNameWithBrackets(): string
    {
        // Always submit as array. parent::getInputName(true) does this via JForm.
        $name = $this->name;
        return str_ends_with($name, '[]') ? $name : $name . '[]';
    }

    private function parseOptions(): array
    {
        $opts = [];
        foreach ($this->element->xpath('option') ?: [] as $opt) {
            $opts[] = [
                'value' => (string) $opt['value'],
                'text' => trim((string) $opt),
            ];
        }
        return $opts;
    }

    private function parseValue(): array
    {
        $v = $this->value;
        if ($v === null || $v === '' || $v === '[]') {
            return [];
        }
        if (is_array($v)) {
            return array_map('strval', $v);
        }
        $decoded = json_decode((string) $v, true);
        if (is_array($decoded)) {
            return array_map('strval', $decoded);
        }
        return [(string) $v];
    }

    private function renderSelect(string $name, string $id, array $options, array $selected, bool $multi, string $placeholder, string $required, string $disabled, int $minSel, int $maxSel): string
    {
        $multiAttr = $multi ? ' multiple' : '';
        $dataMin = $minSel > 0 ? ' data-min-selections="' . $minSel . '"' : '';
        $dataMax = $maxSel > 0 ? ' data-max-selections="' . $maxSel . '"' : '';

        $html = '<div class="alfa-choice-wrap">';
        $html .= '<select class="alfa-choice__select" name="' . $this->h($name) . '" id="' . $this->h($id) . '"'
              . $multiAttr . $required . $disabled . $dataMin . $dataMax . '>';

        if ($placeholder !== '' && !$multi) {
            $html .= '<option value="">' . $this->h($placeholder) . '</option>';
        }

        foreach ($options as $opt) {
            $sel = in_array($opt['value'], $selected, true) ? ' selected' : '';
            $html .= '<option value="' . $this->h($opt['value']) . '"' . $sel . '>' . $this->h($opt['text']) . '</option>';
        }

        $html .= '</select>';
        $html .= $this->renderHint($minSel, $maxSel);
        $html .= '</div>';

        return $html;
    }

    private function renderInputs(string $name, string $id, array $options, array $selected, string $inputType, string $layout, string $variant, string $required, string $disabled, int $minSel, int $maxSel): string
    {
        $cls = 'alfa-choice alfa-choice--' . $variant . ' alfa-choice--' . $layout;

        $dataMin = $minSel > 0 ? ' data-min-selections="' . $minSel . '"' : '';
        $dataMax = $maxSel > 0 ? ' data-max-selections="' . $maxSel . '"' : '';

        $html = '<div class="alfa-choice-wrap">';
        $html .= '<div class="' . $cls . '"' . $dataMin . $dataMax . ' role="group">';

        $i = 0;
        foreach ($options as $opt) {
            $i++;
            $optId = $id . '_' . $i;
            $checked = in_array($opt['value'], $selected, true) ? ' checked' : '';

            $inputRequired = ($inputType === 'radio' && $i === 1) ? $required : '';

            $html .= '<input type="' . $inputType . '" class="alfa-choice__input"'
                  . ' id="' . $this->h($optId) . '"'
                  . ' name="' . $this->h($name) . '"'
                  . ' value="' . $this->h($opt['value']) . '"'
                  . $checked . $inputRequired . $disabled . '>';

            $html .= '<label class="alfa-choice__label" for="' . $this->h($optId) . '">'
                  . '<span class="alfa-choice__text">' . $this->h($opt['text']) . '</span>'
                  . '</label>';
        }

        $html .= '</div>';
        $html .= $this->renderHint($minSel, $maxSel);
        $html .= '</div>';

        return $html;
    }

    private function renderHint(int $minSel, int $maxSel): string
    {
        if ($minSel <= 0 && $maxSel <= 0) {
            return '';
        }

        if ($minSel > 0 && $maxSel > 0 && $minSel === $maxSel) {
            $msg = Text::sprintf('PLG_ALFA_FIELDS_CHOICE_HINT_EXACTLY', $minSel);
        } elseif ($minSel > 0 && $maxSel > 0) {
            $msg = Text::sprintf('PLG_ALFA_FIELDS_CHOICE_HINT_BETWEEN', $minSel, $maxSel);
        } elseif ($minSel > 0) {
            $msg = Text::sprintf('PLG_ALFA_FIELDS_CHOICE_HINT_MIN', $minSel);
        } else {
            $msg = Text::sprintf('PLG_ALFA_FIELDS_CHOICE_HINT_MAX', $maxSel);
        }

        return '<small class="alfa-choice__hint">' . $this->h($msg) . '</small>';
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
