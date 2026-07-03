<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
?>

<div class="com-alfa-registration-complete">

    <h2><?php echo Text::_('COM_ALFA_REGISTRATION_COMPLETE_HEADING'); ?></h2>

    <p><?php echo Text::_('COM_ALFA_REGISTRATION_COMPLETE_MESSAGE'); ?></p>

    <a href="<?php echo Route::_('index.php?option=com_users&view=login'); ?>" class="btn btn-primary">
        <?php echo Text::_('COM_ALFA_REGISTRATION_COMPLETE_LOGIN_BUTTON'); ?>
    </a>

</div>