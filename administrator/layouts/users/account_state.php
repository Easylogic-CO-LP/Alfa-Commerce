<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A customer's Joomla account state in the customers list: the icon Joomla's users list draws for it (a tick for an
 * account that can sign in, a cross for one that cannot) and the state in words. Pure markup.
 *
 * @var array $displayData  ['state' => int] UsersModel::ACCOUNT_ENABLED, ACCOUNT_BLOCKED or ACCOUNT_AWAITING_ACTIVATION.
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\UsersModel;
use Joomla\CMS\Language\Text;

$state = (int) ($displayData['state'] ?? UsersModel::ACCOUNT_ENABLED);

[$icon, $words] = match ($state) {
    UsersModel::ACCOUNT_BLOCKED => ['unpublish', 'COM_ALFA_ACCOUNT_STATE_BLOCKED'],
    UsersModel::ACCOUNT_AWAITING_ACTIVATION => ['unpublish', 'COM_ALFA_ACCOUNT_STATE_AWAITING'],
    default => ['publish', 'COM_ALFA_ACCOUNT_STATE_ENABLED'],
};
?>
<span class="tbody-icon"><span class="icon-<?php echo $icon; ?>" aria-hidden="true"></span></span>
<span class="ms-1"><?php echo Text::_($words); ?></span>
