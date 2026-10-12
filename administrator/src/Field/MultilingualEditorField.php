<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\Traits\HasMultilingualNamesTrait;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\EditorField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use SimpleXMLElement;

/**
 * MultilingualEditorField
 *
 * Renders one WYSIWYG editor instance per installed Joomla language.
 *
 * - Single language  : renders the editor directly with no surrounding chrome.
 * - Multiple languages: wraps each editor in a Bootstrap uitab panel.
 *
 * Each editor posts to its own form key following the convention:
 *   jform[{fieldName}_{langCode}]
 * e.g.  jform[desc_en_gb],  jform[desc_el_gr]
 *
 * How values are loaded
 * The submitted value wins; the stored row is the fallback.
 *
 * AlfaAdminModel declares one MultilingualStorage sibling per language for every
 * translatable field, so the flat keys are REAL form fields: they survive
 * Form::filter(), the session stash a failed save writes, and Form::bind(). setup()
 * therefore reads the form registry first and only reaches the language tables for
 * what the form did not carry, which is what makes a failed save redisplay the HTML
 * the admin wrote instead of repainting the stale saved row over it.
 *
 * The database fallback still needs no model involvement:
 *   - The item ID from the form registry (the id field IS in the XML).
 *   - The table and PK column declared as XML attributes on the field.
 *
 * Required XML attributes
 * multilingual_table  - Base table name           (e.g. "#__alfa_categories").
 * multilingual_pk     - PK column in lang tables  (e.g. "id_category").
 *
 * Required handling
 * The editor owns its own markup; we cannot inject required="" into it.
 * Required is enforced in two ways:
 *   1. Server-side: validate() proxies the rules against the default-language value.
 *   2. Visual:      A star (*) is appended to the default-language tab label
 *                   when required="true" is set in the XML, mirroring the
 *                   native Joomla label star behaviour.
 *
 * Usage in a Joomla form XML:
 *   <field name="desc"
 *          type="MultilingualEditor"
 *          multilingual_table="#__alfa_categories"
 *          multilingual_pk="id_category"
 *          label="COM_ALFA_FIELD_DESC" />
 *
 * NOTE: The `layout` XML attribute is intentionally ignored.
 * Standard Joomla field layouts assume a single input element and are
 * incompatible with the per-language input structure this field produces.
 *
 * @since  1.0.0
 */
class MultilingualEditorField extends EditorField implements MultilingualFieldInterface
{
    use HasMultilingualNamesTrait;

    /**
     * The field type: must match the filename and XML type attribute.
     *
     * @var string
     */
    public $type = 'MultilingualEditor';

    //  Lifecycle

    /**
     * Build the per-language value array: what was submitted, else what is stored.
     *
     * The flat keys are declared storage siblings, so they reach the form registry like any
     * other field; only the languages the form did not carry are read from the language tables,
     * using the item ID (id IS in the XML) and the table/pk declared as XML attributes on the
     * field definition.
     *
     * For new items with nothing submitted (id = 0) all editors are initialised with empty strings.
     *
     * @param SimpleXMLElement $element The XML field definition.
     * @param mixed $value The base field value: always empty, ignored.
     * @param string|null $group Field name group.
     *
     *
     * @since  1.0.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null): bool
    {
        $result = parent::setup($element, $value, $group);

        $languages = LanguageHelper::getLanguages('lang_code');

        // Table and PK come from XML attributes: makes this field reusable across any entity
        // without any model involvement.
        $itemId = (int) $this->form->getValue('id', null);
        $table = (string) ($this->element['multilingual_table'] ?? '');
        $pk = (string) ($this->element['multilingual_pk'] ?? '');

        $arrayValue = [];
        $missing = [];
        $bound = $this->form->getData();

        foreach ($languages as $langCode => $language) {
            $formatted = $this->formatLangCode($langCode);
            $flatKey = MultilingualHelper::buildLangFieldKey($this->fieldname, $formatted);

            // exists(), not get(): Registry::get() hands back its default for an empty string,
            // so an editor the admin deliberately emptied would be repainted from the stored row.
            if ($bound->exists($flatKey)) {
                $arrayValue[$formatted] = (string) $bound->get($flatKey);

                continue;
            }

            $arrayValue[$formatted] = '';
            $missing[$formatted] = $flatKey;
        }

        // Only reach the language tables for what the form did not carry.
        if ($missing !== [] && $itemId > 0 && $table !== '' && $pk !== '') {
            $flatData = MultilingualHelper::getMultilingualDataFlat(
                currentId:         $itemId,
                primaryColumnName: $pk,
                tableName:         $table,
            );

            foreach ($missing as $formatted => $flatKey) {
                $arrayValue[$formatted] = $flatData[$flatKey] ?? '';
            }
        }

        $this->value = $arrayValue;

        return $result;
    }

    //  Rendering

    /**
     * Build the complete field HTML.
     *
     * $this->getEditor() is inherited from EditorField and is the correct way
     * to obtain a fully initialised editor instance; it handles plugin import
     * and dispatcher wiring internally. We call it once here and pass it down
     * to avoid repeated initialisation per language tab.
     *
     * @return string Ready-to-render HTML.
     *
     * @since  1.0.0
     */
    protected function getInput(): string
    {
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useStyle('com_alfa.multilingual-fields');

        $editor = $this->getEditor();
        $languages = LanguageHelper::getLanguages('lang_code');
        $values = is_array($this->value) ? $this->value : [];

        if (count($languages) === 1) {
            return $this->renderSingleLanguage(
                editor:    $editor,
                languages: $languages,
                values:    $values,
            );
        }

        return $this->renderTabSet(
            editor:    $editor,
            languages: $languages,
            values:    $values,
        );
    }

    //  Validation

    /**
     * Proxy Joomla's built-in validation rules against the default-language scalar.
     *
     * jform[desc] is never submitted (only jform[desc_en_gb] etc. are), so both $value and
     * $input are empty by the time this runs and the field reads its own POST, by the very
     * name it rendered.
     *
     * @param mixed $value Always empty, ignored.
     * @param string|null $group Optional form group path.
     * @param \Joomla\Registry\Registry|null $input Full form data registry.
     *
     * @return bool|Exception True if valid, Exception on failure.
     *
     * @since  1.0.0
     */
    public function validate($value, $group = null, ?\Joomla\Registry\Registry $input = null): bool|Exception
    {
        // From the filtered data when the form carries it, else from this editor's own POST.
        $scalarValue = $this->submittedLanguageValue($this->defaultLangCode(), $input, $group);

        return parent::validate($scalarValue, $group, $input);
    }

    //  Private: layout builders

    /**
     * Single language: render the editor with no surrounding chrome.
     *
     * @param array $languages LanguageHelper::getLanguages() result.
     * @param array $values Keyed by formatted lang code e.g. 'en_gb'.
     *
     *
     * @since  1.0.0
     */
    private function renderSingleLanguage(
        \Joomla\CMS\Editor\Editor $editor,
        array $languages,
        array $values,
    ): string {
        $formattedLangCode = $this->formatLangCode(array_key_first($languages));

        return $this->buildEditorHtml(
            editor: $editor,
            id:     $this->id . '_' . $formattedLangCode,
            name:   $this->buildLanguageInputName($formattedLangCode),
            value:  $values[$formattedLangCode] ?? '',
        );
    }

    /**
     * Multiple languages: one Bootstrap uitab panel per language.
     *
     * Tab IDs are scoped to this field's own id so two multilingual editor
     * fields on the same form never collide in the tab set registry.
     *
     * When required="true" is set in the XML, a star (*) is appended to the
     * default-language tab label, mirroring the native Joomla label star.
     * The editor owns its own markup so required="" cannot be injected into it.
     *
     * @param array $languages LanguageHelper::getLanguages() result.
     * @param array $values Keyed by formatted lang code e.g. 'en_gb'.
     *
     *
     * @since  1.0.0
     */
    private function renderTabSet(
        \Joomla\CMS\Editor\Editor $editor,
        array $languages,
        array $values,
    ): string {
        $entries = $this->normaliseLangEntries($languages);
        $tabSetId = 'mlEditor_' . $this->id;
        $activeTab = $tabSetId . '_' . $entries[0]['code'];
        $defaultLangCode = $this->defaultLangCode();

        // No 'recall' here: nested recall tabsets all share one URL-keyed
        // sessionStorage slot, and a deep language-tab id stored there can
        // trip core joomla-tab's walk-up loop on reload (main-thread freeze).
        $html = HTMLHelper::_('uitab.startTabSet', $tabSetId, [
            'active' => $activeTab,
            'breakpoint' => 768,
        ]);

        foreach ($entries as $entry) {
            $tabId = $tabSetId . '_' . $entry['code'];
            $isDefault = ($entry['code'] === $defaultLangCode);

            // Append required star to default-language tab label when field is required.
            // This mirrors the native Joomla <label> star since the editor markup
            // cannot receive a required="" attribute directly.
            $label = $this->buildTabLabel($entry['language']);
            if ($this->required && $isDefault) {
                $label .= ' <span class="star" aria-hidden="true">&nbsp;*</span>';
            }

            $html .= HTMLHelper::_('uitab.addTab', $tabSetId, $tabId, $label);

            $html .= $this->buildEditorHtml(
                editor: $editor,
                id:     $this->id . '_' . $entry['code'],
                name:   $this->buildLanguageInputName($entry['code']),
                value:  $values[$entry['code']] ?? '',
            );

            $html .= HTMLHelper::_('uitab.endTab');
        }

        $html .= HTMLHelper::_('uitab.endTabSet');

        return $html;
    }

    //  Private: element builders

    /**
     * Render the WYSIWYG editor for one language slot.
     *
     * Uses the typed properties EditorField exposes from the XML definition
     * ($this->width, $this->height, etc.), exactly as the parent field would.
     *
     * @param \Joomla\CMS\Editor\Editor $editor Fully initialised editor instance.
     * @param string $id HTML id for this editor instance.
     * @param string $name Form input name e.g. jform[desc_en_gb].
     * @param string $value Existing translation value.
     *
     *
     * @since  1.0.0
     */
    private function buildEditorHtml(
        \Joomla\CMS\Editor\Editor $editor,
        string $id,
        string $name,
        string $value,
    ): string {
        $buttons = isset($this->buttons) ? (bool) $this->buttons : true;

        return $editor->display(
            $name,
            $value,
            $this->width ?? '100%',
            $this->height ?? '250',
            (int) ($this->columns ?? 80),
            (int) ($this->rows ?? 15),
            $buttons,
            $id,
        );
    }

    /**
     * Build the tab label: flag image + native language name.
     *
     * HTMLHelper::_('uitab.addTab') accepts raw HTML for the label so the flag
     * can be embedded directly. Falls back to a Bootstrap badge when no flag
     * image is registered for the language.
     *
     * Note: the required star is NOT added here; it is appended by renderTabSet()
     * after this method returns, keeping label building and required logic separate.
     *
     * @param object $language Language object from LanguageHelper::getLanguages().
     *
     *
     * @since  1.0.0
     */
    private function buildTabLabel(object $language): string
    {
        $flag = !empty($language->image)
            ? HTMLHelper::_(
                'image',
                'mod_languages/' . $language->image . '.gif',
                $language->title_native,
                [
                    'title' => $language->title_native,
                    'style' => 'margin-right:4px;vertical-align:middle;',
                ],
                true,
            )
            : '<span class="badge bg-secondary me-1">'
                . htmlspecialchars($language->sef, ENT_COMPAT, 'UTF-8')
                . '</span>';

        return $flag . htmlspecialchars($language->title_native, ENT_COMPAT, 'UTF-8');
    }

    //  Private: helpers

    /**
     * Normalise the raw LanguageHelper result into a flat list of
     * ['code' => string, 'language' => object] pairs ready for iteration.
     *
     * @param array $languages Keyed by raw lang code e.g. 'en-GB'.
     *
     *
     * @since  1.0.0
     */
    private function normaliseLangEntries(array $languages): array
    {
        $entries = [];

        foreach ($languages as $langCode => $language) {
            $entries[] = [
                'code' => $this->formatLangCode($langCode),
                'language' => $language,
            ];
        }

        return $entries;
    }
}
