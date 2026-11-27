<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Render one page slot's aggregated surfaces: a flat list of descriptors the
 * orchestrator collected from every contributor (item fields, payment / shipment
 * methods), in insertion order. A dumb loop: it knows neither who contributed nor
 * the event system, only how to render a descriptor. Called via Surface::render.
 *
 * Each descriptor's showon attribute string (built upstream) rides on the wrapper
 * so a surface keeps its field's conditional visibility wherever it lands. The
 * wrapper is a div, or the tag the caller names (tr inside the Specifications
 * table, whose contributions draw table cells).
 *
 * A contribution of an item field on a product slot (the product page, the
 * listing card) renders through the layout item.field, where a template override
 * of that one field or of its type takes over; every other contribution renders
 * its own layout directly.
 *
 * @var array  $displayData ['surfaces' => array[], 'slot' => string, 'item' => object|null, 'tag' => string]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Site\Helper\Layout;

$surfaces = $displayData['surfaces'] ?? [];
$slot     = (string) ($displayData['slot'] ?? '');
$item     = $displayData['item'] ?? null;
$tag      = preg_replace('/[^a-z]/', '', strtolower((string) ($displayData['tag'] ?? 'div'))) ?: 'div';
$product  = ItemSurface::tryFrom($slot) !== null;

foreach ($surfaces as $surface):
    if (empty($surface['layout'])) {
        continue;
    }
    ?>
    <<?php echo $tag; ?> class="item-field-surface" data-surface="<?php echo htmlspecialchars($slot, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $surface['showon'] ?? ''; ?>>
        <?php if ($product && ($surface['fieldId'] ?? null) !== null) : ?>
            <?php echo Layout::render('item.field', ['surface' => $surface, 'slot' => $slot, 'item' => $item]); ?>
        <?php else : ?>
            <?php echo Layout::render(
                $surface['layout'],
                !empty($surface['layoutData']) ? $surface['layoutData'] : $item,
                $surface['layoutPluginType'] ?? '',
                $surface['layoutPluginName'] ?? '',
            ); ?>
        <?php endif; ?>
    </<?php echo $tag; ?>>
<?php endforeach;
