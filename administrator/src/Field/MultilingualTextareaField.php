<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\TextareaField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use SimpleXMLElement;

/**
 * MultilingualTextareaField
 *
 * Same shape as MultilingualTextField but renders a <textarea> per installed
 * language instead of a single-line <input>. Use it for short paragraphs
 * (descriptions, tips) that need multiple lines but don't warrant a full
 * WYSIWYG MultilingualEditor.
 *
 * Storage and value-loading rules are identical to MultilingualTextField: the
 * field reads/writes flat language keys (e.g. description_el_gr) directly via
 * MultilingualHelper, bypassing the form registry which always strips them.
 *
 * Supports the standard textarea XML attributes:  rows="3" cols="50" maxlength="255".
 * Defaults to two languages per row; set  fields_per_line="1"  for the stacked layout.
 *
 * REQUIRED XML ATTRIBUTES (same as MultilingualText):
 *   multilingual_table  — Base table name        (e.g. "#__alfa_payments").
 *   multilingual_pk     — PK column in lang tables (e.g. "id_payment").
 *
 * Usage:
 *   <field name="description"
 *          type="MultilingualTextarea"
 *          multilingual_table="#__alfa_payments"
 *          multilingual_pk="id_payment"
 *          rows="3"
 *          label="COM_ALFA_FORM_LBL_DESCRIPTION" />
 *
 * @since  1.0.0
 */
class MultilingualTextareaField extends TextareaField
{
    /**
     * The field type — must match the filename and XML type attribute.
     *
     * @var string
     */
    public $type = 'MultilingualTextarea';

    // =========================================================================
    //  Lifecycle
    // =========================================================================

    /**
     * Build the per-language value array by reading directly from the DB.
     *
     * Form::bind() → filterData() strips every key not in the XML, so the flat
     * multilingual keys (description_en_gb etc.) never reach the form registry.
     * We bypass it and read from the language tables using the item ID and the
     * table/pk declared as XML attributes on the field definition.
     *
     * @param SimpleXMLElement $element The form field XML definition.
     * @param mixed $value The base field value — ignored.
     * @param string|null $group The field group name.
     *
     *
     * @since  1.0.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null): bool
    {
        $result = parent::setup($element, $value, $group);

        $languages = LanguageHelper::getLanguages('lang_code');
        $flatData = [];

        $itemId = (int) $this->form->getValue('id', null);
        $table = (string) ($this->element['multilingual_table'] ?? '');
        $pk = (string) ($this->element['multilingual_pk'] ?? '');

        if ($itemId > 0 && $table !== '' && $pk !== '') {
            $flatData = MultilingualHelper::getMultilingualDataFlat(
                currentId:         $itemId,
                primaryColumnName: $pk,
                tableName:         $table,
            );
        }

        $arrayValue = [];

        foreach ($languages as $langCode => $language) {
            $formatted = strtolower(str_replace('-', '_', $langCode));
            $flatKey = $this->fieldname . '_' . $formatted;

            $arrayValue[$formatted] = $flatData[$flatKey] ?? '';
        }

        $this->value = $arrayValue;

        return $result;
    }

    // =========================================================================
    //  Rendering
    // =========================================================================

    /**
     * Render one <textarea> per installed language.
     *
     * @return string HTML markup for all language textareas.
     *
     * @since  1.0.0
     */
    protected function getInput(): string
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useStyle('com_alfa.multilingual-fields');

        $languages = LanguageHelper::getLanguages('lang_code');
        $isMultilang = count($languages) > 1;
        $isRequired = $this->required;
        $defaultLangCode = strtolower(str_replace('-', '_', array_key_first($languages)));

        $items = '';
        $values = is_array($this->value) ? $this->value : [];

        foreach ($languages as $langCode => $language) {
            $formatted = strtolower(str_replace('-', '_', $langCode));
            $isDefault = ($formatted === $defaultLangCode);

            $inputId = $this->id . '_' . $formatted;
            $inputName = 'jform[' . $this->fieldname . '_' . $formatted . ']';
            $val = $values[$formatted] ?? '';

            // Only the default-language textarea is marked required.
            $required = $isRequired && $isDefault;

            // The --textarea modifier pins the flag to the top of the wrapper
            // instead of the middle so it doesn't float in a tall textarea.
            $items .= '<div class="ml-field-item ml-field-item--textarea">'
                . $this->buildTextareaHtml($inputId, $inputName, $val, $required)
                . ($isMultilang ? $this->buildFlagHtml($language) : '')
                . '</div>';
        }

        // Default to two languages per row; set fields_per_line="1" to opt back
        // into the stacked layout, or a higher number for more columns. The exact
        // column count is emitted inline so it holds for any language count.
        // Base classes live in media/com_alfa/css/admin/multilingual-fields.css.
        $perLine = (int) ($this->element['fields_per_line'] ?? 2);

        if ($perLine >= 2) {
            // Emit the column count as a CSS var (not grid-template-columns directly)
            // so the stylesheet can collapse to one-per-row on phones without an
            // !important fight against an inline declaration.
            return '<div class="ml-field-grid" style="--ml-cols: ' . $perLine . ';">'
                . $items . '</div>';
        }

        return '<div class="ml-field-stack">' . $items . '</div>';
    }

    // =========================================================================
    //  Validation
    // =========================================================================

    /**
     * Proxy Joomla's built-in validation rules against the default-language
     * scalar value. jform[description] is never submitted — only the per-language
     * keys are — so raw POST is the only guaranteed source of truth.
     *
     * @param mixed $value Always empty — ignored.
     * @param string|null $group Optional form group path.
     * @param \Joomla\Registry\Registry|null $input Full form data registry.
     *
     *
     * @since  1.0.0
     */
    public function validate($value, $group = null, ?\Joomla\Registry\Registry $input = null): bool|Exception
    {
        $languages = LanguageHelper::getLanguages('lang_code');
        $defaultLangCode = strtolower(str_replace('-', '_', array_key_first($languages)));
        $flatKey = $this->fieldname . '_' . $defaultLangCode;

        $jform = Factory::getApplication()->getInput()->post->get('jform', [], 'raw');
        $scalarValue = (string) ($jform[$flatKey] ?? '');

        return parent::validate($scalarValue, $group, $input);
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    /**
     * Render a single <textarea> element for one language slot.
     *
     * @param string $id The textarea element ID.
     * @param string $name The textarea element name (array notation).
     * @param string $value The pre-filled value for this language.
     * @param bool $required Whether to add `required` and `.required` class.
     *
     * @return string HTML <textarea> tag.
     *
     * @since  1.0.0
     */
    private function buildTextareaHtml(
        string $id,
        string $name,
        string $value,
        bool $required = false,
    ): string {
        // Strip 'required' that parent::setup() injected into $this->class.
        $baseClass = trim(str_replace('required', '', $this->class ?: 'form-control')) ?: 'form-control';

        $attrs = [
            'id' => $id,
            'name' => $name,
            'class' => trim($baseClass . ($required ? ' required' : '')),
        ];

        if (!empty($this->rows)) {
            $attrs['rows'] = (int) $this->rows;
        }
        if (!empty($this->columns)) {
            $attrs['cols'] = (int) $this->columns;
        }
        if (!empty($this->maxlength)) {
            $attrs['maxlength'] = (int) $this->maxlength;
        }
        if ($this->readonly) {
            $attrs['readonly'] = 'readonly';
        }
        if ($this->disabled) {
            $attrs['disabled'] = 'disabled';
        }
        if (!empty($this->hint)) {
            $attrs['placeholder'] = Text::_($this->hint);
        }

        // HTML5 native required — only on the default-language textarea.
        if ($required) {
            $attrs['required'] = '';
        }

        $attrString = '';

        foreach ($attrs as $attrName => $attrValue) {
            $attrString .= ' ' . $attrName . '="' . htmlspecialchars((string) $attrValue, ENT_COMPAT, 'UTF-8') . '"';
        }

        return '<textarea' . $attrString . '>'
            . htmlspecialchars($value, ENT_COMPAT, 'UTF-8')
            . '</textarea>';
    }

    /**
     * Render the language flag (image, or text fallback) pinned to the input.
     *
     * @param object $language Language object from LanguageHelper::getLanguages().
     *
     *
     * @since  1.0.0
     */
    private function buildFlagHtml(object $language): string
    {
        if (empty($language->image)) {
            return '<span class="ml-field-flag ml-field-flag--text">'
                . htmlspecialchars($language->sef, ENT_COMPAT, 'UTF-8')
                . '</span>';
        }

        return HTMLHelper::_(
            'image',
            'mod_languages/' . $language->image . '.gif',
            $language->title_native,
            [
                'title' => $language->title_native,
                'class' => 'ml-field-flag',
            ],
            true,
        );
    }
}
