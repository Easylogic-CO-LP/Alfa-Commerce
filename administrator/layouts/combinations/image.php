<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The Image cell of the variants grid: the variant's first image as a thumbnail, with how many more it has, or a
 * small drop zone for a variant without one. The grid draws it for every row; the upload task draws it again for
 * the row it changed and combinations.js puts that answer in the cell's place, so no markup is made by the script.
 *
 * Layout variables:
 * @var  int          $combinationId The variant.
 * @var  int          $mediaCount    How many images the variant has.
 * @var  object|null  $first         Its first image (thumbnail_url, alt), null for none.
 * @var  string       $label         The variant's label, for the control's title and the image's alt.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

extract($displayData, EXTR_OVERWRITE);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$first ??= null;
$mediaCount = (int) ($mediaCount ?? 0);
$label = (string) ($label ?? '');
?>
<div class="variant-image" data-variant-image data-combination-id="<?php echo (int) $combinationId; ?>">
<?php if ($first !== null) : ?>
    <button type="button" class="variant-image-open" data-studio-images
            title="<?php echo $escape(Text::sprintf('COM_ALFA_COMBINATION_IMAGE_OPEN', $label)); ?>">
        <img src="<?php echo $escape((string) $first->thumbnail_url); ?>"
             alt="<?php echo $escape((string) ($first->alt ?: $label)); ?>" width="56" height="56" loading="lazy">
        <?php if ($mediaCount > 1) : ?>
            <span class="variant-image-more"><?php echo $escape(Text::sprintf('COM_ALFA_COMBINATION_IMAGE_MORE', $mediaCount - 1)); ?></span>
        <?php endif; ?>
    </button>
<?php else : ?>
    <label class="variant-image-drop" data-variant-image-drop title="<?php echo $escape(Text::_('COM_ALFA_COMBINATION_IMAGE_DROP')); ?>">
        <input type="file" accept="image/*" class="visually-hidden" data-variant-image-file
               aria-label="<?php echo $escape(Text::_('COM_ALFA_COMBINATION_IMAGE_DROP')); ?>">
        <span class="icon-upload" aria-hidden="true"></span>
        <span class="variant-image-drop-text"><?php echo Text::_('COM_ALFA_COMBINATION_IMAGE_DROP_SHORT'); ?></span>
    </label>
<?php endif; ?>
</div>
