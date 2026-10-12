<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
    ->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

// Default-language input id of the (multilingual) name field, used as the
// source for auto-generating the machine field_name below.
$defaultTitleInputId = 'jform_name_' . MultilingualHelper::getDefaultLanguageTag();

$ignoreFieldsets = ['general', 'publish', 'showon'];

$fieldsets = $this->form->getFieldsets();

?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Auto-generate the machine field_name from the default-language title; the
  // server re-derives + validates it on save, so this is purely UX.
  const titleInput = document.getElementById('<?php echo $this->escape($defaultTitleInputId); ?>');
  const nameInput  = document.getElementById('jform_field_name');

  if (!titleInput || !nameInput) {
    return;
  }

  let auto = nameInput.value.trim() === '';

  const slugify = (value) => value
    .toString().toLowerCase().trim()
    .normalize('NFD').replace(/\p{M}/gu, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  nameInput.addEventListener('input', () => {
    auto = nameInput.value.trim() === '';
  });

  titleInput.addEventListener('input', () => {
    if (auto) {
      nameInput.value = slugify(titleInput.value);
    }
  });
});
</script>

<form
    action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
    method="post" enctype="multipart/form-data" name="adminForm" id="itemfield-form" class="form-validate form-horizontal">

    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name'); ?>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_ITEMFIELD', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <fieldset class="adminform">
                <legend><?php echo Text::_('COM_ALFA_FIELDSET_ITEMFIELD'); ?></legend>
                <?php echo $this->form->renderFieldset('general'); ?>
            </fieldset>
        </div>
        <div class="col-lg-3">
            <?php echo $this->form->renderFieldset('publish'); ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'values', Text::_('COM_ALFA_TAB_ITEMFIELD_VALUES', true)); ?>
    <?php echo $this->loadTemplate('values'); ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php
    // Render the type plugin's params (and any other plugin-contributed
    // fieldsets) as their own tabs: a plugin declaring several fieldsets in
    // its params.xml gets several tabs, automatically.
    foreach ($fieldsets as $fieldsetName => $fieldset) {
        if (in_array($fieldsetName, $ignoreFieldsets)) {
            continue;
        }

        $fieldSetLabel = isset($fieldset->label) && !empty($fieldset->label)
            ? Text::_($fieldset->label)
            : ucwords(str_replace('_', ' ', $fieldsetName));

        echo HTMLHelper::_('uitab.addTab', 'myTab', $fieldsetName, Text::_($fieldSetLabel), true);
        ?>
        <div class="row">
            <div class="col-lg-12">
                <fieldset class="adminform">
                    <?php echo $this->form->renderFieldset($fieldsetName); ?>
                </fieldset>
            </div>
        </div>
        <?php
        echo HTMLHelper::_('uitab.endTab');
    }
    ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'visibility', Text::_('COM_ALFA_TAB_ITEMFIELD_VISIBILITY')); ?>
    <div class="row">
        <div class="col-lg-12">
            <fieldset class="adminform">
                <p class="text-muted small"><?php echo Text::_('COM_ALFA_ITEMFIELD_VISIBILITY_HELP'); ?></p>
                <div class="row">
                    <div class="col-md-6"><?php echo $this->form->renderField('showon_effect'); ?></div>
                </div>
                <?php echo $this->form->renderField('showon'); ?>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php echo $this->form->renderControlFields(); ?>

</form>
