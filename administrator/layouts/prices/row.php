<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;

/**
 * One price rule as a card, everything always visible:
 *
 *   grid     AMOUNT (mode/operation/price/sale) | CONDITIONS (quantity
 *            range over active window, stacked) | APPLIES TO (scope selects,
 *            one per line); corner: Enabled switch + delete, top right
 *
 * Class names are plain (price-row, price-cluster, ...) because all styling
 * and behavior is scoped under the .alfa-prices root. Both modes share the
 * FULL dimension set; only the sale-price cell is price-mode-only. Input
 * names are the exact ItemModel::setPrices() contract.
 *
 * @var array $displayData
 *   - string $base       POST base of the row, e.g. 'jform[prices][0]'
 *   - array  $row        Row values (empty = new row defaults)
 *   - bool   $showModify Offer the price/adjust mode (combination context)
 *   - array  $options    Select option lists
 */
$base = (string) $displayData['base'];
$row = (array) $displayData['row'];
$showModify = (bool) ($displayData['showModify'] ?? false);
$options = (array) $displayData['options'];
$preview = (array) ($displayData['preview'] ?? []);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mode = (string) ($row['_mode'] ?? 'price');
$operation = (string) ($row['operation'] ?? '+');
$active = !isset($row['state']) || (int) $row['state'] === 1;
$value = trim((string) ($row['value'] ?? ''));
$override = trim((string) ($row['override_value'] ?? ''));
$overrideType = in_array($row['override_type'] ?? '', ['start', 'final'], true) ? $row['override_type'] : 'start';

// Adjust target (net/gross): for an adjust row, override_type is repurposed as
// where the delta applies. New rows take the global default. Posted as a
// SEPARATE key so it never collides with the override's override_type radios.
$adjustTarget = ($mode === 'adjust' && in_array($row['override_type'] ?? '', ['start', 'final'], true))
    ? $row['override_type']
    : \Joomla\CMS\Component\ComponentHelper::getParams('com_alfa')->get('combination_adjust_target', 'final');
$adjustTarget = in_array($adjustTarget, ['start', 'final'], true) ? $adjustTarget : 'final';

// The editable pair is net <-> net*tax ONLY: discounts are time-varying, so
// the stored prices must not depend on whichever discount runs today. The
// engine's full result (final = a*net + b, discounts included, from two real
// engine runs under this row's dimensions) renders as a read-only
// "after discounts" figure beside the applied chips.
$calcA = (float) ($preview['a'] ?? 1.0);
$calcB = (float) ($preview['b'] ?? 0.0);
$taxFactor = (float) ($preview['taxFactor'] ?? 1.0);
// A new item has no tax stored: its taxes follow its categories. A price typed
// with tax cannot be worked back to its base, so the with-tax box is rendered
// shut; prices.js opens it once item.pricePreview has answered for the
// categories the item form holds.
$taxKnown = (int) ($displayData['itemId'] ?? 0) > 0;
// The item base an ADJUST row applies its delta to, resolved by the storefront
// engine under this row's own dimensions and quantity tier, like the tax factor.
$baseNet = (float) ($preview['baseNet'] ?? 0.0);
// The row's currency dictates precision (EUR 2, JPY 0, BTC 8): gross money
// uses its decimal places, nets carry two guard digits more so every final
// price stays reachable by back-computation. Server formatting matches the
// live JS toFixed exactly, so a copied row reads identically.
$decimals = (int) ($preview['decimals'] ?? 2);
$netDecimals = $decimals + 2;
$money = static fn (float $amount, int $places): string => number_format($amount, $places, '.', '');
$stepOf = static fn (int $places): string => $places > 0 ? '0.' . str_repeat('0', $places - 1) . '1' : '1';
// A stored price or sale is shown and posted as stored, to the catalogue's
// eight places: the browser never shortens it, the save judges it.
$storedStep = $stepOf(8);

// Operation cycler (adjust mode): the current op as a prefix button, click
// cycles + / - / +% / -%; same component as the grid quick-adjust and the
// options rows (combinations.js [data-op-cycle] handler).
$opCycle = static function (string $name, ?string $current): string {
    $current = in_array($current, ['+', '-', '%', '-%'], true) ? $current : '+';
    $labels = ['+' => '+', '-' => '−', '%' => '+%', '-%' => '−%'];

    return '<span class="op-cycle">'
        . '<button type="button" data-op-cycle title="' . $labels[$current] . '">' . $labels[$current] . '</button>'
        . '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . '">'
        . '</span>';
};

// Mode cycler (set <-> adjust): a single cycle button, same chrome as the grid's
// outer toggle. The hidden input keeps data-price-mode so existing JS resolves
// the value; prices.js cycles it on click.
$modeCycle = static function (string $name, string $current): string {
    $current = $current === 'adjust' ? 'adjust' : 'price';
    $labels = ['price' => Text::_('COM_ALFA_PRICES_MODE_PRICE'), 'adjust' => Text::_('COM_ALFA_PRICES_MODE_ADJUST')];
    $label = htmlspecialchars($labels[$current], ENT_QUOTES, 'UTF-8');

    return '<span class="op-cycle mode-cycle">'
        . '<button type="button" data-price-mode-cycle'
        . ' data-label-price="' . htmlspecialchars($labels['price'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-label-adjust="' . htmlspecialchars($labels['adjust'], ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . $label . '">' . $label . '</button>'
        . '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . $current . '" data-price-mode>'
        . '</span>';
};

// Price mode renders the final/after server-side (value IS the net). An adjust
// row's value is a delta, so its final depends on the item base; prices.js
// fills those on load from the row's own data-base-net.
$finalValue = ($taxKnown && $mode === 'price' && $value !== '') ? $money((float) $value * $taxFactor, $decimals) : '';
$appliedTaxes = (array) ($preview['taxes'] ?? []);
$appliedDiscounts = (array) ($preview['discounts'] ?? []);

// The tax/discount pipeline chips, built once in APPLICATION order (before-tax
// discounts -> taxes -> after-tax discounts). Rendered in the header for the
// combination popup, or under the final price for the item; prices.js refreshes
// whichever [data-price-pipeline] element by selector.
$discountChip = static fn (array $d): string => '<span class="applied-discount">' . htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8')
    . ($d['type'] === 'percentage' ? ' −' . rtrim(rtrim(number_format((float) $d['percent'], 2, '.', ''), '0'), '.') . '%' : '') . '</span>';
$taxChip = static fn (array $t): string => '<span class="applied-tax">' . htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8')
    . ' +' . rtrim(rtrim(number_format((float) $t['rate'], 2, '.', ''), '0'), '.') . '%</span>';

$pipelineChips = '';

foreach ($appliedDiscounts as $pipelineDiscount) {
    $pipelineChips .= ($pipelineDiscount['timing'] ?? 'before_tax') === 'before_tax' ? $discountChip((array) $pipelineDiscount) : '';
}

foreach ($appliedTaxes as $pipelineTax) {
    $pipelineChips .= $taxChip((array) $pipelineTax);
}

foreach ($appliedDiscounts as $pipelineDiscount) {
    $pipelineChips .= ($pipelineDiscount['timing'] ?? 'before_tax') === 'after_tax' ? $discountChip((array) $pipelineDiscount) : '';
}

// Custom discount: the computed other end - Starting pins the net, so show
// the resulting final (taxes on top); Final pins the gross, show the net.
$overrideOn = $override !== '' ? $overrideType : '';
$overrideHint = '';

if ($overrideOn === 'final') {
    $overrideHint = Text::_('COM_ALFA_PRICES_PRICE') . ': ' . $money((float) $override / max($taxFactor, 0.000001), $netDecimals);
} elseif ($overrideOn === 'start') {
    $overrideHint = Text::_('COM_ALFA_PRICES_FINAL') . ': ' . $money((float) $override * $taxFactor, $decimals);
}

// "After discounts" is the engine's result for the row: a row carrying its own
// sale is charged the sale, with no regular discount, and a sale below zero is
// charged as zero, as the engine floors it. Any other row is a*net + b, never
// below zero: a fixed amount is capped at what the line holds, so a unit below
// it is charged nothing.
$discountedValue = '';

if ($mode === 'price' && $value !== '') {
    $discountedValue = match ($overrideOn) {
        'final' => $money(max(0.0, (float) $override), $decimals),
        'start' => $money(max(0.0, (float) $override) * $taxFactor, $decimals),
        default => $money(max(0.0, ((float) $value * $calcA) + $calcB), $decimals),
    };
}

$selectHtml = static function (string $name, array $list, $current, string $anyLabel) use ($esc) {
    $current = (string) (int) $current;
    $html = '<select name="' . $esc($name) . '" class="form-select form-select-sm">';
    $html .= '<option value="0">' . $esc($anyLabel) . '</option>';

    foreach ($list as $id => $label) {
        $html .= '<option value="' . (int) $id . '"' . ($current === (string) $id ? ' selected' : '') . '>' . $esc($label) . '</option>';
    }

    return $html . '</select>';
};

// REAL Joomla calendar fields: clone-safe because prices.js dispatches
// `joomla:updated` on inserted rows (core's re-init contract).
$clean = static fn ($value) => (str_starts_with((string) $value, '0000') ? '' : (string) ($value ?? ''));

$calendarForm = new Form('com_alfa.pricerow.' . md5($base), ['control' => $base]);
$calendarForm->load('<form>
    <field name="publish_up" type="calendar" showtime="true" format="%Y-%m-%d %H:%M" filter="USER_UTC" hiddenLabel="true" label="COM_ALFA_PRICES_FROM"/>
    <field name="publish_down" type="calendar" showtime="true" format="%Y-%m-%d %H:%M" filter="USER_UTC" hiddenLabel="true" label="COM_ALFA_PRICES_TO"/>
</form>');
$calendarForm->bind([
    'publish_up' => $clean($row['publish_up'] ?? ''),
    'publish_down' => $clean($row['publish_down'] ?? ''),
]);
?>
<div class="price-row" data-mode="<?php echo $esc($mode); ?>"
     data-override="<?php echo $override !== '' ? $esc($overrideType) : ''; ?>"
     data-calc-a="<?php echo $esc($calcA); ?>" data-calc-b="<?php echo $esc($calcB); ?>"
     data-tax-factor="<?php echo $esc($taxFactor); ?>" data-decimals="<?php echo $decimals; ?>"
     data-base-net="<?php echo $esc($baseNet); ?>"
     data-base-sale="<?php echo !empty($preview['baseSale']) ? '1' : '0'; ?>">
    <input type="hidden" name="<?php echo $esc($base); ?>[id]" value="<?php echo (int) ($row['id'] ?? 0); ?>">

    <!-- Header strip: mode + pipeline chips (combination only) + status + delete -->
    <div class="price-head">
        <?php if ($showModify) : ?>
            <?php echo $modeCycle($base . '[_mode]', $mode); ?>
            <div class="chip-line price-head-chips" data-price-pipeline><?php echo $pipelineChips; ?></div>
        <?php endif; ?>
        <label class="price-active">
            <input type="hidden" name="<?php echo $esc($base); ?>[price_status]" value="0">
            <input type="checkbox" class="form-check-input" name="<?php echo $esc($base); ?>[price_status]" value="1" <?php echo $active ? 'checked' : ''; ?>>
            <span><?php echo Text::_('JENABLED'); ?></span>
        </label>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-price-copy
                title="<?php echo Text::_('COM_ALFA_PRICES_COPY_DESC'); ?>" aria-label="<?php echo Text::_('COM_ALFA_PRICES_COPY'); ?>">
            <span class="icon-copy" aria-hidden="true"></span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-price-remove aria-label="<?php echo Text::_('JACTION_DELETE'); ?>">
            <span class="icon-trash" aria-hidden="true"></span>
        </button>
    </div>

    <div class="price-line">
        <div class="price-cluster price-cluster-amount" data-caption="<?php echo Text::_('COM_ALFA_PRICES_GROUP_AMOUNT'); ?>">
            <div class="price-cell">
                <?php if (!$showModify) : ?>
                    <label>
                        <span class="price-only"><?php echo Text::_('COM_ALFA_PRICES_PRICE'); ?></span>
                        <span class="adjust-only"><?php echo Text::_('COM_ALFA_PRICES_AMOUNT'); ?></span>
                    </label>
                <?php endif; ?>
                <div class="amount-line">
                    <?php if ($showModify) : ?>
                        <span class="adjust-only"><?php echo $opCycle($base . '[operation]', $operation); ?></span>
                    <?php endif; ?>
                    <input type="number" step="<?php echo $storedStep; ?>" name="<?php echo $esc($base); ?>[value]"
                           value="<?php echo $esc($value); ?>" class="form-control form-control-sm" data-price-value required>
                </div>
            </div>

            <?php if ($showModify) : ?>
                <!-- Adjust target as a clickable cycle (like the grid quick edit):
                     apply the +/-/% on the net (pre-tax) or the final (gross). -->
                <?php
                $targetLabels = ['final' => Text::_('COM_ALFA_PRICES_TARGET_GROSS'), 'start' => Text::_('COM_ALFA_PRICES_TARGET_NET')];
                $targetLabel = $esc($targetLabels[$adjustTarget] ?? $targetLabels['final']);
                ?>
                <div class="price-cell price-cell-sm adjust-only">
                    <label><?php echo Text::_('COM_ALFA_PRICES_ADJUST_ON'); ?></label>
                    <span class="op-cycle">
                        <button type="button" data-price-target-cycle
                                data-label-final="<?php echo $esc($targetLabels['final']); ?>"
                                data-label-start="<?php echo $esc($targetLabels['start']); ?>"
                                title="<?php echo $targetLabel; ?>"><?php echo $targetLabel; ?></button>
                        <input type="hidden" name="<?php echo $esc($base); ?>[adjust_target]" value="<?php echo $esc($adjustTarget); ?>" data-price-target>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Tax-inclusive twin of the net price (taxes ONLY; discounts
                 are time-varying and must not bend the stored price).
                 Two-way: typing here back-computes the net. Never posted.
                 The tax chips below explain the Price -> Final gap. -->
            <!-- ONE pipeline line, in APPLICATION order exactly like the
                 frontend breakdown: before-tax discounts -> taxes -> after-tax
                 discounts. No discounts running = just the tax chips. -->
            <div class="price-cell">
                <label><?php echo Text::_('COM_ALFA_PRICES_FINAL'); ?></label>
                <input type="number" step="<?php echo $stepOf($decimals); ?>" value="<?php echo $esc($finalValue); ?>"
                       class="form-control form-control-sm" data-price-final placeholder="-"
                       <?php echo $taxKnown ? '' : 'disabled title="' . $esc(Text::_('COM_ALFA_PRICES_FINAL_CATEGORY_FIRST')) . '"'; ?>>
                <?php if (!$showModify) : ?>
                    <div class="chip-line" data-price-pipeline><?php echo $pipelineChips; ?></div>
                <?php endif; ?>
            </div>

            <!-- TODAY's selling price: the engine result with the currently
                 running discounts. Read-only: it comes and goes with the
                 calendar, the stored prices above do not. -->
            <div class="price-cell" data-price-discounts <?php echo $appliedDiscounts ? '' : 'hidden'; ?>>
                <label><?php echo Text::_('COM_ALFA_PRICES_DISCOUNTED'); ?></label>
                <div class="chip-line">
                    <strong data-price-current><?php echo $esc($discountedValue); ?></strong>
                </div>
            </div>

            <!-- Custom discount: replaces every regular discount with one
                 fixed price. Segmented: Off / pins the starting (net)
                 price (taxes still apply on top) / pins the final gross
                 (tax carved out at runtime, customer pays exactly this). -->
            <div class="price-cell price-only">
                <label><?php echo Text::_('COM_ALFA_PRICES_OVERRIDE'); ?></label>
                <div class="seg-group">
                    <label class="seg" title="<?php echo Text::_('COM_ALFA_PRICES_OVERRIDE_OFF_DESC'); ?>">
                        <input type="radio" name="<?php echo $esc($base); ?>[override_type]" value="" data-price-override-type <?php echo $overrideOn === '' ? 'checked' : ''; ?>>
                        <span><?php echo Text::_('COM_ALFA_PRICES_OVERRIDE_OFF'); ?></span>
                    </label>
                    <label class="seg" title="<?php echo Text::_('COM_ALFA_PRICES_OVERRIDE_START_DESC'); ?>">
                        <input type="radio" name="<?php echo $esc($base); ?>[override_type]" value="start" data-price-override-type <?php echo $overrideOn === 'start' ? 'checked' : ''; ?>>
                        <span><?php echo Text::_('COM_ALFA_PRICES_OVERRIDE_START'); ?></span>
                    </label>
                    <label class="seg" title="<?php echo Text::_('COM_ALFA_PRICES_OVERRIDE_FINAL_DESC'); ?>">
                        <input type="radio" name="<?php echo $esc($base); ?>[override_type]" value="final" data-price-override-type <?php echo $overrideOn === 'final' ? 'checked' : ''; ?>>
                        <span><?php echo Text::_('COM_ALFA_PRICES_OVERRIDE_FINAL'); ?></span>
                    </label>
                </div>
                <!-- A pinned FINAL price is customer-facing money: whole
                     cents only. The net variant keeps 4-decimal precision. -->
                <input type="number" step="<?php echo $storedStep; ?>"
                       name="<?php echo $esc($base); ?>[override_value]"
                       value="<?php echo $esc($override); ?>" class="form-control form-control-sm mt-1"
                       data-price-override placeholder="-" <?php echo $overrideOn === '' ? 'disabled' : ''; ?>>
                <div class="override-hint" data-price-override-hint <?php echo $overrideHint === '' ? 'hidden' : ''; ?>><?php echo $esc($overrideHint); ?></div>
            </div>

        </div>

        <div class="price-cluster price-cluster-col" data-caption="<?php echo Text::_('COM_ALFA_PRICES_GROUP_CONDITIONS'); ?>">
            <div class="price-cell">
                <label><?php echo Text::_('COM_ALFA_PRICES_QTY'); ?></label>
                <div class="range-pair">
                    <input type="number" step="any" min="0" name="<?php echo $esc($base); ?>[quantity_start]"
                           value="<?php echo $esc($row['quantity_start'] ?? ''); ?>" class="form-control form-control-sm"
                           placeholder="<?php echo Text::_('COM_ALFA_PRICES_QTY_FROM'); ?>">
                    <span aria-hidden="true">-</span>
                    <input type="number" step="any" min="0" name="<?php echo $esc($base); ?>[quantity_end]"
                           value="<?php echo $esc($row['quantity_end'] ?? ''); ?>" class="form-control form-control-sm"
                           placeholder="<?php echo Text::_('COM_ALFA_PRICES_QTY_TO'); ?>">
                </div>
            </div>
            <div class="price-cell">
                <label><?php echo Text::_('COM_ALFA_PRICES_ACTIVE'); ?></label>
                <div class="range-pair">
                    <?php echo $calendarForm->getField('publish_up')->input; ?>
                    <span aria-hidden="true">&rarr;</span>
                    <?php echo $calendarForm->getField('publish_down')->input; ?>
                </div>
            </div>
        </div>

        <div class="price-cluster price-cluster-col" data-caption="<?php echo Text::_('COM_ALFA_PRICES_GROUP_SCOPE'); ?>">
            <div class="price-inline">
                <label><?php echo Text::_('COM_ALFA_PRICES_CURRENCY'); ?></label>
                <?php echo $selectHtml($base . '[currency_id]', $options['currencies'], $row['currency_id'] ?? 0, Text::_('COM_ALFA_PRICES_ANY')); ?>
            </div>
            <div class="price-inline">
                <label><?php echo Text::_('COM_ALFA_PRICES_COUNTRY'); ?></label>
                <?php echo $selectHtml($base . '[country_id]', $options['places'], $row['country_id'] ?? 0, Text::_('COM_ALFA_PRICES_ANY')); ?>
            </div>
            <div class="price-inline">
                <label title="<?php echo Text::_('COM_ALFA_PRICES_GROUP_DESC'); ?>"><?php echo Text::_('COM_ALFA_PRICES_GROUP'); ?></label>
                <?php echo $selectHtml($base . '[usergroup_id]', $options['usergroups'], $row['usergroup_id'] ?? 0, Text::_('COM_ALFA_PRICES_ANY')); ?>
            </div>
            <div class="price-inline">
                <label><?php echo Text::_('COM_ALFA_PRICES_USER'); ?></label>
                <?php echo $selectHtml($base . '[user_id]', $options['users'], $row['user_id'] ?? 0, Text::_('COM_ALFA_PRICES_ANY')); ?>
            </div>
        </div>
    </div>
</div>
