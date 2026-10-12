<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ListLimitsHelper;
use Joomla\CMS\Form\Field\TextField;
use SimpleXMLElement;

/**
 * The page sizes a SHOPPER may pick, for one tier of the listing chain
 * (category -> menu item -> global config).
 *
 * Comma-separated whole numbers ("12,24,48"); include 0 to offer "All". Kept
 * as a plain text input rather than a repeatable subform on purpose: the
 * safety lives in `validate="listLimits"` (which fires on com_config,
 * com_menus and category saves alike) plus ListLimitsHelper::parse() on read
 * - a subform's number inputs are only a client-side hint against a crafted
 * POST, while storing nested subform JSON inside the category's list_options
 * JSON would complicate every consumer for no added guarantee.
 *
 * Empty means "not set at this tier"; the chain floor's value renders as the
 * placeholder. When no tier sets it, the storefront keeps its stock dropdown
 * (this setting unset changes nothing).
 *
 * @since  1.9.0
 */
class ListLimitsField extends TextField
{
    use InheritsGlobalParam;

    protected $type = 'ListLimits';

    /**
     * Show the inherited sizes as the placeholder, prettified ("12, 24, All").
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
            $this->hint = $this->inheritLabel(ListLimitsHelper::label(ListLimitsHelper::parse($this->inheritedValue())));
        }

        return $return;
    }
}
