<?php

/**
 * A webhook's edit screen: its name, https address, topics and state; a secret made by the shop when the webhook is
 * new or staff ask for a new one, shown once in the message after that save.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\WebhookSignature;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

$item = $this->item;
$isNew = (int) ($item->id ?? 0) === 0;
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) ($item->id ?? 0)); ?>"
      method="post" name="adminForm" id="webhook-form" class="form-validate form-horizontal">

    <div class="row">
        <div class="col-lg-7">
            <?php echo $this->form->renderField('name'); ?>
            <?php echo $this->form->renderField('url'); ?>
            <?php echo $this->form->renderField('topics'); ?>
            <?php echo $this->form->renderField('state'); ?>
            <?php if (!$isNew) : ?>
                <?php echo $this->form->renderField('new_secret'); ?>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_($isNew ? 'COM_ALFA_WEBHOOK_SECRET_NEW_HINT' : (!empty($item->has_secret) ? 'COM_ALFA_WEBHOOK_SECRET_SET_HINT' : 'COM_ALFA_WEBHOOK_SECRET_NONE_HINT')); ?>
            </div>
            <p class="small text-muted"><?php echo Text::sprintf('COM_ALFA_WEBHOOK_SIGNATURE_HINT', WebhookSignature::HEADER, WebhookSignature::TOLERANCE_SECONDS); ?></p>
            <?php if (!$isNew && !empty($item->last_attempt_at)) : ?>
                <p class="small mb-0">
                    <?php echo Text::_('COM_ALFA_WEBHOOK_LAST_DELIVERY'); ?>
                    <span class="alfa-nowrap"><?php echo HTMLHelper::_('date', (string) $item->last_attempt_at, Text::_('DATE_FORMAT_LC6')); ?></span>,
                    <span class="badge <?php echo (int) $item->last_ok === 1 ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $this->escape(str_starts_with((string) $item->last_result, 'COM_ALFA_') ? Text::_((string) $item->last_result) : (string) $item->last_result); ?></span>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <input type="hidden" name="task" value=""/>
    <?php echo $this->form->getInput('id'); ?>
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
