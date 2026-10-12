<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\NumberField;
use Joomla\CMS\Language\Text;
use SimpleXMLElement;

/**
 * Items-per-page for one tier of the listing chain
 * (category -> menu item -> global config).
 *
 * A free number, NOT a picker: the merchant's default and the shopper's menu of
 * page sizes are different decisions. This is "how many when nobody asked" and
 * may be any figure the shop wants (a 3-across grid wanting 9 is not served by
 * a fixed 5/10/15/25 list); what the SHOPPER may choose is the sibling
 * `items_limits` setting ({@see ListLimitsField}), on the same chain.
 *
 * 0 means "all on one page". Empty means "not set at this tier", rendered as
 * the inherit placeholder, the same wording the chain's select fields use.
 * Guarded at save by `validate="listLimit"` on every tier.
 *
 * @since  1.9.0
 */
class ListLimitField extends NumberField
{
    use InheritsGlobalParam;

    protected $type = 'ListLimit';

    /**
     * Show the inherited value as the placeholder: core does exactly this for
     * `useglobal` on free-value fields, so an empty box reads as "inheriting
     * N", not "nothing".
     *
     * @param SimpleXMLElement $element The field XML element
     * @param mixed $value The field value
     * @param string $group The field group control name
     *
     * @return bool True on success
     * @since  1.9.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $return = parent::setup($element, $value, $group);

        if ($return && $this->inherits()) {
            $inherited = $this->inheritedValue();
            $this->hint = $this->inheritLabel($inherited === '0' ? Text::_('JALL') : $inherited);
        }

        return $return;
    }
}
