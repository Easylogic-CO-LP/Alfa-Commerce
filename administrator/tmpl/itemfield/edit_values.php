<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$this->getDocument()->getWebAssetManager()->useScript('com_alfa.sortable');

/**
 * Inline value pool. Every value of the field is managed right here: names
 * per language and the type plugin's per-value config (params/value.xml). The alias auto-generates per language
 * on save (and powers greeklish search).
 *
 * Row inputs post as jform[values][<idx>][...]; removed existing rows collect
 * in the deleted_value_ids hidden field. Plugin value-config renders on SAVED
 * rows only (media/color widgets don't survive JS cloning): add a value,
 * save, then configure its plugin extras.
 */

$values = is_array($this->item->values ?? null) ? $this->item->values : [];

/**
 * Render one full value row.
 *
 * @param string $idx    Row index ('__IDX__' for the JS template)
 * @param object|null $row Existing row or null (new/template)
 */
$renderValueRow = function ($idx, $row = null) {
    $base = 'jform[values][' . $idx . ']';
    $valueId = (int) ($row->id ?? 0);
    ?>
    <div class="card mb-2 alfa-value-row" data-value-id="<?php echo $valueId; ?>">
        <div class="card-body py-2">
            <input type="hidden" name="<?php echo $base; ?>[id]" value="<?php echo $valueId; ?>">
            <div class="d-flex gap-3 flex-wrap align-items-start">
                <span class="sortable-handler mt-2" aria-hidden="true"><span class="icon-ellipsis-v"></span></span>

                <div class="flex-grow-1">
                    <div class="d-flex gap-2 flex-wrap">
                        <?php foreach ($this->contentLanguages as $language) : ?>
                            <div>
                                <label class="form-label small mb-0">
                                    <?php echo Text::_('COM_ALFA_ITEMFIELDVALUES_NAME'); ?>
                                    <?php if (!empty($language->image)) : ?>
                                        <?php echo HTMLHelper::_('image', 'mod_languages/' . $language->image . '.gif', $language->title_native, ['title' => $language->title_native], true); ?>
                                    <?php else : ?>
                                        <span class="badge bg-body-tertiary text-body-emphasis"><?php echo $this->escape($language->sef ?? $language->tag); ?></span>
                                    <?php endif; ?>
                                </label>
                                <input type="text" class="form-control form-control-sm"
                                       name="<?php echo $base; ?>[name_<?php echo $language->key; ?>]"
                                       value="<?php echo $this->escape($row->{'name_' . $language->key} ?? ''); ?>">
                                <?php // Always-blank alias key: saveMultilingualData() regenerates the greeklish slug from the current name on every save. The value alias is search-only (not a stable URL), so it tracks renames instead of sticking. ?>
                                <input type="hidden"
                                       name="<?php echo $base; ?>[alias_<?php echo $language->key; ?>]"
                                       value="">
                            </div>
                        <?php endforeach; ?>

                    </div>

                    <?php if ($valueId > 0 && $this->valueXmlPath) : ?>
                        <details class="mt-2">
                            <summary class="small text-muted"><?php echo Text::_('COM_ALFA_ITEMFIELDVALUE_PLUGIN_SETTINGS'); ?></summary>
                            <div class="mt-2">
                                <?php
                                $rowForm = new Form('com_alfa.itemfieldvalue.' . $idx, ['control' => $base]);
                                $rowForm->loadFile($this->valueXmlPath, false);
                                $rowForm->bind(['valueparams' => $row->valueparams ?? []]);
                                echo $rowForm->renderFieldset('value_settings');
                                ?>
                            </div>
                        </details>
                    <?php elseif ($this->valueXmlPath) : ?>
                        <div class="small text-muted mt-2"><?php echo Text::_('COM_ALFA_ITEMFIELDVALUE_SAVE_FOR_PLUGIN_SETTINGS'); ?></div>
                    <?php endif; ?>
                </div>

                <button type="button" class="btn btn-sm btn-outline-danger alfa-value-remove mt-2" aria-label="<?php echo Text::_('JACTION_DELETE'); ?>">
                    <span class="icon-trash" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="row">
    <div class="col-lg-12">
        <fieldset class="adminform">
            <legend><?php echo Text::_('COM_ALFA_TAB_ITEMFIELD_VALUES'); ?></legend>
            <p class="text-muted small"><?php echo Text::_('COM_ALFA_ITEMFIELD_VALUES_HELP'); ?></p>

            <input type="hidden" name="jform[deleted_value_ids]" id="alfa-deleted-value-ids" value="">

            <div id="alfa-value-rows">
                <?php foreach ($values as $i => $row) : ?>
                    <?php $renderValueRow((string) $i, $row); ?>
                <?php endforeach; ?>
            </div>

            <template id="alfa-value-row-template">
                <?php $renderValueRow('__IDX__', null); ?>
            </template>

            <button type="button" class="btn btn-secondary" id="alfa-value-add">
                <span class="icon-plus" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_ITEMFIELD_VALUES_ADD'); ?>
            </button>
        </fieldset>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const rows       = document.getElementById('alfa-value-rows');
  const template   = document.getElementById('alfa-value-row-template');

  // Drag to reorder: row order in the form IS the saved ordering
  // (saveValues numbers rows by POST position), so no renumbering needed.
  if (window.Sortable) {
    window.Sortable.create(document.getElementById('alfa-value-rows'), {
      handle: '.sortable-handler',
      animation: 150,
    });
  }
  const deletedIds = document.getElementById('alfa-deleted-value-ids');
  let nextIdx = <?php echo count($values); ?>;

  document.getElementById('alfa-value-add').addEventListener('click', () => {
    const html = template.innerHTML.replaceAll('__IDX__', String(nextIdx++));
    rows.insertAdjacentHTML('beforeend', html);
  });

  document.addEventListener('click', (event) => {
    const removeBtn = event.target.closest('.alfa-value-remove');
    if (removeBtn) {
      const row = removeBtn.closest('.alfa-value-row');
      const id  = parseInt(row.dataset.valueId || '0', 10);
      if (id > 0) {
        deletedIds.value = deletedIds.value ? deletedIds.value + ',' + id : String(id);
      }
      row.remove();
      return;
    }
  });
});
</script>
