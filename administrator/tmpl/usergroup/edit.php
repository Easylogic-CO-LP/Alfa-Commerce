<?php
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
   ->useScript('form.validate');
?>
<form
    action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
    method="post"
    enctype="multipart/form-data"
    name="adminForm"
    id="usergroup-form"
    class="form-validate form-horizontal">

    <?php // ── Hidden fields required for correct save (UPDATE vs INSERT) ── ?>
    <?php echo $this->form->renderField('id'); ?>
    <?php echo $this->form->renderField('usergroup_id'); ?>

    <div class="row title-alias form-vertical mb-3">
        <div class="col-12">
            <?php echo $this->form->renderField('name'); ?>
        </div>
    </div>

    <div class="row">

        <div class="col-lg-12">

            <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'usergroup', 'recall' => true]); ?>
            <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'usergroup', Text::_('COM_ALFA_TAB_USERGROUP', true)); ?>

            <?php echo $this->form->renderField('prices_enable'); ?>

            <?php $pricesDisplay = $this->item->prices_enable == '1' ? '' : 'display:none'; ?>
            <div
                data-show-when="jform[prices_enable]"
                data-show-value="1"
                style="<?php echo $pricesDisplay; ?>"
             >
                <?php echo $this->form->renderFieldset('prices'); ?>
            </div>

            <?php echo HTMLHelper::_('uitab.endTab'); ?>
            <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

        </div>

    </div>

    <?php echo $this->form->renderControlFields(); ?>

</form>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // Finds all [data-show-when] elements and toggles their visibility
    // based on the value of the named input.
    //
    // Usage in HTML:
    //   <div data-show-when="jform[my_field]" data-show-value="1"> ... </div>

    function applyConditionalVisibility() {
        document.querySelectorAll('[data-show-when]').forEach(function (el) {
            const fieldName = el.dataset.showWhen;
            const showValue = el.dataset.showValue;
            const input     = document.querySelector(`[name="${fieldName}"]:checked`)
                           ?? document.querySelector(`[name="${fieldName}"]`);

            el.style.display = (input && input.value === showValue) ? '' : 'none';
        });
    }

    // Set initial state on page load
    applyConditionalVisibility();

    // Re-evaluate whenever any input inside the form changes
    document.getElementById('usergroup-form').addEventListener('change', applyConditionalVisibility);

});
</script>