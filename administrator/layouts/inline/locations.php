<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Inline per-location STOCK editor: one input per location, rendered on demand
 * (task=<entity>.inlineLoad) and swapped into the cell by
 * media/com_alfa/js/admin/inline-edit.js. Same .ml-field markup as
 * inline/multilingual.php, so "one box per language" and "one box per location"
 * read as the same control; the location name simply takes the flag's place.
 *
 * Each input carries the ABSOLUTE on-hand count for that location; merchants
 * count stock, they do not compute deltas. data-name is the location id, which
 * the controller turns into one journalled adjustment per changed location.
 * data-baseline is the figure as rendered, identical to the value: the
 * controller treats a figure equal to its baseline as untouched, so saving an
 * editor left open while stock moved does not undo that movement.
 *
 * @var array $displayData
 *   - locations array<int, array{location_id:int,title:string,published:bool,physical:float,reserved:float,available:float}>
 *   - single    bool  Hide the location labels when the shop has only one.
 *   - hint      string  Optional explanation printed under the inputs.
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Joomla\CMS\Language\Text;

$locations = $displayData['locations'] ?? [];
$single    = !empty($displayData['single']) || \count($locations) <= 1;
$hint      = (string) ($displayData['hint'] ?? '');
?>
<div class="ml-field-stack">
    <?php foreach ($locations as $location) : ?>
        <?php
            $locationId = (int) ($location['location_id'] ?? 0);
            $title      = (string) ($location['title'] ?? '');
            $reserved   = (float) ($location['reserved'] ?? 0);
            $figure     = InventoryAvailabilityService::formatQuantity(value: (float) ($location['physical'] ?? 0));

            // Reserved is stock this location owes to open orders. Surfacing it
            // next to the box prevents the classic correction mistake: typing
            // the sellable figure into a field that means physical.
            $note = $reserved > 0
                ? Text::sprintf(
                    'COM_ALFA_INLINE_STOCK_LOCATION_RESERVED',
                    InventoryAvailabilityService::formatQuantity($reserved),
                )
                : '';
        ?>
        <div class="ml-field-item">
            <input type="number" step="any" class="form-control form-control-sm alfa-inline-input"
                   data-name="<?php echo $locationId; ?>"
                   data-baseline="<?php echo htmlspecialchars($figure, ENT_QUOTES, 'UTF-8'); ?>"
                   value="<?php echo htmlspecialchars($figure, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if (!$single) : ?>
                <span class="ml-field-flag ml-field-flag--text alfa-inline-location<?php echo empty($location['published']) ? ' is-unpublished' : ''; ?>"
                      title="<?php echo htmlspecialchars(
                          $title . ($note !== '' ? ' - ' . $note : ''),
                          ENT_QUOTES,
                          'UTF-8',
                      ); ?>">
                    <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($hint !== '') : ?>
    <div class="alfa-inline-hint"><?php echo htmlspecialchars($hint, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
