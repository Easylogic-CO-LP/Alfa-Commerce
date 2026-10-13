<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;
use SimpleXMLElement;

/**
 * Validates one items-per-page value: a whole number, or empty (= inherit).
 *
 * A form RULE, not model code, on purpose: the menu and global-config tiers are
 * saved by com_menus and com_config, whose models com_alfa cannot reach, but
 * both run Form::validate() against the XML this rule is attached to, so the
 * same guard fires on every tier's save. 0 is valid (= show everything).
 *
 * @since  2.0.0
 */
class ListLimitRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element
     * @param mixed $value The posted value
     * @param string $group The field group
     * @param Registry $input The whole posted data
     * @param Form $form The form
     *
     * @return bool True when the value is a whole number or empty
     * @since  2.0.0
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        if ($value === null || $value === '') {
            return true;
        }

        // A crafted POST can hand an array where a scalar belongs.
        return \is_scalar($value) && preg_match('/^\d+$/', (string) $value) === 1;
    }
}
