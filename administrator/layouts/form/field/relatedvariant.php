<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A related row's variant cell (RelatedVariantField): what the linked product's card opens on. The Variant select
 * offers the product's default, each current combination and Choose values; the dimensions' selects belong to Choose
 * values and show only while it is chosen (CSS, css/admin/related-products.css); the options' selects apply to every
 * choice. A row with no product yet shows a hint and posts nothing. A template overrides this layout at
 * html/layouts/com_alfa/form/field/relatedvariant.php.
 *
 * Receives Joomla's field layout data and: $itemId (the row's product, 0 for none), $choices (RelatedItems::choices():
 * combinations, dimensions, options), $choice ('' the default, a combination id, or 'choose'), $values (field id to
 * value id) and $control (the field's name, which the posted keys extend).
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\RelatedVariantField;
use Joomla\CMS\Language\Text;

extract($displayData);

$e = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
$selected = static fn (bool $is): string => $is ? ' selected' : '';
$hasChoices = $choices['combinations'] !== [] || $choices['options'] !== [];
?>
<div class="related-variant" data-related-variant>
    <?php if ($itemId <= 0) : ?>
        <p class="related-variant__hint"><?php echo Text::_('COM_ALFA_ITEM_RELATED_VARIANT_PICK_FIRST'); ?></p>
    <?php elseif (!$hasChoices) : ?>
        <p class="related-variant__hint"><?php echo Text::_('COM_ALFA_ITEM_RELATED_VARIANT_NONE_TO_CHOOSE'); ?></p>
    <?php else : ?>
        <?php if ($choices['combinations'] !== []) : ?>
            <div class="related-variant__group">
                <label for="<?php echo $e($id); ?>_combination"><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_RELATED_VARIANT'); ?></label>
                <select id="<?php echo $e($id); ?>_combination" name="<?php echo $e($control); ?>[combination]" class="form-select related-variant__mode">
                    <option value=""<?php echo $selected($choice === ''); ?>><?php echo Text::_('COM_ALFA_ITEM_RELATED_VARIANT_DEFAULT'); ?></option>
                    <?php foreach ($choices['combinations'] as $combination) : ?>
                        <option value="<?php echo (int) $combination['id']; ?>"<?php echo $selected($choice === (string) $combination['id']); ?>>
                            <?php echo $e((int) $combination['state'] === 1 ? $combination['label'] : Text::sprintf('COM_ALFA_ITEM_RELATED_VARIANT_UNPUBLISHED', $combination['label'])); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="<?php echo RelatedVariantField::CHOOSE; ?>"<?php echo $selected($choice === RelatedVariantField::CHOOSE); ?>><?php echo Text::_('COM_ALFA_ITEM_RELATED_VARIANT_CHOOSE'); ?></option>
                </select>
            </div>
            <div class="related-variant__group related-variant__dims">
                <?php foreach ($choices['dimensions'] as $field) : ?>
                    <label for="<?php echo $e($id); ?>_dim<?php echo (int) $field['id']; ?>"><?php echo $e($field['name']); ?></label>
                    <select id="<?php echo $e($id); ?>_dim<?php echo (int) $field['id']; ?>" name="<?php echo $e($control); ?>[dims][<?php echo (int) $field['id']; ?>]" class="form-select">
                        <option value=""><?php echo Text::_('COM_ALFA_ITEM_RELATED_VARIANT_ANY'); ?></option>
                        <?php foreach ($field['values'] as $value) : ?>
                            <option value="<?php echo (int) $value['id']; ?>"<?php echo $selected($choice === RelatedVariantField::CHOOSE && ($values[$field['id']] ?? 0) === $value['id']); ?>><?php echo $e($value['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($choices['options'] !== []) : ?>
            <div class="related-variant__group">
                <?php foreach ($choices['options'] as $field) : ?>
                    <label for="<?php echo $e($id); ?>_opt<?php echo (int) $field['id']; ?>"><?php echo $e($field['name']); ?></label>
                    <select id="<?php echo $e($id); ?>_opt<?php echo (int) $field['id']; ?>" name="<?php echo $e($control); ?>[options][<?php echo (int) $field['id']; ?>]" class="form-select">
                        <option value=""><?php echo Text::_('COM_ALFA_ITEM_RELATED_VARIANT_NONE'); ?></option>
                        <?php foreach ($field['values'] as $value) : ?>
                            <option value="<?php echo (int) $value['id']; ?>"<?php echo $selected(($values[$field['id']] ?? 0) === $value['id']); ?>><?php echo $e($value['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
