<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The INTERIOR of one colour filter chip.
 *
 * Rendered by the filter module through the shared plugin-layout renderer, exactly as the
 * item page and the catalogue card render this plugin's selectors. The module owns the
 * control around this (the checkbox, its name, the group token, the field id, the label
 * and the count), so nothing here can reach the filter's state; this draws the swatch and
 * nothing else.
 *
 * @var array $displayData ['codes' => string[], 'image' => string] as reported by the plugin.
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Facet\MediaStyle;
use Alfa\Plugin\AlfaItemFields\Choice\Color\ChoiceColors;

// A configured image is more specific than a code, so it wins. MediaStyle is the shared,
// security-reviewed media pipeline (cleanImageURL + whitelist + site-root anchoring); the
// colour banding beside it is this plugin's own business.
$image = MediaStyle::image((string) ($displayData['image'] ?? ''));
$swatch = $image !== ''
    ? ['style' => $image, 'overflow' => 0]
    : ChoiceColors::style((array) ($displayData['codes'] ?? []));

if ($swatch['style'] === '') {
    return;
}
?>
<span class="af-chip-swatch" style="<?php echo $swatch['style']; ?>"></span>
<?php if ($swatch['overflow'] > 0) : ?>
    <span class="af-chip-more">+<?php echo (int) $swatch['overflow']; ?></span>
<?php endif; ?>
