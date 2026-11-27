<?php
	/**
	 * @package     Alfa.Administrator
	 * @subpackage  com_alfa
	 *
	 * Media Item Template
	 * Displays a single media card with preview and edit controls.
	 * Layout id: mediazone.item, rendered by MediaZoneField, grid.php and the
	 * media.getSource AJAX endpoint (renamed from the former dropmedia.php).
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

	use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
	use Joomla\CMS\Component\ComponentHelper;
	use Joomla\CMS\Language\Text;
	use Joomla\CMS\HTML\HTMLHelper;
	use Joomla\CMS\Layout\LayoutHelper;

	$params = ComponentHelper::getParams('com_alfa');

	// Sanitize all output
	$mediaId   = htmlspecialchars($media->id, ENT_QUOTES, 'UTF-8');
	$path      = htmlspecialchars($media->path, ENT_QUOTES, 'UTF-8');
	$source    = htmlspecialchars($media->source, ENT_QUOTES, 'UTF-8');
	$thumbnail = htmlspecialchars($media->thumbnail ?? '', ENT_QUOTES, 'UTF-8');
	$altText   = htmlspecialchars($media->alt ?? '', ENT_QUOTES, 'UTF-8');
	$isNew     = $media->isNew ? '1' : '0';
	$type      = htmlspecialchars($media->type ?? '', ENT_QUOTES, 'UTF-8');

	// A stored file is drawn at its versioned address (MediaHelper::displayUrl()), so a file written again is
	// never shown from the browser's cache; a dropped file not saved yet is its blob. The form's values stay the paths.
	$preview = static fn (string $file, string $class): string => str_starts_with($file, 'blob:')
		? HTMLHelper::_('image', $file, (string) ($media->alt ?? ''), ['loading' => 'lazy', 'class' => $class], false, -1)
		: LayoutHelper::render('joomla.html.image', ['src' => MediaHelper::displayUrl(MediaHelper::toUrl($file)), 'alt' => (string) ($media->alt ?? ''), 'loading' => 'lazy', 'class' => $class]);

	// Compute preview HTML
	if ($type === 'url') {
		$previewHtml = '<a href="' . $path . '" target="_blank">' . $path . '</a>';
	} else {
		$previewHtml = $preview((string) $media->path, 'media-preview-img');
	}

	// Compute thumbnail preview
	$thumbnailHtml = $preview((string) ($media->thumbnail ?? ''), 'media-thumbnail-img');
?>

<div class="media-card"
     data-media-id="<?= $mediaId; ?>"
     data-is-new="<?= $isNew; ?>">

    <div class="errors-container">
        <span class="thumbnail-error-msg"><?= Text::_('COM_ALFA_MEDIA_THUMBNAIL_ERROR') ?></span>
    </div>

    <!-- Header: type tag + action buttons -->
    <div class="media-header">
        <div class="media-type-tag <?= $type; ?>">
            <span class="badge <?= $type === 'url' ? 'text-bg-danger' : 'text-bg-secondary'; ?>"><?= strtoupper($type); ?></span>
        </div>

        <div class="media-actions">
            <button type="button"
                    class="btn btn-sm btn-outline-danger media-btn-delete"
                    title="<?= Text::_('JACTION_DELETE'); ?>">
                <span class="icon-trash" aria-hidden="true"></span>
                <span class="visually-hidden"><?= Text::_('JACTION_DELETE'); ?></span>
            </button>

            <button type="button"
                    class="btn btn-sm btn-outline-secondary drag-handle"
                    title="<?= Text::_('COM_ALFA_MEDIA_DRAG_REORDER'); ?>">
                <span class="icon-arrows-alt" aria-hidden="true"></span>
                <span class="visually-hidden"><?= Text::_('COM_ALFA_MEDIA_DRAG_REORDER'); ?></span>
            </button>

            <input type="checkbox"
                   name="jform[media][<?= $mediaId; ?>][delete]"
                   value="1"
                   class="media-delete-flag"
                   style="display: none;"
                   aria-hidden="true">
        </div>
    </div>

    <!-- Preview: main image/url + thumbnail -->
    <div class="media-preview" title="<?= Text::_('COM_ALFA_MEDIA_PREVIEW_HINT'); ?>">
		<?= $previewHtml; ?>

        <div class="media-thumbnail">
            <input type="hidden"
                   class="media-thumbnail-input"
                   name="jform[media][<?= $mediaId; ?>][thumbnail]"
                   value="<?= $thumbnail; ?>">

            <button type="button" class="media-thumbnail-btn" title="<?= Text::_('COM_ALFA_MEDIA_THUMBNAIL_HINT'); ?>">
				<?= $thumbnailHtml; ?>
                <span class="select-thumbnail-btn-msg"><?= Text::_('COM_ALFA_MEDIA_SELECT_THUMBNAIL_BUTTON_ERROR_TEXT') ?></span>
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
                   maxlength="<?= MediaHelper::ALT_MAX_LENGTH; ?>"
                   class="form-control"
                   aria-describedby="media-alt-hint-<?= $mediaId; ?>"
                   placeholder="<?= Text::_('COM_ALFA_MEDIA_DESCRIPTION_INPUT_PLACEHOLDER'); ?>">
            <?php // The field stops at the length a description can hold: said under it. ?>
            <div class="form-text" id="media-alt-hint-<?= $mediaId; ?>"><?= Text::sprintf('COM_ALFA_MEDIA_DESCRIPTION_HINT', MediaHelper::ALT_MAX_LENGTH); ?></div>
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