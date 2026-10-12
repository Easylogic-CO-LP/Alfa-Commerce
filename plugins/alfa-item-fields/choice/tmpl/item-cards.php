<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field - item-page selector, CARDS mode: a big tappable card per value
 * (image on top, name below) for choices whose look matters more than a chip
 * can show (photobook covers, materials, layouts).
 *
 * Choice::renderSelector() always supplies the full $displayData below, so the
 * layout just extracts it (no defaults needed):
 *
 * @var object $field       The field definition (id, display_name, ...).
 * @var object[] $values      The selectable value objects (id, display_name, params_decoded: swatch_image/swatch_colors).
 * @var string $inputName   This control's form name: sel[fieldId] (variant) or opt[fieldId] (option).
 * @var int $selected    The currently-chosen value id (0 = none chosen).
 * @var array $available   Selectable value ids as a set [id => true]; others are disabled.
 * @var array $inStock     In-stock value ids as a set [id => true] (available but flagged when not in stock).
 * @var string $reloadScope The data-item-reload value: 'item' re-fetches the whole item (variant), 'price' re-prices only (option).
 */

use Alfa\Component\Alfa\Site\Service\Facet\MediaStyle;
use Alfa\Plugin\AlfaItemFields\Choice\Color\ChoiceColors;

defined('_JEXEC') or die;

extract($displayData);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * The media style for one card.
 *
 * The plugin owns the storage shape and may read it; the RENDERING is shared, so the card,
 * the item-page swatch and the filter chip cannot drift apart again. MediaStyle parses a
 * media value with Joomla's cleanImageURL() (the picker stores
 * `images/x.jpg#joomlaImage://...`, which the old local regex here silently rejected),
 * whitelists it for the style attribute, anchors it with Uri::root() and cache-busts local
 * files, and draws any number of colours rather than just the first.
 */
$mediaStyleOf = static function (object $value): string {
    $params = $value->params_decoded ?? [];
    $image = MediaStyle::image((string) ($params['swatch_image'] ?? ''));

    // The card shows its own product imagery, so a configured swatch IMAGE is not painted
    // as a background here; only colours are, and only when there is no image.
    if ($image !== '') {
        return '';
    }

    $style = ChoiceColors::style(array_keys(ChoiceColors::codes($params)))['style'];

    return $style === '' ? '' : ' style="' . $style . '"';
};
?>
<div class="variant-field">
    <span class="variant-field-label"><?php echo $esc($field->display_name); ?></span>
    <div class="variant-cards" role="radiogroup" aria-label="<?php echo $esc($field->display_name); ?>">
        <?php foreach ($values as $value) :
            $vid = (int) $value->id;
            $isAvailable = isset($available[$vid]);
            $isInStock = isset($inStock[$vid]);

            if (!$isAvailable && ($unavailableMode ?? 'disabled') === 'removed') {
                continue;
            }

            $classes = 'variant-card'
                . ($isAvailable ? '' : ' is-unavailable')
                . ($isAvailable && !$isInStock ? ' is-out-of-stock' : '');

            $mediaStyle = $mediaStyleOf($value);
            $safeImage = MediaStyle::imageUrl((string) (($value->params_decoded ?? [])['swatch_image'] ?? ''));
            ?>
            <label class="<?php echo $classes; ?>"
                   title="<?php echo $esc($value->display_name); ?>">
                <input type="radio" name="<?php echo $esc($inputName); ?>"
                       value="<?php echo $vid; ?>" class="variant-card-input" data-item-reload="<?php echo $esc($reloadScope); ?>"
                    <?php echo $selected === $vid ? 'checked' : ''; ?>
                    <?php echo $isAvailable ? '' : 'disabled'; ?>>
                <span class="variant-card-media"<?php echo $mediaStyle; ?>>
                    <?php if ($safeImage !== '') : ?>
                        <img src="<?php echo $esc($safeImage); ?>" alt="" loading="lazy">
                    <?php endif; ?>
                </span>
                <span class="variant-card-name"><?php echo $esc($value->display_name); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
