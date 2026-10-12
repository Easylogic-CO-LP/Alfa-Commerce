<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Downloads tab of the item editor: the ITEM-WIDE downloadable files
 * (#__alfa_item_downloads rows with combination_id NULL: every variant
 * inherits them). Variant-specific files live in the combination Studio,
 * like every other per-variant surface. Independent of is_virtual:
 * physical items may carry files too (manual, license, firmware).
 *
 * Persistence is split by nature:
 *   - row METADATA (title/limits/variant/state) posts as
 *     jform[item_downloads][<id>][...] and saves with the toolbar
 *     Save/Save & Close via ItemModel::save() -> saveItemDownloadsMeta(),
 *     like every other item surface;
 *   - file BYTES (upload / replace / delete) go through the
 *     ItemdownloadsController AJAX tasks: multipart transfers and the
 *     immediate storage GC don't fit a form roundtrip. Those actions reload
 *     the page on success (the uitab recall keeps the tab active).
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Session\Session;

$itemId = (int) ($this->item->id ?? 0);
// Item-wide files only; variant files are managed inside each Studio.
$downloads = array_values(array_filter(
    $this->itemDownloads ?? [],
    static fn ($download) => empty($download->combination_id),
));
?>
<div class="row">
    <div class="col-12">

        <?php // The download store's direct-download protection: only while it is not verified. ?>
        <?php echo LayoutHelper::render('downloads.protection', ['always' => false], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>

        <?php if ($itemId === 0) : ?>
            <div class="alert alert-info">
                <?php echo Text::_('COM_ALFA_DOWNLOADS_SAVE_ITEM_FIRST'); ?>
            </div>
        <?php else : ?>

        <div id="item-downloads"
             data-endpoint="index.php?option=com_alfa&format=json"
             data-item-id="<?php echo $itemId; ?>"
             data-token="<?php echo Session::getFormToken(); ?>">

            <fieldset id="fieldset-downloads-files" class="options-form">
                <legend><?php echo Text::_('COM_ALFA_DOWNLOADS_GROUP_FILES'); ?></legend>

                <?php if (empty($downloads)) : ?>
                    <p class="text-muted mb-2"><?php echo Text::_('COM_ALFA_DOWNLOADS_NONE'); ?></p>
                <?php else : ?>
                    <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_TITLE'); ?></th>
                                <th><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_FILE'); ?></th>
                                <th class="w-10"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_MAX'); ?></th>
                                <th class="w-10"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_DAYS'); ?></th>
                                <th class="w-10"><?php echo Text::_('JSTATUS'); ?></th>
                                <th class="w-25"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_ACTIONS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($downloads as $download) : ?>
                            <?php $rowName = 'jform[item_downloads][' . (int) $download->id . ']'; ?>
                            <tr data-download-id="<?php echo (int) $download->id; ?>">
                                <td>
                                    <input type="text" class="form-control form-control-sm"
                                           name="<?php echo $rowName; ?>[title]"
                                           value="<?php echo htmlspecialchars((string) $download->title, ENT_QUOTES, 'UTF-8'); ?>">
                                </td>
                                <td>
                                    <span class="d-block text-truncate" style="max-width:220px;"
                                          title="<?php echo htmlspecialchars((string) $download->original_filename, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $download->display_filename, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <small class="text-muted"><?php echo HTMLHelper::_('number.bytes', (int) $download->file_size); ?></small>
                                </td>
                                <td>
                                    <input type="number" min="0" class="form-control form-control-sm"
                                           name="<?php echo $rowName; ?>[max_downloads]"
                                           value="<?php echo (int) $download->max_downloads; ?>">
                                </td>
                                <td>
                                    <input type="number" min="0" class="form-control form-control-sm"
                                           name="<?php echo $rowName; ?>[days_valid]"
                                           value="<?php echo (int) $download->days_valid; ?>">
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" name="<?php echo $rowName; ?>[state]">
                                        <option value="1" <?php echo (int) $download->state === 1 ? 'selected' : ''; ?>><?php echo Text::_('JPUBLISHED'); ?></option>
                                        <option value="0" <?php echo (int) $download->state !== 1 ? 'selected' : ''; ?>><?php echo Text::_('JUNPUBLISHED'); ?></option>
                                    </select>
                                </td>
                                <td class="text-nowrap">
                                    <label class="btn btn-sm btn-outline-secondary mb-0">
                                        <?php echo Text::_('COM_ALFA_DOWNLOADS_REPLACE_FILE'); ?>
                                        <input type="file" class="d-none" data-action="replace">
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete">
                                        <?php echo Text::_('JACTION_DELETE'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </fieldset>

            <fieldset id="fieldset-downloads-upload" class="options-form">
                <legend><?php echo Text::_('COM_ALFA_DOWNLOADS_GROUP_UPLOAD'); ?></legend>
                <div class="row g-2 align-items-end" id="download-upload-row">
                    <div class="col-md-4">
                        <label class="form-label" for="download-upload-file"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_FILE'); ?></label>
                        <input type="file" class="form-control" id="download-upload-file">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="download-upload-title"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_TITLE'); ?></label>
                        <input type="text" class="form-control" id="download-upload-title"
                               placeholder="<?php echo Text::_('COM_ALFA_DOWNLOADS_TITLE_PLACEHOLDER'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="download-upload-max"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_MAX'); ?></label>
                        <input type="number" min="0" value="0" class="form-control" id="download-upload-max">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="download-upload-days"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_DAYS'); ?></label>
                        <input type="number" min="0" value="0" class="form-control" id="download-upload-days">
                    </div>
                    <div class="col-12">
                        <button type="button" class="btn btn-success" id="download-upload-btn">
                            <?php echo Text::_('COM_ALFA_DOWNLOADS_UPLOAD_BUTTON'); ?>
                        </button>
                        <small class="text-muted ms-2">
                            <?php echo Text::sprintf('COM_ALFA_DOWNLOADS_MAX_UPLOAD_HINT', ini_get('upload_max_filesize')); ?>
                            <?php echo Text::_('COM_ALFA_DOWNLOADS_LIMITS_HINT'); ?>
                        </small>
                    </div>
                </div>
            </fieldset>
        </div>

        <script>
        (function () {
            'use strict';

            var panel = document.getElementById('item-downloads');

            if (!panel) {
                return;
            }

            var itemId = panel.dataset.itemId;
            var token = panel.dataset.token;

            function send(task, formData, button) {
                formData.append(token, '1');

                if (button) {
                    button.disabled = true;
                }

                fetch('index.php?option=com_alfa&task=itemdownloads.' + task + '&format=json', {
                    method: 'POST',
                    body: formData
                })
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        if (json && json.success) {
                            window.location.reload();
                        } else {
                            alert((json && json.message) || 'Request failed');

                            if (button) {
                                button.disabled = false;
                            }
                        }
                    })
                    .catch(function (error) {
                        alert(error.message || 'Request failed');

                        if (button) {
                            button.disabled = false;
                        }
                    });
            }

            var uploadBtn = document.getElementById('download-upload-btn');

            uploadBtn.addEventListener('click', function () {
                var fileInput = document.getElementById('download-upload-file');

                if (!fileInput.files.length) {
                    alert(<?php echo json_encode(Text::_('COM_ALFA_DOWNLOADS_ERROR_NO_FILE')); ?>);
                    return;
                }

                var formData = new FormData();
                formData.append('item_id', itemId);
                formData.append('file', fileInput.files[0]);
                formData.append('title', document.getElementById('download-upload-title').value);
                formData.append('max_downloads', document.getElementById('download-upload-max').value);
                formData.append('days_valid', document.getElementById('download-upload-days').value);
                formData.append('combination_id', '0');

                send('upload', formData, uploadBtn);
            });

            panel.querySelectorAll('tr[data-download-id]').forEach(function (row) {
                var downloadId = row.dataset.downloadId;

                row.querySelector('[data-action="replace"]').addEventListener('change', function () {
                    if (!this.files.length) {
                        return;
                    }

                    var formData = new FormData();
                    formData.append('id', downloadId);
                    formData.append('file', this.files[0]);

                    send('replace', formData, null);
                });

                row.querySelector('[data-action="delete"]').addEventListener('click', function () {
                    if (!confirm(<?php echo json_encode(Text::_('COM_ALFA_DOWNLOADS_CONFIRM_DELETE')); ?>)) {
                        return;
                    }

                    var formData = new FormData();
                    formData.append('id', downloadId);

                    send('delete', formData, this);
                });
            });
        })();
        </script>

        <?php endif; ?>
    </div>
</div>
