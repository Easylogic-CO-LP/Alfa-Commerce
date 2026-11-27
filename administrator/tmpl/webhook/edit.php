<?php

/**
 * A webhook's edit screen, a tab a fieldset: General (name, https address, state), Topics (the changes it is told
 * of, each with when it is told) and Security (the signing secret, shown masked and never read back, New secret, and
 * how a request is signed). The secret is made by the shop when the webhook is new or staff press New secret, and
 * shown once in the message after that save.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Outbound\WebhookSignature;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('webcomponent.toolbar-button')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

$item = $this->item;
$isNew = (int) ($item->id ?? 0) === 0;
$canEdit = AlfaHelper::can(section: 'webhooks', action: 'edit', user: $this->getCurrentUser());
$fieldsets = $this->form->getFieldsets();
$tab = static fn (string $fieldset): string => Text::_((string) ($fieldsets[$fieldset]->label ?? $fieldset));
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) ($item->id ?? 0)); ?>"
      method="post" name="adminForm" id="webhook-form" class="form-validate form-horizontal">

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', $tab('general')); ?>
    <div class="row">
        <div class="col-lg-7">
            <?php echo $this->form->renderField('name'); ?>
            <?php echo $this->form->renderField('url'); ?>
            <?php echo $this->form->renderField('state'); ?>
        </div>
        <div class="col-lg-5">
            <?php echo $this->form->renderField('about'); ?>
            <?php if (!$isNew && !empty($item->last_attempt_at)) : ?>
                <p class="small mb-0">
                    <?php echo Text::_('COM_ALFA_WEBHOOK_LAST_DELIVERY'); ?>
                    <span class="alfa-nowrap"><?php echo HTMLHelper::_('date', (string) $item->last_attempt_at, Text::_('DATE_FORMAT_LC6')); ?></span>,
                    <span class="badge <?php echo (int) $item->last_ok === 1 ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $this->escape(str_starts_with((string) $item->last_result, 'COM_ALFA_') ? Text::_((string) $item->last_result) : (string) $item->last_result); ?></span>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'topics', $tab('topics')); ?>
    <div class="row">
        <div class="col-lg-7">
            <?php echo $this->form->renderFieldset('topics'); ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'security', $tab('security')); ?>
    <div class="row">
        <div class="col-lg-7">
            <?php echo $this->form->renderField('secret_mask'); ?>
            <p class="small text-muted"><?php echo Text::sprintf('COM_ALFA_WEBHOOK_SIGNATURE_HINT', WebhookSignature::HEADER, WebhookSignature::TOLERANCE_SECONDS); ?></p>
        </div>
        <div class="col-lg-5">
            <?php echo $this->form->renderField('secret_note'); ?>
            <?php if (!$isNew && $canEdit) : ?>
                <?php // The button posts this form as Apply does, with new_secret set: the webhook is saved and the screen stays open with the new secret in the message. ?>
                <joomla-toolbar-button task="webhook.newsecret" form="webhook-form" form-validation>
                    <button type="button" class="btn btn-secondary">
                        <span class="icon-key" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_WEBHOOK_NEW_SECRET'); ?>
                    </button>
                </joomla-toolbar-button>
                <div class="form-text"><?php echo Text::_('COM_ALFA_WEBHOOK_NEW_SECRET_DESC'); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php echo $this->form->getInput('new_secret'); ?>
    <input type="hidden" name="task" value=""/>
    <?php echo $this->form->getInput('id'); ?>
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
