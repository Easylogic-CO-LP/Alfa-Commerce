<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Language\LanguageHelper;

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
	->useScript('keepalive')
	->useScript('form.validate')
	->useScript('bootstrap.modal');


$input = Factory::getApplication()->getInput();

// Build the preview-modal data — combos of language × recipient. Only
// makes sense once the status row exists (we need an id to load its
// saved positions JSON). Shown as tabs inside the modal, each tab
// holding an iframe that hits OrderstatusController::previewEmail.
$statusId          = (int) ($this->item->id ?? 0);
$canPreviewEmail   = $statusId > 0;
$previewLanguages  = $canPreviewEmail ? (LanguageHelper::getLanguages('lang_code') ?: []) : [];
$previewRecipients = ['customer', 'admin'];
$previewBaseUri    = Uri::base(true) . '/index.php?option=com_alfa&task=orderstatus.previewEmail&format=raw&id=' . $statusId;

?>

<form
	action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" enctype="multipart/form-data" name="adminForm" id="orderstatus-form" class="form-validate form-horizontal">

	<div class="row title-alias form-vertical mb-3">
        <div class="col-12">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <div class="col-12">
            <?php echo $this->form->renderField('name_customer'); ?>
        </div>
    </div>


	<?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_ORDER_STATUS', true)); ?>
	<div class="row">
		<div class="col-lg-9">
			<fieldset class="adminform">

				<?php echo $this->form->renderField('color'); ?>
				<?php echo $this->form->renderField('bg_color'); ?>
				<?php echo $this->form->renderField('stock_operation'); ?>

				<?php echo $this->form->renderField('id'); ?>

	            <?php echo $this->form->renderField('created_by'); ?>
				<?php echo $this->form->renderField('modified_by'); ?>
				<?php echo $this->form->renderField('modified'); ?>

			</fieldset>
		</div>
		 <div class="col-lg-3">
            <?php echo $this->form->renderField('state'); ?>

        </div>
	</div>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php // Role flags — see forms/orderstatus.xml fieldset name="roles".
	      // Rendered via renderFieldset so adding/removing role flags is a
	      // one-line XML change with no template edit needed. ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'roles', Text::_('COM_ALFA_FORM_FIELDSET_ORDER_STATUS_ROLES')); ?>
		<fieldset class="adminform">
			<?php echo $this->form->renderFieldset('roles'); ?>
		</fieldset>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

	<?php // Per-status email templates — see forms/orderstatus.xml fieldset
	      // name="emails". notify_customer / notify_admin gate the
	      // subject/body inputs via showon; OrderEmailHelper reads these
	      // on order-status transitions. ?>
	<?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'emails', Text::_('COM_ALFA_FORM_FIELDSET_ORDER_STATUS_EMAILS')); ?>
		<fieldset class="adminform">

			<?php // Help note: how layouts + positions + tokens fit together.
			      // Collapsed <details> (not a banner) so it sits quietly above
			      // the recipient tabs; applies to both. ?>
			<details class="mb-3">
				<summary style="cursor:pointer;font-weight:600;">
					<span class="icon-info-circle" aria-hidden="true"></span>
					<?php echo Text::_('COM_ALFA_ORDEREMAIL_POSITIONS_NOTE_TITLE'); ?>
				</summary>
				<div class="mt-2">
					<?php // %1$s = active admin template element (e.g. 'atum') so
					      // the override steps name the real template + exact path. ?>
					<?php echo Text::sprintf('COM_ALFA_ORDEREMAIL_POSITIONS_NOTE_BODY', Factory::getApplication()->getTemplate()); ?>
				</div>
			</details>

			<?php // Nested tabs — Customer / Admin. Form XML splits into
			      // emails_customer + emails_admin fieldsets so the renderer
			      // can iterate each cleanly. ?>
			<?php // No 'recall' on nested tabsets — see MultilingualEditorField note. ?>
			<?php echo HTMLHelper::_('uitab.startTabSet', 'emailsTab', ['active' => 'emails_customer', 'breakpoint' => 768]); ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'emailsTab', 'emails_customer', Text::_('COM_ALFA_FORM_FIELDSET_ORDER_STATUS_EMAILS_CUSTOMER')); ?>
					<?php echo $this->form->renderFieldset('emails_customer'); ?>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'emailsTab', 'emails_admin', Text::_('COM_ALFA_FORM_FIELDSET_ORDER_STATUS_EMAILS_ADMIN')); ?>
					<?php echo $this->form->renderFieldset('emails_admin'); ?>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>

			<?php echo HTMLHelper::_('uitab.endTabSet'); ?>

		</fieldset>
	<?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

	<?php echo $this->form->renderControlFields(); ?>

</form>


<?php if ($canPreviewEmail) :
    // One Preview + one Send-test modal PER recipient. The iframe src is
    // empty here — the composer's button sets it on click, appending the
    // ACTIVE language tab (&lang=…), so there are no in-modal language /
    // recipient pickers. previewEmail renders that one combo directly;
    // sendTestForm uses the passed recipient + lang as hidden inputs.
    foreach ($previewRecipients as $rcpt) :
        $rcptLabel = Text::_('COM_ALFA_FORM_FIELDSET_ORDER_STATUS_EMAILS_' . strtoupper($rcpt));

        $rcptLabelEsc = htmlspecialchars($rcptLabel, ENT_COMPAT, 'UTF-8');

        echo HTMLHelper::_(
            "bootstrap.renderModal",
            "alfaEmailPreviewModal_" . $rcpt,
            [
                "title"      => Text::_("COM_ALFA_ORDERSTATUS_PREVIEW_EMAIL_TITLE") . ' — ' . $rcptLabel,
                "width"      => "85%",
                "bodyHeight" => "80vh",
            ],
            // $body — separate 3rd arg to renderModal, NOT a params key.
            '<iframe id="aecPreviewFrame_' . $rcpt . '" src="about:blank" title="' . $rcptLabelEsc
                . '" style="width:100%;height:78vh;border:0;display:block"></iframe>',
        );

        echo HTMLHelper::_(
            "bootstrap.renderModal",
            "alfaEmailSendTestModal_" . $rcpt,
            [
                "title"      => Text::_("COM_ALFA_ORDEREMAIL_TEST_MODAL_TITLE") . ' — ' . $rcptLabel,
                "width"      => "50%",
                "bodyHeight" => "70vh",
            ],
            '<iframe id="aecTestFrame_' . $rcpt . '" src="about:blank" title="' . $rcptLabelEsc
                . '" style="width:100%;height:68vh;border:0;display:block"></iframe>',
        );
    endforeach;
endif; ?>
