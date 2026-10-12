<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\TextField;
use Joomla\CMS\Form\FormHelper;
use SimpleXMLElement;

/**
 * A telephone number, anywhere a com_alfa form asks for one.
 *
 * The storefront's `tel` checkout field has validated and normalised numbers
 * for a while. Everything ELSE that wanted one (a carrier's sender number in
 * its own settings, a warehouse contact) got a bare text box, so the shop held
 * two kinds of phone number and the code consuming them could not tell which it
 * had. This is the same behaviour as a plain Joomla form field, so any
 * component form or plugin params.xml can use it:
 *
 *     <field name="admin_phone_number" type="telephone"
 *            addfieldprefix="Alfa\Component\Alfa\Administrator\Field"
 *            default_region="GR" require_mobile="0" />
 *
 * IN THE COMPONENT, NOT IN THE CHECKOUT PLUGIN, and that is the whole reason it
 * exists. Borrowing the checkout plugin's field class would make every settings
 * form that used it depend on a FORM-FIELD PLUGIN being installed and enabled,
 * and Joomla does not complain when a field type cannot be resolved: it
 * silently substitutes a plain TextField. A merchant who disabled that plugin
 * would get a text box that looks identical and validates nothing.
 *
 * IT BRINGS ITS OWN FILTER AND RULE. The checkout path attaches its validation
 * from a plugin hook that only runs for checkout fields, so a params.xml
 * borrowing it would render a telephone box that validates nothing. This
 * registers both prefixes itself and defaults both attributes, so declaring the
 * type is enough: nothing else to remember, and nothing to get wrong quietly.
 *
 * @see \Alfa\Component\Alfa\Administrator\Filter\TelephoneFilter for the
 *      normalisation to E.164, which is the value that gets stored.
 * @see \Alfa\Component\Alfa\Administrator\Rule\TelephoneRule for what is
 *      accepted, the region rules, and the messages a person reads.
 *
 * @since  1.10.0
 */
class TelephoneField extends TextField
{
    /**
     * Field type id: must match the XML `type` and this file's name.
     *
     * @var string
     * @since  1.10.0
     */
    protected $type = 'telephone';

    /**
     * @param SimpleXMLElement $element The field XML.
     * @param mixed $value The stored value.
     * @param string|null $group The field group.
     *
     * @return bool
     *
     * @since  1.10.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        if (!parent::setup($element, $value, $group)) {
            return false;
        }

        // The filter and the rule live beside this field, and a consumer should
        // not have to know that: declaring the type is the whole contract.
        //
        // BOTH, BECAUSE THEY DO DIFFERENT HALVES. Joomla runs filter() and then
        // validate(), and the model returns the FILTERED data, so the filter
        // is what makes a number canonical, and the rule is what refuses one
        // that is not acceptable. A field carrying only the rule would validate
        // correctly and still store whatever spacing was typed.
        FormHelper::addFilterPrefix('Alfa\\Component\\Alfa\\Administrator\\Filter');
        FormHelper::addRulePrefix('Alfa\\Component\\Alfa\\Administrator\\Rule');

        if (!$this->element['filter']) {
            $this->element->addAttribute('filter', 'telephone');
        }

        if (!$this->element['validate']) {
            $this->element->addAttribute('validate', 'telephone');
            $this->validate = 'telephone';
        }

        // A browser that knows this is a phone number offers the right keypad
        // and the right autofill; one that does not is unaffected.
        $this->inputmode = $this->inputmode ?: 'tel';
        $this->autocomplete = $this->autocomplete ?: 'tel';

        return true;
    }

    /**
     * Rendered as a real `tel` input rather than a text one.
     *
     * @return string
     *
     * @since  1.10.0
     */
    protected function getInput()
    {
        return str_replace('type="text"', 'type="tel"', parent::getInput());
    }
}
