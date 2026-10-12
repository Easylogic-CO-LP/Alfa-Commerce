<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A card that is a chart: an optional lede of figures, the chart, an optional table.
 *
 * Offered to be reused (core's own chart cards name it exactly as a plugin's would),
 * never imposed: a card that wants another shape names another layout, and one that
 * wants a chart INSIDE a shape of its own calls ChartHelper::attach() and draws its
 * own `<div data-alfa-chart="...">`.
 *
 * The figures are not decoration. A chart alone forces the reader to estimate a number
 * off an axis; the lede states it, the delta judges it, and the chart is then evidence
 * rather than the only source. When the chart cannot be attached (no HTML document, a
 * locked asset manager, a kind the renderer does not know), the figures and the table
 * still render and the frame is simply not drawn.
 *
 * NOTHING HERE TAKES HTML FROM ITS CALLER. The table is a column SPEC plus plain rows,
 * exactly like the analytics reports, so a contributor's card can never be the place a
 * stored string becomes markup.
 *
 * @var array $displayData One resolved card. data:
 *   [
 *     'chart'   => array,   // the DTO, as ChartHelper::attach() expects it
 *     'pair'    => bool,    // chart and table side by side instead of stacked
 *     'figures' => array[], // ['value','label','minor'=>bool,'delta'=>['percent','label']|null]
 *     'table'   => [
 *        'columns' => [['key','label','type'=>'text|number|money','align'=>'end']],
 *        'rows'    => [['<key>' => scalar, 'url' => string, 'color' => string]],
 *     ],
 *     'empty'   => string,  // language key shown when there is nothing at all to draw
 *     'more'    => string,  // language key for the corner link
 *   ]
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ChartHelper;
use Joomla\CMS\Language\Text;

$data    = (array) ($displayData['data'] ?? []);
$chart   = (array) ($data['chart'] ?? []);
$figures = (array) ($data['figures'] ?? []);
$table   = (array) ($data['table'] ?? []);
$columns = (array) ($table['columns'] ?? []);
$rows    = (array) ($table['rows'] ?? []);
$empty   = (string) ($data['empty'] ?? 'COM_ALFA_CHART_EMPTY');
$more    = (string) ($data['more'] ?? 'COM_ALFA_DASHCARD_SEE_ALL');
$url     = (string) ($displayData['url'] ?? '');

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// The sheet first, unconditionally: everything below is styled by it, and a card with
// no chart to draw is still a chart card. Attaching decides whether there is a
// container to draw: a false is a context that cannot chart, not an error, and the
// card still says everything else it can.
ChartHelper::attachStyles();

$drawn = $chart !== [] && ChartHelper::attach($chart);
$pair  = $drawn && $rows !== [] && !empty($data['pair']);

/** One cell, formatted by its column's declared type; no row writes presentation. */
$cell = static function (array $row, array $column) use ($e): string {
    $value = $row[$column['key']] ?? null;

    if ($value === null || $value === '') {
        return '<span class="alfa-card-sub">&mdash;</span>';
    }

    // `money` arrives ALREADY FORMATTED by the provider that knew the currency; this
    // layout never guesses a symbol or an exponent.
    $text = match ($column['type'] ?? 'text') {
        'number' => number_format((float) $value),
        default  => (string) $value,
    };

    $swatch = '';

    if (!empty($column['swatch']) && ($row['color'] ?? '') !== '') {
        $swatch = '<span class="alfa-chart-swatch" style="--swatch: '
            . $e((string) $row['color']) . '"></span>';
    }

    $href = (string) ($row['url'] ?? '');

    return $swatch . ($href !== '' && ($column['link'] ?? false)
        ? '<a href="' . $e($href) . '">' . $e($text) . '</a>'
        : $e($text));
};
?>
<span class="alfa-card-head">
    <span class="alfa-card-title"><?php echo Text::_($displayData['title']); ?></span>

    <?php if (($displayData['subtitle'] ?? '') !== '') : ?>
        <span class="alfa-card-sub"><?php echo Text::_($displayData['subtitle']); ?></span>
    <?php endif; ?>

    <?php if ($url !== '') : ?>
        <a class="alfa-card-more alfa-card-more--button" href="<?php echo $e($url); ?>">
            <?php echo Text::_($more); ?>
        </a>
    <?php endif; ?>
</span>

<div class="alfa-chart-hero">
    <?php if ($figures !== []) : ?>
        <div class="alfa-chart-lede">
            <?php foreach ($figures as $figure) : ?>
                <div class="alfa-chart-figure<?php echo !empty($figure['minor']) ? ' alfa-chart-figure--minor' : ''; ?>">
                    <span class="alfa-chart-amount">
                        <?php echo $e((string) ($figure['value'] ?? '')); ?>

                        <?php
                        $delta = $figure['delta'] ?? null;

                        if (\is_array($delta)) :
                            // Flat is its own state: "no change" is an answer, and painting it
                            // green or red would invent a direction the data does not have.
                            $percent = (float) ($delta['percent'] ?? 0.0);
                            $trend   = $percent > 0.5 ? 'up' : ($percent < -0.5 ? 'down' : 'flat');
                            $arrow   = [
                                'up'   => '<path d="M12 19V5M5 12l7-7 7 7"/>',
                                'down' => '<path d="M12 5v14M19 12l-7 7-7-7"/>',
                                'flat' => '<path d="M5 12h14"/>',
                            ][$trend];
                            ?>
                            <span class="alfa-chart-delta" data-trend="<?php echo $trend; ?>"
                                  title="<?php echo $e(Text::_((string) ($delta['label'] ?? ''))); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $arrow; ?></svg>
                                <?php echo $e(($percent > 0 ? '+' : '') . number_format($percent, 1) . '%'); ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="alfa-chart-label"><?php echo $e(Text::_((string) ($figure['label'] ?? ''))); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div<?php echo $pair ? ' class="alfa-chart-pair"' : ''; ?>>
        <?php if ($drawn) : ?>
            <div class="alfa-card-chart" data-alfa-chart="<?php echo $e((string) $chart['key']); ?>"
                 role="img" aria-label="<?php echo $e((string) ($chart['title'] ?? Text::_($displayData['title']))); ?>"></div>
        <?php endif; ?>

        <?php if ($rows !== [] && $columns !== []) : ?>
            <table class="alfa-chart-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $column) : ?>
                            <th<?php echo ($column['align'] ?? '') === 'end' ? ' class="is-numeric"' : ''; ?>>
                                <?php echo $e(Text::_((string) ($column['label'] ?? ''))); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <?php foreach ($columns as $index => $column) : ?>
                                <?php
                                $classes = [];

                                if (($column['align'] ?? '') === 'end') {
                                    $classes[] = 'is-numeric';
                                }

                                if ($index === 0) {
                                    $classes[] = 'is-name';
                                }
                                ?>
                                <td<?php echo $classes ? ' class="' . implode(' ', $classes) . '"' : ''; ?>>
                                    <?php echo $cell((array) $row, (array) $column); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if (!$drawn && $rows === []) : ?>
        <?php // Figures alone leave a hole where the chart belongs, and a reader who is
              // not told why assumes something failed. Every caller states its own
              // sentence for this, so the card says what the silence means. ?>
        <p class="alfa-chart-empty"><?php echo Text::_($empty); ?></p>
    <?php endif; ?>
</div>
