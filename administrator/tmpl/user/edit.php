<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
   ->useScript('form.validate')
   ->useScript('com_alfa.validate.engine')
   ->useStyle('com_alfa.validate');

$linkedUser = !empty($this->item->id_user);

$this->linkedUser = $linkedUser;
$this->editUrl    = $linkedUser
    ? $this->item->joomla_edit_url . '&' . Session::getFormToken() . '=1'
    : '';
?>

<form
    action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
    method="post"
    enctype="multipart/form-data"
    name="adminForm"
    id="user-form"
    class="form-validate form-horizontal">

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'details', 'recall' => true]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'details', Text::_('COM_ALFA_TAB_USER_DETAILS')); ?>

    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">

                <?php echo $this->form->renderField('id'); ?>
                <?php echo $this->form->renderField('id_user'); ?>
                <?php echo $this->form->renderField('note'); ?>

                <?php if ($linkedUser) : ?>
                    <div class="control-group">
                        <div class="control-label">
                            <label><?php echo Text::_('COM_ALFA_FIELD_JOOMLA_ACCOUNT_LABEL'); ?></label>
                        </div>
                        <div class="controls">
                            <?php echo $this->loadTemplate('user_popup'); ?>
                        </div>
                    </div>
                <?php endif; ?>

            </fieldset>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php if ($linkedUser) : ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'userinfo', Text::_('COM_ALFA_TITLE_USERINFO_VIEW_USERINFO')); ?>
        <?php
        // Rows list + per-row Edit popup (modal iframe), the order-popups
        // doctrine: one clean view per action; the popup loads the checkout
        // fields with the showon + validation engines like the storefront.
        $uinfoRows = UserInfoHelper::getRowsByUser(userId: (int) $this->item->id_user);
        $uinfoAddUrl = Route::_(sprintf(
            'index.php?option=com_alfa&view=user&layout=edit_userinfo&tmpl=component&user_id=%d&info_id=0',
            (int) $this->item->id_user,
        ));
        ?>
        <div id="admin-userinfo"
             data-user-id="<?php echo (int) $this->item->id_user; ?>"
             data-token="<?php echo Session::getFormToken(); ?>">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3><?php echo Text::_('COM_ALFA_TITLE_USERINFO_VIEW_USERINFO'); ?>
                    <?php if ($uinfoRows) : ?>
                        <span class="badge bg-secondary"><?php echo count($uinfoRows); ?></span>
                    <?php endif; ?>
                </h3>
                <a href="#userinfoAddModal" data-bs-toggle="modal" class="btn btn-success">
                    <span class="icon-plus"></span> <?php echo Text::_('COM_ALFA_USERINFO_ADD'); ?>
                </a>
            </div>

            <?php if (!$uinfoRows) : ?>
                <p class="text-muted"><?php echo Text::_('COM_ALFA_USERINFO_EMPTY'); ?></p>
            <?php else : ?>
                <ul class="list-group mb-3">
                    <?php foreach ($uinfoRows as $uinfoRow) : ?>
                        <?php
                        $frozen = UserInfoHelper::isUsedInOrders(id: (int) $uinfoRow->id);
                        $modalId = 'userinfoModal' . (int) $uinfoRow->id;
                        $editUrl = Route::_(sprintf(
                            'index.php?option=com_alfa&view=user&layout=edit_userinfo&tmpl=component&user_id=%d&info_id=%d',
                            (int) $this->item->id_user,
                            (int) $uinfoRow->id,
                        ));
                        ?>
                        <li class="list-group-item d-flex align-items-center gap-2" data-userinfo-id="<?php echo (int) $uinfoRow->id; ?>">
                            <span class="flex-grow-1">
                                <?php echo $this->escape(UserInfoHelper::label($uinfoRow)); ?>
                                <?php if ($frozen) : ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_USERINFO_BADGE_IN_ORDERS'); ?></span>
                                <?php endif; ?>
                            </span>
                            <a href="#<?php echo $modalId; ?>" data-bs-toggle="modal" class="btn btn-sm btn-outline-secondary">
                                <?php echo Text::_('JACTION_EDIT'); ?>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-uinfo-delete <?php echo $frozen ? 'disabled' : ''; ?>>
                                <?php echo Text::_('JACTION_DELETE'); ?>
                            </button>
                            <?php echo HTMLHelper::_('bootstrap.renderModal', $modalId, [
                                'title'      => Text::_('COM_ALFA_USERINFO_EDIT'),
                                'url'        => $editUrl,
                                'width'      => '80%',
                                'bodyHeight' => '70vh',
                            ]); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php echo HTMLHelper::_('bootstrap.renderModal', 'userinfoAddModal', [
                'title'      => Text::_('COM_ALFA_USERINFO_ADD'),
                'url'        => $uinfoAddUrl,
                'width'      => '80%',
                'bodyHeight' => '70vh',
            ]); ?>
        </div>
        <script>
        (function () {
            'use strict';

            var panel = document.getElementById('admin-userinfo');

            if (!panel) {
                return;
            }

            panel.addEventListener('click', function (event) {
                var button = event.target.closest('[data-uinfo-delete]');

                if (!button) {
                    return;
                }

                var row = button.closest('[data-userinfo-id]');
                var data = new FormData();
                data.append(panel.dataset.token, '1');
                data.append('user_id', panel.dataset.userId);
                data.append('id', row.dataset.userinfoId);
                fetch('index.php?option=com_alfa&task=user.deleteUserinfo&format=json', { method: 'POST', body: data })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (json && json.success) { window.location.reload(); } else { alert((json && json.message) || 'Failed'); }
                    });
            });
        })();
        </script>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php endif; ?>

    <?php
// CONTRIBUTED TABS. A plugin that loads a named <fieldset> into this form through
// onAlfaAdminForm gets it rendered here as its own tab; same rule as every Alfa edit
// screen: a contributed named fieldset is a new tab. The template's OWN named fieldsets
// are listed in $ownFieldsets and skipped, so nothing renders twice. This template
// renders its fields individually from an unnamed fieldset, so it owns none by name.
$ownFieldsets = [];
foreach ($this->form->getFieldsets() as $fieldset) {
    if (in_array($fieldset->name, $ownFieldsets, true)) {
        continue;
    }
    $fieldsetLabel = !empty($fieldset->label)
        ? Text::_($fieldset->label)
        : ucwords(str_replace('_', ' ', $fieldset->name));

    echo HTMLHelper::_('uitab.addTab', 'myTab', 'plg-' . $fieldset->name, $fieldsetLabel);
    echo '<div class="row"><div class="col-12"><fieldset class="options-form"><legend>'
        . $fieldsetLabel . '</legend><div>'
        . $this->form->renderFieldset($fieldset->name)
        . '</div></fieldset></div></div>';
    echo HTMLHelper::_('uitab.endTab');
}

// Customer Credit Account: the customer liability ledger. Only for
//    profiles linked to a real Joomla account (guests have no wallet).
if ($linkedUser) {
    echo HTMLHelper::_('uitab.addTab', 'myTab', 'credit', Text::_('COM_ALFA_TAB_USER_CREDIT'));
    echo $this->loadTemplate('credit');
    echo HTMLHelper::_('uitab.endTab');
}
?><?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="jform[id]"      value="<?php echo (int) $this->item->id; ?>" />
    <input type="hidden" name="jform[id_user]" value="<?php echo (int) ($this->item->id_user ?? 0); ?>" />
    <input type="hidden" name="task" value="" />
    <?php echo HTMLHelper::_('form.token'); ?>

</form>

<?php if ($linkedUser) : ?>
    <?php // SIBLING form for the credit actions. It cannot live inside
          // adminForm (nested forms are invalid HTML), so the Credit tab's
          // fields point at it with form="alfa-credit-form": valid HTML5.
          // It deliberately carries NO hidden `task`: the buttons set the
          // task through formaction, and a POSTed empty task would override
          // the query string and drop the request on the default view. ?>
    <form id="alfa-credit-form" action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post">
        <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>"/>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
<?php endif; ?>