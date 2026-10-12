<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field: item-page selector, swatches mode. Each value's chip uses its
 * params (swatch_image wins over swatch_colors); empties get a neutral chip.
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
 * The chip style for one value.
 *
 * This plugin OWNS the storage shape (params/value.xml -> swatch_colors / swatch_image), so
 * reading it here is legitimate; what it must not do is re-implement the rendering. The
 * image handling used to live here as a local regex that silently rejected every image
 * chosen through Joomla's media picker: the picker stores
 * `images/x.jpg#joomlaImage://...`, which has neither a leading slash nor a bare extension.
 * MediaStyle is the one media implementation everywhere: Joomla's cleanImageURL() to parse,
 * our whitelist to validate, Uri::root() to anchor, plus mtime cache-busting. It also
 * draws ANY number of colours, so a two-tone value is a split chip instead of its first
 * colour only.
 */
$swatchOf = static function (object $value): array {
    $params = $value->params_decoded ?? [];
    $image = MediaStyle::image((string) ($params['swatch_image'] ?? ''));

    if ($image !== '') {
        return ['style' => $image, 'overflow' => 0];
    }

    return ChoiceColors::style(array_keys(ChoiceColors::codes($params)));
};
?>
<div class="variant-field">
    <span class="variant-field-label"><?php echo $esc($field->display_name); ?></span>
    <div class="variant-swatches" role="radiogroup" aria-label="<?php echo $esc($field->display_name); ?>">
        <?php foreach ($values as $value) :
            $vid = (int) $value->id;
            $swatch = $swatchOf($value);
            $style = $swatch['style'];
            $isAvailable = isset($available[$vid]);
            $isInStock = isset($inStock[$vid]);
            // 'removed' policy: drop values unreachable with the current selection
            // entirely. Out-of-stock is still "available", so it is never dropped.
            if (!$isAvailable && ($unavailableMode ?? 'disabled') === 'removed') {
                continue;
            }
            // Selected state is NOT a class here; CSS keys off the native radio's
            // :checked (set below), so a price-only reload still shows the choice.
            $classes = 'variant-swatch'
                . ($isAvailable ? '' : ' is-unavailable')
                . ($isAvailable && !$isInStock ? ' is-out-of-stock' : '');

            ?>
            <label class="<?php echo $classes; ?>"
                   title="<?php echo $esc($value->display_name); ?>">
                <input type="radio" name="<?php echo $esc($inputName); ?>"
                       value="<?php echo $vid; ?>" class="variant-swatch-input" data-item-reload="<?php echo $esc($reloadScope); ?>"
                    <?php echo $selected === $vid ? 'checked' : ''; ?>
                    <?php echo $isAvailable ? '' : 'disabled'; ?>>
                <span class="variant-swatch-chip" style="<?php echo $style; ?>"
                    <?php echo $style === '' ? 'data-empty="1"' : ''; ?>></span>
                <span class="variant-swatch-name"><?php echo $esc($value->display_name); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
