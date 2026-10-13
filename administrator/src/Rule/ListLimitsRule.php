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
 * Validates the "page sizes" list: comma-separated whole numbers ("12,24,48,0"),
 * or empty (= inherit / storefront default set). Spaces around entries are
 * fine, and EMPTY entries (a trailing or doubled comma) are ignored, exactly
 * what ListLimitsHelper::parse() does on read, so nothing the reader accepts
 * is unsavable. Anything non-numeric rejects the save. 0 offers "All".
 *
 * Same reasoning as {@see ListLimitRule}: a rule travels with the field XML and
 * therefore fires on com_config, com_menus AND category saves alike: the only
 * seam that guards all three tiers. ListLimitsHelper::parse() stays defensive
 * on read regardless, since the DB can be written without a form.
 *
 * @since  2.0.0
 */
class ListLimitsRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element
     * @param mixed $value The posted value
     * @param string $group The field group
     * @param Registry $input The whole posted data
     * @param Form $form The form
     *
     * @return bool True when every comma-separated entry is a whole number
     * @since  2.0.0
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // A crafted POST can hand an array where a scalar belongs.
        if ($value !== null && !\is_scalar($value)) {
            return false;
        }

        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        foreach (explode(',', (string) $value) as $entry) {
            $entry = trim($entry);

            if ($entry !== '' && preg_match('/^\d+$/', $entry) !== 1) {
                return false;
            }
        }

        return true;
    }
}
