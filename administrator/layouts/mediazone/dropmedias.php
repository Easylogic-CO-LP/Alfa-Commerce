<?php
/**
 * @package     Alfa.Administrator
 * @subpackage  com_alfa
 *
 * Media Management Layout
 * Toolbar with dropdown actions, drop area, URL modal, and media grid
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\HTML\HTMLHelper;

extract($displayData);

$app      = Factory::getApplication();
$params   = ComponentHelper::getParams('com_alfa');
$document = $app->getDocument();
$user     = $app->getIdentity();
$medias   = $data ?? [];

// Get allowed MIME from config
$mimes = $params->get('media_mime');
$allowedTypes = explode(',', $allowedTypes[0]);

// Pass options to JS
$document->addScriptOptions('com_alfa.mimes', $mimes);
$document->addScriptOptions('com_alfa.multiple', $multiple);
$document->addScriptOptions('com_alfa.types', $allowedTypes);

// Set media-picker options required by joomla-field-media web component
$document->addScriptOptions('media-picker', $supportedExtensions);
$document->addScriptOptions('media-picker-api', [
    'apiBaseUrl' => Uri::base(true) . '/index.php?option=com_media&format=json',
]);

// Load required assets
$wa = $document->getWebAssetManager();

$wa->useStyle('com_alfa.mediazone')
    ->useScript('com_alfa.mediazone')
    ->useScript('com_alfa.sortable')
    ->useScript('webcomponent.field-media')
    ->useScript('webcomponent.media-select');

// ── Translatable strings ──
$dropdownText          = Text::_('COM_ALFA_MEDIA_ACTIONS');
$selectFromLibraryText = Text::_('COM_ALFA_MEDIA_SELECT_FROM_LIBRARY');
$selectUrlText         = Text::_('COM_ALFA_MEDIA_SELECT_URL');
$urlLabelText          = Text::_('COM_ALFA_MEDIA_URL_LABEL');
$insertUrlTitle        = Text::_('COM_ALFA_MEDIA_INSERT_URL');
$urlThumbnailText      = Text::_('COM_ALFA_MEDIA_URL_THUMBNAIL');
$closeText             = Text::_('JCLOSE');
$addUrlText            = Text::_('COM_ALFA_MEDIA_ADD_URL');

// -- Pass variables of translations to Javascript
Text::script('JSELECT');
Text::script('JCLOSE');
Text::script('JCANCEL');

// ── Toolbar ──
$mediaToolbar = new Toolbar('media-actions-toolbar');

$dropdown = $mediaToolbar->dropdownButton('media-actions')
    ->text($dropdownText)
    ->toggleSplit(false)
    ->icon('icon-images')
    ->buttonClass('btn btn-success toggle-options-button');

$childBar = $dropdown->getChildToolbar();

if(in_array('media', $allowedTypes)){
    $childBar->appendButton(
            'Custom',
            <<<HTML
                <button type="button" class="btn media-action-select-media dropdown-item">
                    <span class="icon-folder-open"></span> {$selectFromLibraryText}
                </button>
            HTML
    );
}

if(in_array('url', $allowedTypes)){
$childBar->appendButton(
        'Custom',
        <<<HTML
            <button type="button"
                class="btn media-action-select-url dropdown-item"
                data-bs-toggle="modal"
                data-bs-target="#selectUrlModal">
                <span class="icon-upload"></span> {$selectUrlText}
            </button>
        HTML
    );
}

// ── URL Modal ──
$urlPopup = <<<HTML
    <div class="d-flex flex-column gap-4 mb-3 px-5 py-3">
        <div>
            <label for="media-url-input" class="form-label">{$urlLabelText}</label>
            <input type="url" class="form-control" id="media-url-input" placeholder="https://example.com">
            <small id="media-url-error" class="hidden">-</small>
            <button type="button" class="btn btn-primary media-url-thumbnail-btn">{$urlThumbnailText}</button>
        </div>
        <div>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$closeText}</button>
            <button type="button" class="btn btn-primary" id="media-url-submit">{$addUrlText}</button>
        </div>
    </div>
HTML;

// ── Media picker shared attributes ──
$mediaPickerUrl = $app->isClient('administrator')
    ? '/administrator/index.php?option=com_media&view=media&tmpl=component&mediatypes=0&asset=com_alfa&author=' . $user->id . '&path=local-images:/'
    : '/index.php?option=com_media&view=media&tmpl=component&mediatypes=0';

$pickerAttrs = [
    'style'                => 'border-radius: 15px',
    'class'                => 'field-media-wrapper',
    'types'                => 'images',
    'base-path'            => Uri::root(),
    'root-folder'          => 'images',
    'url'                  => $mediaPickerUrl,
    'input'                => '.field-media-input',
    'button-select'        => '.button-select',
    'button-clear'         => '.button-clear',
    'preview'              => 'static',
    'preview-container'    => '.field-media-preview',
    'supported-extensions' => json_encode($supportedExtensions),
];

$pickerAttrString = '';
foreach ($pickerAttrs as $key => $value) {
    $pickerAttrString .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
}
?>


<div class="media-management-section">
    <!-- Toolbar -->
    <div class="media-toolbar-wrapper mb-3">
        <?= $mediaToolbar->render(); ?>
    </div>

    <!-- Dropzone -->
    <div class="media-dropzone">

        <!-- Overlay (shown when dragging files) -->
        <div class="media-dropzone-overlay">
            <div class="media-dropzone-icon">
                <span class="icon-cloud-upload" aria-hidden="true"></span>
                <p><?= Text::_('COM_ALFA_MEDIA_DROP_HERE'); ?></p>
            </div>
        </div>

        <!-- Drop area inner -->
        <div class="media-dropzone-inner">
            <!-- Media Grid (Sortable) -->
            <div id="media-grid" class="media-grid">
                <?php if (!empty($medias)): ?>
                    <?php foreach ($medias as $media): ?>
                        <?= LayoutHelper::render('mediazone.dropmedia', ['media' => $media]); ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="media-placeholder">
                        <div class="media-placeholder-icon">
                            <span class="icon-images" aria-hidden="true"></span>
                        </div>
                        <p><?= Text::_('COM_ALFA_MEDIA_NO_ITEMS'); ?></p>
                        <p class="text-muted"><?= Text::_('COM_ALFA_MEDIA_NO_ITEMS_DESC'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================================
         Hidden UI (modals, pickers, file input)
         ============================================================ -->

    <!-- URL Modal -->
    <?= HTMLHelper::_('bootstrap.renderModal',
        'selectUrlModal',
        [
            'title'    => $insertUrlTitle,
            'backdrop' => 'static',
        ],
        $urlPopup
    ); ?>

    <!-- Media pickers (triggered via JS only) -->
    <div class="media-picker-controls" style="display: none;">

        <!-- Library picker -->
        <joomla-field-media
            id="media-picker-library"
            <?= $pickerAttrString; ?>
            modal-title="<?= Text::_('COM_ALFA_INSERT_MEDIA_MODAL'); ?>">
            <div class="input-group">
                <input type="text"
                       id="media-picker-input"
                       class="form-control field-media-input"
                       style="display: none;"
                       aria-hidden="true">
                <button type="button"
                        class="btn btn-success button-select"
                        id="media-picker-open-btn">
                    <span class="icon-images" aria-hidden="true"></span>
                </button>
                <button type="button"
                        class="btn btn-danger button-clear"
                        id="media-picker-clear-btn"
                        style="display: none;">
                    <span class="icon-times" aria-hidden="true"></span>
                </button>
            </div>
        </joomla-field-media>

        <!-- Thumbnail picker -->
        <joomla-field-media
            id="media-picker-thumbnail"
            <?= $pickerAttrString; ?>
            modal-title="<?= Text::_('COM_ALFA_SELECT_THUMBNAIL_MODAL_TITLE'); ?>">
            <div class="input-group">
                <input type="text"
                       id="media-thumbnail-picker-input"
                       class="form-control field-media-input media-thumbnail-picker-input"
                       style="display: none;"
                       aria-hidden="true">
                <button type="button"
                        class="btn btn-success button-select"
                        id="media-thumbnail-open-btn">
                </button>
                <button type="button"
                        class="btn btn-danger button-clear"
                        style="display: none;">
                    <span class="icon-times" aria-hidden="true"></span>
                </button>
            </div>
        </joomla-field-media>

    </div>

    <!-- Hidden file input for drag & drop uploads -->
    <input type="file"
           name="jform[uploads][]"
           id="media-file-input"
           multiple
           accept="image/*"
           style="display: none;"
           aria-hidden="true">

</div><!-- /.media-management-section -->