<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

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

<style>
    .alfa-action-notice { display: flex; align-items: center; justify-content: center; min-height: 10rem; padding: 1.5rem 1.5rem 0.5rem; }
    .alfa-action-notice__card { text-align: center; max-width: 24rem; }
    .alfa-action-notice__icon { font-size: 2.6rem; line-height: 1; display: inline-block; margin-bottom: 0.6rem; }
    .alfa-action-notice__card--ok .alfa-action-notice__icon { color: var(--success-text-emphasis, #2a8a3e); }
    .alfa-action-notice__card--error .alfa-action-notice__icon { color: var(--danger-text-emphasis, #c5391b); }
    .alfa-action-notice__msg { font-size: 0.95rem; color: var(--body-color, #1f2327); margin: 0; }
    /* The notice's buttons centre rather than the default right-align. */
    .alfa-action-notice + .alfa-action-buttons { justify-content: center; border-top: 0; }
</style>
