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
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Router\Route;
use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;

/**
 * The Combination Studio as an INDEPENDENT popup (order-edit pattern):
 * loaded in an iframe (layout=edit_combination&tmpl=component), with its OWN
 * form and save (item.saveCombination). On a successful save it notifies the
 * parent item screen via postMessage (alfa:combination-action) which closes
 * the dialog and re-renders through the explicit item.reload task.
 *
 * Body sections come from the shared combinations.detail layout; the gallery
 * is the variant's OWN MediaZone (#__alfa_media, origin 'combination'):
 * full upload parity with the item; no own media = the frontend inherits the
 * item gallery.
 */

$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.combinations')
    ->useScript('com_alfa.combinations')
    ->useScript('com_alfa.actions')
    ->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

$combination = $this->studioCombination ?? null;
$input = Factory::getApplication()->getInput();
$itemId = (int) ($this->item->id ?? 0);
$alfaSaved = $input->getInt('alfasaved', -1);
$closeAfter = $input->getInt('close_after', 0);

if (!$combination) : ?>
    <div class="alert alert-warning m-3"><?php echo Text::_('COM_ALFA_COMBINATION_NOT_FOUND'); ?></div>
    <?php return;
endif;

$combinationId = (int) $combination->id;

// The Studio is an iframe with its OWN document, so inject the item base net
// the in-popup adjust preview needs (prices.js reads priceCoef.baseNet), the
// same anchor the grid quick edit uses.
$this->getDocument()->addScriptOptions('com_alfa.combinations.priceCoef', [
    'baseNet' => \Alfa\Component\Alfa\Administrator\Field\AlfapricesField::baseNet($itemId),
]);

$formAction = Route::_(
    'index.php?option=com_alfa&view=item&layout=edit_combination&tmpl=component'
    . '&id=' . $itemId . '&combination_id=' . $combinationId,
    false,
);

$detailLayout = new FileLayout('combinations.detail', JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');

// The variant's own gallery: the same MediaZone field the item uses, bound
// to the 'combination' origin.
$galleryForm = new Form('com_alfa.combination.gallery.' . $combinationId, ['control' => 'jform']);
$galleryForm->load('<form addfieldprefix="Alfa\Component\Alfa\Administrator\Field">
    <field name="medias" type="MediaZone" multiple="true" types="media,url" hiddenLabel="true" label="COM_ALFA_COMBINATION_GALLERY"/>
</form>');
$galleryForm->bind([
    'medias' => MediaHelper::getMediaData(origin: 'combination', itemIDs: $combinationId),
]);
?>

<!-- Native Joomla toolbar: same chrome as the order-edit popups. -->
<div class="subhead noshadow mb-0">
    <?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
</div>

<form action="<?php echo $formAction; ?>" method="post" enctype="multipart/form-data"
      name="adminForm" id="combination-form" class="form-validate form-horizontal alfa-studio">


    <?php echo $detailLayout->render([
        'combination' => $combination,
        'descriptors' => $this->combinationOverrideFields,
        'languages' => $this->contentLanguages,
        'escaper' => $this,
        'item' => $this->item,
        'galleryForm' => $galleryForm,
    ]); ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="id" value="<?php echo $itemId; ?>">
    <input type="hidden" name="combination_id" value="<?php echo $combinationId; ?>">
    <!-- The variant's price rows as this window rendered them: a save over rows
         changed elsewhere since is refused. -->
    <input type="hidden" name="jform[combinations][<?php echo $combinationId; ?>][price_rows_rendered]"
           value="<?php echo $this->escape(ItemFieldsAdminHelper::priceRowsState(rows: (array) ($combination->price_rows ?? []))); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

<script>
// Close button (native toolbar): just tells the parent; nothing is saved.
window.alfaStudioCancel = () => {
  window.parent.postMessage({
    messageType: 'alfa:combination-action',
    shouldClose: true,
    saved: false
  }, window.location.origin);
};

document.addEventListener('DOMContentLoaded', () => {
  // Variant downloads: file bytes ride the same itemdownloads AJAX tasks as
  // the item tab; metadata rides the Studio form save.
  const dl = document.getElementById('combination-downloads');
  if (dl) {
    const token = [...document.querySelectorAll('#combination-form input[type="hidden"]')]
      .find((input) => /^[a-f0-9]{32}$/.test(input.name));
    const send = (task, formData, button) => {
      if (token) { formData.append(token.name, '1'); }
      if (button) { button.disabled = true; }
      fetch('index.php?option=com_alfa&task=itemdownloads.' + task + '&format=json', { method: 'POST', body: formData })
        .then((r) => r.json())
        .then((json) => {
          if (json && json.success) { window.location.reload(); }
          else { alert((json && json.message) || 'Request failed'); if (button) { button.disabled = false; } }
        })
        .catch((e) => { alert(e.message || 'Request failed'); if (button) { button.disabled = false; } });
    };
    const uploadBtn = document.getElementById('combo-download-upload');
    uploadBtn.addEventListener('click', () => {
      const file = document.getElementById('combo-download-file');
      if (!file.files.length) { return; }
      const fd = new FormData();
      fd.append('item_id', dl.dataset.itemId);
      fd.append('combination_id', dl.dataset.combinationId);
      fd.append('file', file.files[0]);
      fd.append('title', document.getElementById('combo-download-title').value);
      fd.append('max_downloads', document.getElementById('combo-download-max').value);
      fd.append('days_valid', document.getElementById('combo-download-days').value);
      send('upload', fd, uploadBtn);
    });
    dl.querySelectorAll('tr[data-download-id]').forEach((row) => {
      row.querySelector('[data-action="replace"]').addEventListener('change', function () {
        if (!this.files.length) { return; }
        const fd = new FormData();
        fd.append('id', row.dataset.downloadId);
        fd.append('file', this.files[0]);
        send('replace', fd, null);
      });
      row.querySelector('[data-action="delete"]').addEventListener('click', function () {
        const fd = new FormData();
        fd.append('id', row.dataset.downloadId);
        send('delete', fd, this);
      });
    });
  }


  // One-shot dirty signal: lets the parent warn before prev/next navigation
  // (or any iframe swap) would discard unsaved Studio edits.
  const markDirty = () => {
    window.parent.postMessage({ messageType: 'alfa:combination-dirty' }, window.location.origin);
    document.removeEventListener('change', markDirty, true);
    document.removeEventListener('input', markDirty, true);
  };
  document.addEventListener('change', markDirty, true);
  document.addEventListener('input', markDirty, true);

  // Arriving back after our own save: Apply stays in the dialog (the parent
  // only marks itself for a DEFERRED reload), Save additionally closes.
  <?php if ($alfaSaved !== -1) : ?>
  window.parent.postMessage({
    messageType: 'alfa:combination-action',
    shouldClose: <?php echo $closeAfter === 1 ? 'true' : 'false'; ?>,
    saved: <?php echo $alfaSaved === 1 ? 'true' : 'false'; ?>
  }, window.location.origin);
  <?php endif; ?>
});
</script>
