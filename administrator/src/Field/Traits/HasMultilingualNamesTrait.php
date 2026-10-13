<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field\Traits;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\Registry\Registry;

/**
 * One derivation of a per-language input name, used by every Multilingual* field.
 *
 * A multilingual field draws one input per installed language by suffixing the language tag
 * onto the field's OWN segment: jform[short_desc] becomes jform[short_desc_el_gr]. The name is
 * derived from $this->name (which Joomla has already nested with the form control and any
 * group) and never rebuilt as a hardcoded 'jform[...]': a hardcoded prefix posts a nested field
 * at the top level, outside the array the controller reads, and the translation is dropped with
 * no error at all.
 *
 * Rendering and validation both come through here, so the name a field draws and the key it
 * reads back can never disagree.
 *
 * @since  2.0.0
 */
trait HasMultilingualNamesTrait
{
    /**
     * Normalise a Joomla language tag to the lower-snake form used as a key suffix.
     *
     * Examples: 'en-GB' -> 'en_gb', 'el-GR' -> 'el_gr'.
     *
     * @param string $langCode Raw tag as LanguageHelper returns it.
     *
     *
     * @since  2.0.0
     */
    protected function formatLangCode(string $langCode): string
    {
        return MultilingualHelper::normaliseTag(tag: $langCode);
    }

    /**
     * The default language's tag, in the same lower-snake form.
     *
     * The default is the first installed language, which is what every required-field check and
     * every alias derivation in the component already treats as the canonical one.
     *
     * @return string Empty only when no language is installed at all.
     *
     * @since  2.0.0
     */
    protected function defaultLangCode(): string
    {
        $first = array_key_first(LanguageHelper::getLanguages('lang_code'));

        return $first === null ? '' : $this->formatLangCode($first);
    }

    /**
     * The input name for one language slot, honouring the form control and any group.
     *
     * @param string $formattedLangCode e.g. 'en_gb'.
     *
     * @return string e.g. 'jform[short_desc_en_gb]', or 'jform[combinations][5][short_desc_en_gb]'
     *                inside a nested control.
     *
     * @since  2.0.0
     */
    protected function buildLanguageInputName(string $formattedLangCode): string
    {
        // A form built with ['control' => ''] yields a bare name with no bracket.
        return str_ends_with($this->name, ']')
            ? substr($this->name, 0, -1) . '_' . $formattedLangCode . ']'
            : $this->name . '_' . $formattedLangCode;
    }

    /**
     * Point the label's `for` at the default-language input.
     *
     * Core renders for="$this->id" (jform_name) while every input these fields draw is
     * jform_name_<langtag>, so the attribute named an element that does not exist: clicking the
     * label focused nothing, assistive tech saw unlabelled inputs, and Joomla's validator could
     * not find a label to mark invalid or to hang its inline message on, which is why a failed
     * multilingual field showed no message while its plain neighbours did.
     *
     *
     * @since  2.0.0
     */
    protected function getLabel(): string
    {
        $label = parent::getLabel();
        $default = $this->defaultLangCode();

        if ($default === '') {
            return $label;
        }

        return str_replace(
            'for="' . $this->id . '"',
            'for="' . $this->id . '_' . $default . '"',
            $label,
        );
    }

    /**
     * One language's submitted value, as validation must see it.
     *
     * ONE PATH. The per-language keys are declared storage fields
     * ({@see \Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper::declareStorageFields()}),
     * so by the time a field is validated its translations are in the set Joomla filtered, and
     * that filtered value is the one that will be STORED, which is what a required check has to
     * be decided on. There is deliberately no raw-POST route back in: a second source is how the
     * rendered name and the validated key came to disagree in the first place, and it cannot
     * serve a caller that never went through $_POST at all, such as the API.
     *
     * @param string $formattedLangCode e.g. 'en_gb'.
     * @param Registry|null $input The full filtered form data, as Form::validate() supplies it.
     * @param string|null $group The field's group path, as Form::validate() supplies it.
     *
     * @return string Empty when the form carried nothing for that language.
     *
     * @since  2.0.0
     */
    protected function submittedLanguageValue(
        string $formattedLangCode,
        ?Registry $input = null,
        ?string $group = null,
    ): string {
        if (!$input instanceof Registry) {
            return '';
        }

        // Joomla keys a grouped field as `<group>.<name>`, so the group has to be part of the
        // lookup or a bare key would read a same-named field at the top level instead.
        $key = self::keyWithGroup(
            key:   MultilingualHelper::buildLangFieldKey($this->fieldname, $formattedLangCode),
            group: $group ?? $this->group ?? '',
        );

        return $input->exists($key) ? (string) $input->get($key) : '';
    }

    /**
     * Prefix a flat key with its group path, when it has one.
     *
     * @param string $key The field's own key.
     * @param string $group The group path, empty at form root.
     *
     *
     * @since  2.0.0
     */
    private static function keyWithGroup(string $key, string $group): string
    {
        return $group === '' ? $key : $group . '.' . $key;
    }
}
