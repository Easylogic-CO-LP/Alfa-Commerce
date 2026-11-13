<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * The RESULT screen for a side-effect action (a message/refresh, no layout).
 * Shows the outcome with the buttons the plugin declared (addButton); if it
 * declared none, a default OK/Close is shown. The buttons are the explicit
 * trigger: the admin reads the result and clicks (no rushed auto-dismiss).
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Action\HtmlView $this
 */
$event = $this->actionEvent;

$message = method_exists($event, 'getMessage') ? (string) $event->getMessage() : '';
// isSuccess() is what the action events actually expose; setError() flips it.
// (There is no getError(); probing for one made every failure render green.)
$isError = method_exists($event, 'isSuccess') ? !$event->isSuccess() : false;
$reload  = method_exists($event, 'getRefresh') ? (bool) $event->getRefresh() : false;

if ($message === '') {
    $message = Text::_('COM_ALFA_ACTION_DONE');
}

// Plugin-declared buttons, or a sensible default (OK reloads, Close otherwise).
$buttons = method_exists($event, 'getButtons') ? $event->getButtons() : [];

if (!$buttons) {
    $buttons = [[
        'label'  => $reload ? Text::_('COM_ALFA_ACTION_OK') : Text::_('JCLOSE'),
        'reload' => $reload,
    ]];
}

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// The action page's stylesheet: the result's buttons follow its message, never over it.
Factory::getApplication()->getDocument()->getWebAssetManager()->useStyle('com_alfa.action-page');
?>
<div class="alfa-action-notice">
    <div class="alfa-action-notice__card alfa-action-notice__card--<?php echo $isError ? 'error' : 'ok'; ?>">
        <span class="alfa-action-notice__icon" aria-hidden="true">
            <span class="<?php echo $isError ? 'icon-warning' : 'icon-checkmark-circle'; ?>"></span>
        </span>
        <p class="alfa-action-notice__msg"><?php echo $esc($message); ?></p>
    </div>
</div>
<?php echo LayoutHelper::render('action.buttons', ['buttons' => $buttons], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
