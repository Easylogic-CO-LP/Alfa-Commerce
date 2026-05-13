<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\TextField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;

/**
 * MultilingualTextField
 *
 * Renders one text input per installed language, each with a unique name
 * following the convention:
 *   jform[{fieldName}_{langCode}]
 * e.g.  jform[name_en_gb],  jform[name_el_gr],  jform[alias_en_gb]
 *
 * HOW VALUES ARE LOADED
 * ---------------------
 * Joomla's Form::bind() calls filterData() which strips every key not defined
 * in the XML. Flat multilingual keys (name_en_gb etc.) are never in the XML
 * so they are always stripped before setup() runs. $this->form->getValue()
 * therefore always returns null for them — the form registry cannot be used.
 *
 * Instead, setup() reads directly from the DB using:
 *   - The item ID from the form registry (the id field IS in the XML).
 *   - The table and PK column declared as XML attributes on the field.
 *
 * This makes the field fully self-contained and reusable across any entity.
 * The model has zero multilingual knowledge — it does not need to inject
 * any flat keys or arrays for form hydration to work.
 *
 * REQUIRED XML ATTRIBUTES
 * -----------------------
 * multilingual_table  — Base table name       (e.g. "#__alfa_categories").
 * multilingual_pk     — PK column in lang tables  (e.g. "id_category").
 *
 * REQUIRED HANDLING
 * -----------------
 * Joomla's parent::setup() appends 'required' to $this->class when required="true"
 * is set in the XML. We strip it from the base class and re-apply it only to the
 * default-language input so non-default inputs are never marked required.
 * Server-side validation reads the default-language value from raw POST.
 *
 * Usage in a Joomla form XML:
 *   <field name="name"
 *          type="MultilingualText"
 *          multilingual_table="#__alfa_categories"
 *          multilingual_pk="id_category"
 *          label="COM_ALFA_FIELD_NAME"
 *          required="true" />
 *
 * @since  1.0.1
 */
class MultilingualTextField extends TextField
{
    protected $type = 'MultilingualText';

    // =========================================================================
    //  Lifecycle
    // =========================================================================

    /**
     * Build the per-language value array by reading directly from the DB.
     *
     * Form::bind() → filterData() strips every key not in the XML, so flat
     * multilingual keys (name_en_gb etc.) never reach the form registry.
     * We bypass the registry entirely and read from the language tables directly
     * using the item ID (which survives filterData() because id IS in the XML)
     * and the table/pk declared as XML attributes on the field definition.
     *
     * For new items (id = 0) all inputs are initialised with empty strings.
     *
     * @param   \SimpleXMLElement  $element  The form field XML definition.
     * @param   mixed              $value    The base field value — always empty, ignored.
     * @param   string|null        $group    The field group name.
     *
     * @return  bool
     *
     * @since   1.0.1
     */
    public function setup(\SimpleXMLElement $element, $value, $group = null): bool
    {
        $result = parent::setup($element, $value, $group);

        $languages = LanguageHelper::getLanguages('lang_code');
        $flatData  = [];

        // Read table and PK from XML attributes — makes this field reusable
        // across any entity without any model involvement.
        $itemId = (int) $this->form->getValue('id', null);
        $table  = (string) ($this->element['multilingual_table'] ?? '');
        $pk     = (string) ($this->element['multilingual_pk']    ?? '');

        if ($itemId > 0 && $table !== '' && $pk !== '') {
            // Read flat keys (name_en_gb, alias_el_gr …) directly from DB,
            // bypassing the form registry which always strips them.
            $flatData = MultilingualHelper::getMultilingualDataFlat(
                currentId:         $itemId,
                primaryColumnName: $pk,
                tableName:         $table,
            );
        }

        $arrayValue = [];

        foreach ($languages as $langCode => $language) {
            $formatted = strtolower(str_replace('-', '_', $langCode));
            $flatKey   = $this->fieldname . '_' . $formatted;

            $arrayValue[$formatted] = $flatData[$flatKey] ?? '';
        }

        $this->value = $arrayValue;

        return $result;
    }

    // =========================================================================
    //  Rendering
    // =========================================================================

    /**
     * Render one text input per installed language.
     *
     * Uses $this->required (bool) which Joomla's parent::setup() already parsed
     * from the XML attribute — handles both required="true" and required="required".
     * Only the default-language input receives the HTML5 `required` attribute and
     * Joomla's `.required` CSS class, matching native Joomla field markup.
     *
     * @return  string  HTML markup for all language inputs.
     *
     * @since   1.0.1
     */
    protected function getInput(): string
    {
        $languages       = LanguageHelper::getLanguages('lang_code');
        $isMultilang     = count($languages) > 1;
        $isRequired      = $this->required;
        $defaultLangCode = strtolower(str_replace('-', '_', array_key_first($languages)));

        $html   = '';
        $values = is_array($this->value) ? $this->value : [];

        foreach ($languages as $langCode => $language) {
            $formatted = strtolower(str_replace('-', '_', $langCode));
            $isDefault = ($formatted === $defaultLangCode);

            $inputId   = $this->id . '_' . $formatted;
            $inputName = 'jform[' . $this->fieldname . '_' . $formatted . ']';
            $val       = $values[$formatted] ?? '';

            // Only the default-language input is marked required
            $required = $isRequired && $isDefault;

            $html .= '<div style="position:relative;margin-bottom:4px;">'
                . $this->buildInputHtml($inputId, $inputName, $val, $required)
                . ($isMultilang ? $this->buildFlagHtml($language) : '')
                . '</div>';
        }

        return $html;
    }

    // =========================================================================
    //  Validation
    // =========================================================================

    /**
     * Proxy Joomla's built-in validation rules (required, maxlength, etc.)
     * against the default-language scalar value instead of the raw array.
     *
     * jform[name] is never submitted — only jform[name_en_gb] etc. are.
     * $value and $input are therefore always empty. Raw POST is the only
     * guaranteed source of truth during form submission.
     *
     * @param   mixed                           $value  Always empty — ignored.
     * @param   string|null                     $group  The optional form group path.
     * @param   \Joomla\Registry\Registry|null  $input  The full form data registry.
     *
     * @return  bool|\Exception  True if valid, Exception on failure.
     *
     * @since   1.0.1
     */
    public function validate($value, $group = null, ?\Joomla\Registry\Registry $input = null): bool|\Exception
    {
        $languages       = LanguageHelper::getLanguages('lang_code');
        $defaultLangCode = strtolower(str_replace('-', '_', array_key_first($languages)));
        $flatKey         = $this->fieldname . '_' . $defaultLangCode;

        // jform[name] is never in POST — only jform[name_en_gb] etc. are submitted.
        // $value and $input are always empty. Read directly from raw POST.
        $jform       = Factory::getApplication()->getInput()->post->get('jform', [], 'raw');
        $scalarValue = (string) ($jform[$flatKey] ?? '');

        return parent::validate($scalarValue, $group, $input);
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    /**
     * Render a plain <input type="text"> element.
     *
     * We build the element directly rather than delegating to the parent's
     * layout renderer because the renderer expects $this->value / $this->id
     * to be the canonical values and does not support per-language overrides.
     *
     * parent::setup() appends 'required' to $this->class when required="true" is
     * set in the XML. We strip it from the base class here and re-apply it only
     * when $required is true, so non-default language inputs are never affected.
     *
     * The second ?: 'form-control' fallback is necessary because when $this->class
     * contains only 'required' and nothing else, stripping it leaves an empty string.
     *
     * @param   string  $id        The input element ID.
     * @param   string  $name      The input element name (array notation).
     * @param   string  $value     The pre-filled value for this language.
     * @param   bool    $required  Whether to add `required` and `.required` class.
     *
     * @return  string  HTML <input> tag.
     *
     * @since   1.0.1
     */
    private function buildInputHtml(
        string $id,
        string $name,
        string $value,
        bool   $required = false
    ): string {
        // Strip 'required' that parent::setup() injected into $this->class.
        // The second ?: 'form-control' guards against $this->class being *only*
        // 'required' — after stripping that, trim() returns '' which is falsy.
        $baseClass = trim(str_replace('required', '', $this->class ?: 'form-control')) ?: 'form-control';

        $attrs = [
            'type'  => 'text',
            'id'    => $id,
            'name'  => $name,
            'value' => $value,
            'class' => trim($baseClass . ($required ? ' required' : '')),
        ];

        if (!empty($this->size))      { $attrs['size']        = (int) $this->size; }
        if (!empty($this->maxlength)) { $attrs['maxlength']   = (int) $this->maxlength; }
        if ($this->readonly)          { $attrs['readonly']    = 'readonly'; }
        if ($this->disabled)          { $attrs['disabled']    = 'disabled'; }
        if (!empty($this->hint))      { $attrs['placeholder'] = Text::_($this->hint); }

        // HTML5 native required — only on the default-language input
        if ($required) {
            $attrs['required'] = '';
        }

        $attrString = '';

        foreach ($attrs as $attrName => $attrValue) {
            $attrString .= ' ' . $attrName . '="' . htmlspecialchars((string) $attrValue, ENT_COMPAT, 'UTF-8') . '"';
        }

        return '<input' . $attrString . ' />';
    }

    /**
     * Render a small flag image pinned to the right edge of the wrapper div.
     * Falls back to a plain text language code when no flag image is available.
     *
     * @param   object  $language  Language object from LanguageHelper::getLanguages().
     *
     * @return  string  HTML image tag or text fallback.
     *
     * @since   1.0.1
     */
    private function buildFlagHtml(object $language): string
    {
        if (empty($language->image)) {
            return '<span style="position:absolute;right:6px;top:50%;transform:translateY(-50%);font-size:0.75em;pointer-events:none;">'
                . htmlspecialchars($language->sef, ENT_COMPAT, 'UTF-8')
                . '</span>';
        }

        return HTMLHelper::_(
            'image',
            'mod_languages/' . $language->image . '.gif',
            $language->title_native,
            [
                'title' => $language->title_native,
                'style' => 'position:absolute;right:6px;top:50%;transform:translateY(-50%);pointer-events:none;',
            ],
            true,
        );
    }
}