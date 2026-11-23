<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;

/**
 * Whether the site accepts customer sign-ups, read in one place: the storefront's sign-up form guards its submit with
 * it (com_users' own model, which creates the account, never reads the option), and the setup checklist decides with
 * it whether to make the sign-up page, so the two never disagree.
 *
 * @since  2.3.0
 */
final class SignUpHelper
{
    /**
     * Whether Joomla accepts sign-ups (Users: Options, Allow User Registration). A site that has not saved the option
     * reads Joomla's own default, which is No.
     *
     *
     * @since  2.3.0
     */
    public static function open(): bool
    {
        return (bool) ComponentHelper::getParams('com_users')->get('allowUserRegistration', 0);
    }
}
