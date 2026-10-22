<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;

/**
 * Price-matrix editor wrapper: the existing rows, the <template> the add
 * button clones, and the add button itself.
 *
 * @var array $displayData
 *   - string $controlBase POST base, e.g. 'jform[prices]'
 *   - array  $rows        Existing price rows
 *   - bool   $showModify  Offer the price/adjust mode (combination context)
 *   - array  $options     Select option lists (currencies/places/usergroups/users)
 *   - array|null $storedMemberships The item editor of a saved item: the categories and manufacturers it has
 *                stored (ids ascending, comma-separated), which prices.js compares with its form's (2.1.0)
 */
$controlBase = (string) $displayData['controlBase'];
$rows = (array) $displayData['rows'];
$showModify = (bool) ($displayData['showModify'] ?? false);
$options = (array) $displayData['options'];
$preview = $displayData['preview'] ?? static fn (array $row): array => ['a' => 1.0, 'b' => 0.0, 'taxFactor' => 1.0, 'taxes' => [], 'discounts' => []];
$stored = $displayData['storedMemberships'] ?? null;

$rowLayout = new FileLayout('prices.row', JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');

$renderRow = static fn (string $base, array $row) => $rowLayout->render([
    'base' => $base,
    'row' => $row,
    'showModify' => $showModify,
    'options' => $options,
    'preview' => $preview($row),
    'itemId' => (int) ($displayData['itemId'] ?? 0),
]);
?>
<div class="alfa-prices" data-control="<?php echo htmlspecialchars($controlBase, ENT_QUOTES, 'UTF-8'); ?>"
     data-item-id="<?php echo (int) ($displayData['itemId'] ?? 0); ?>"<?php if (\is_array($stored)) : ?>
     data-item-categories="<?php echo htmlspecialchars((string) $stored['categories'], ENT_QUOTES, 'UTF-8'); ?>"
     data-item-manufacturers="<?php echo htmlspecialchars((string) $stored['manufacturers'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
    <div class="prices-rows" data-price-rows>
        <?php foreach (array_values($rows) as $index => $row) : ?>
            <?php echo $renderRow($controlBase . '[' . $index . ']', (array) $row); ?>
        <?php endforeach; ?>
    </div>

    <template data-price-template><?php echo $renderRow($controlBase . '[__PIDX__]', []); ?></template>

    <button type="button" class="btn btn-sm btn-secondary mt-1" data-price-add>
        <span class="icon-plus" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_PRICES_ADD'); ?>
    </button>
</div>
