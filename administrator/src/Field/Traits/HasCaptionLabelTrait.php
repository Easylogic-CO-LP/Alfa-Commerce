<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field\Traits;

\defined('_JEXEC') or die;

/**
 * The caption of a field that draws no single control a label could name: a set of controls, a widget or a notice.
 *
 * Joomla's label always names the field's id, and these fields give that id to no control, so the browser
 * reports a label that labels nothing. The caption keeps the label's id, classes, text and required star, as text.
 *
 * @since  2.1.1
 */
trait HasCaptionLabelTrait
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
        return $this->captionLabel();
    }

    /**
     * The caption markup: the label's id, classes, text and required star, without the for attribute.
     *
     * @return string The caption markup, or nothing for a hidden field.
     *
     * @since  2.1.1
     */
    protected function captionLabel(): string
    {
        if ($this->hidden) {
            return '';
        }

        $data = $this->collectLayoutData();
        $classes = array_filter(explode(' ', (string) ($data['labelclass'] ?? '')));

        if (!empty($data['required'])) {
            $classes[] = 'required';
        }

        return '<span id="' . htmlspecialchars($this->id . '-lbl', ENT_QUOTES, 'UTF-8') . '"'
            . ($classes !== [] ? ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
            . (string) ($data['label'] ?? '')
            . (!empty($data['required']) ? '<span class="star" aria-hidden="true">&#160;*</span>' : '')
            . '</span>';
    }
}
