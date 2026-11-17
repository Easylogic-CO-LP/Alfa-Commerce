<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * A required form field that cannot be filled in now refuses every submission, naming the field.
 *
 * FieldsHelper::prepareForm() puts this rule on a required field whose form-field plugin is unavailable in the request
 * (missing, disabled, not loaded for the visitor's access, or failing to start): the form fails closed instead of
 * storing the record without the field (2.2.1). The visitor reads that the field cannot be filled in now;
 * staff read the plugin and the reason in the log.
 *
 * @since  2.2.1
 */
class UnavailableRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The posted value, whatever it is.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data.
     * @param Form|null $form The form.
     *
     * @return bool Never: the rule always refuses.
     *
     * @throws UnexpectedValueException With the visitor's words, naming the field.
     *
     * @since  2.2.1
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // The storefront forms are built in requests that never load the administrator strings.
        Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

        $label = trim((string) $element['label']);

        throw new UnexpectedValueException(Text::sprintf('COM_ALFA_FORM_FIELD_UNAVAILABLE', $label !== '' ? $label : (string) $element['name']));
    }
}
