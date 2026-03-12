<?php
    /**
     * @package     Alfa.Administrator
     * @subpackage  com_alfa
     *
     * Media Item Template
     * Displays a single media card with preview and edit controls
     *
     * @var object $media Media object with:
     *                    - id: Unique identifier
     *                    - isNew: Boolean
     *                    - path: Full URL or blob for display
     *                    - source: File identifier (fileId or relative path)
     *                    - thumbnail: Thumbnail path
     *                    - alt: Alt text
     *                    - type: Media type (image|url)
     */

    \defined('_JEXEC') or die;

    extract($displayData);

    use Joomla\CMS\Component\ComponentHelper;
    use Joomla\CMS\Language\Text;
    use Joomla\CMS\HTML\HTMLHelper;

    $params = ComponentHelper::getParams('com_alfa');

    // Sanitize all output
    $mediaId   = htmlspecialchars($media->id, ENT_QUOTES, 'UTF-8');
    $path      = htmlspecialchars($media->path, ENT_QUOTES, 'UTF-8');
    $source    = htmlspecialchars($media->source, ENT_QUOTES, 'UTF-8');
    $thumbnail = htmlspecialchars($media->thumbnail ?? '', ENT_QUOTES, 'UTF-8');
    $altText   = htmlspecialchars($media->alt ?? '', ENT_QUOTES, 'UTF-8');
    $isNew     = $media->isNew ? '1' : '0';
    $type      = htmlspecialchars($media->type ?? '', ENT_QUOTES, 'UTF-8');

    // Compute preview HTML
    if ($type === 'url') {
        $previewHtml = '<a href="' . $path . '" target="_blank">' . $path . '</a>';
    } else {
        $outputMode  = str_starts_with($path, 'blob:') ? -1 : 0;
        $previewHtml = HTMLHelper::_('image', $path, $altText, ['loading' => 'lazy', 'class' => 'media-preview-img'], false, $outputMode);
    }

    // Compute thumbnail preview
    $thumbOutputMode = str_starts_with($thumbnail, 'blob:') ? -1 : 0;
    $thumbnailHtml   = HTMLHelper::_('image', $thumbnail, $altText, ['loading' => 'lazy', 'class' => 'media-thumbnail-img'], false, $thumbOutputMode);
?>

<div class="media-card"
     data-media-id="<?= $mediaId; ?>"
     data-is-new="<?= $isNew; ?>">

    <!-- Header: type tag + action buttons -->
    <div class="media-header">
        <div class="media-type-tag <?= $type; ?>">
            <span><?= strtoupper($type); ?></span>
        </div>

        <div class="media-actions">
            <button type="button"
                    class="action-btn delete media-btn-delete"
                    title="<?= Text::_('JACTION_DELETE'); ?>">
                <span class="icon-trash" aria-hidden="true"></span>
            </button>

            <div class="action-btn drag-handle"
                 title="<?= Text::_('COM_ALFA_MEDIA_DRAG_REORDER'); ?>">
                <span class="icon-arrows-alt" aria-hidden="true"></span>
            </div>

            <input type="checkbox"
                   name="jform[media][<?= $mediaId; ?>][delete]"
                   value="1"
                   class="media-delete-flag"
                   style="display: none;"
                   aria-hidden="true">
        </div>
    </div>

    <!-- Preview: main image/url + thumbnail -->
    <div class="media-preview">
        <?= $previewHtml; ?>

        <div class="media-thumbnail">
            <input type="hidden"
                   class="media-thumbnail-input"
                   name="jform[media][<?= $mediaId; ?>][thumbnail]"
                   value="<?= $thumbnail; ?>">

            <button type="button" class="media-thumbnail-btn">
                <?= $thumbnailHtml; ?>
            </button>
        </div>
    </div>

    <!-- Form fields -->
    <div class="media-fields">
        <div class="media-field media-field-alt">
            <label for="media-alt-<?= $mediaId; ?>">
                <?= Text::_('COM_ALFA_MEDIA_DESCRIPTION_LABEL'); ?>
            </label>
            <input type="text"
                   id="media-alt-<?= $mediaId; ?>"
                   name="jform[media][<?= $mediaId; ?>][alt]"
                   value="<?= $altText; ?>"
                   class="form-control"
                   placeholder="<?= Text::_('COM_ALFA_MEDIA_DESCRIPTION_INPUT_PLACEHOLDER'); ?>">
        </div>

        <div class="media-field media-field-path">
            <label for="media-path-<?= $mediaId; ?>">
                <?= Text::_('COM_ALFA_MEDIA_PATH_LABEL'); ?>
            </label>
            <input type="text"
                   id="media-path-<?= $mediaId; ?>"
                   name="jform[media][<?= $mediaId; ?>][source]"
                   value="<?= $source; ?>"
                   class="form-control media-source-input"
                   placeholder="<?= Text::_('COM_ALFA_MEDIA_PATH_INPUT_PLACEHOLDER'); ?>"
                   <?= $type !== 'url' ? 'readonly' : ''; ?>>
        </div>
    </div>

    <!-- Hidden state -->
    <input type="hidden"
           name="jform[media][<?= $mediaId; ?>][type]"
           value="<?= $type; ?>">
</div>