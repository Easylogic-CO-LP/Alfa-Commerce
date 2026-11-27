<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemSections;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * A list of product page sections saves only rows the page can show: a row with a body needs a title in the shop's
 * default language, the one every other language falls back to. The product page leaves a section without a title
 * out, so a row saved without one would be kept and never seen; the save is refused instead, naming each such row by
 * its place in the list and its key.
 *
 * On the product's Sections list and on the general sections of Options alike, from the form and from the REST
 * resource, since the rule travels with the field:
 *
 *     <field name="sections" type="subform" multiple="true" validate="SectionRows" replaces="section_key" ...>
 *
 * `replaces` names the row's field that holds the general section it takes the place of: such a row keeps that
 * section's title and needs none of its own. Without the attribute the row's `key` field is its own key.
 *
 * @see ItemSections::untitledRows() for the judgement.
 *
 * @since  2.3.0
 */
class SectionRowsRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The posted rows.
     * @param string $group The field group.
     * @param Registry $input The whole posted data.
     * @param Form $form The form.
     *
     * @return bool True when every row with a body has a title in the default language.
     *
     * @throws UnexpectedValueException Naming the rows that have none.
     *
     * @since  2.3.0
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        $replaces = (string) ($element['replaces'] ?? '');
        $untitled = ItemSections::untitledRows(posted: $value, replaces: $replaces, keyField: $replaces !== '' ? $replaces : 'key');

        if ($untitled === []) {
            return true;
        }

        $rows = implode(', ', array_map(
            static fn (array $row): string => $row['row'] . ($row['key'] !== '' ? ' (' . $row['key'] . ')' : ''),
            $untitled,
        ));
        $code = MultilingualHelper::getDefaultLanguageCode();
        $language = (string) (LanguageHelper::getLanguages('lang_code')[$code]->title ?? $code);

        throw new UnexpectedValueException(Text::sprintf('COM_ALFA_SECTION_ROWS_NEED_TITLE', Text::_((string) $element['label']), $rows, $language));
    }
}
