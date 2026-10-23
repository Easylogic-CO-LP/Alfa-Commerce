<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaFormFields\Choice\Field\Traits;

\defined('_JEXEC') or die;

/**
 * The visible caption of a choice drawn as a group of checkboxes, radios or buttons.
 *
 * Joomla's label names the field's id, which here is the group's fieldset, so it labels nothing; its text was also
 * read a second time, after the group's own legend. The caption keeps the label's id, classes, text and required
 * star for the eye, and is hidden from assistive technology: the group is named once, by its legend.
 *
 * @since  2.1.1
 */
trait HasGroupCaptionTrait
{
    /**
     * The field's caption.
     *
     * @return string The caption markup, or nothing for a hidden field.
     *
     * @since  2.1.1
     */
    protected function getLabel(): string
    {
        if ($this->hidden) {
            return '';
        }

        $data = $this->collectLayoutData();
        $classes = array_filter(explode(' ', (string) ($data['labelclass'] ?? '')));

        if (!empty($data['required'])) {
            $classes[] = 'required';
        }

        return '<span id="' . htmlspecialchars($this->id . '-lbl', ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"'
            . ($classes !== [] ? ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
            . (string) ($data['label'] ?? '')
            . (!empty($data['required']) ? '<span class="star">&#160;*</span>' : '')
            . '</span>';
    }
}
