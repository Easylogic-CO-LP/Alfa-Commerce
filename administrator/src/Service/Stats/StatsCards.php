<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Stats;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ChartHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Language\Text;

/**
 * Core's readings in the shape the `card.chart` layout draws: the takings hero, and the
 * money formatter every card on the screen must share.
 *
 * ONE PLACE TURNS A READING INTO A CARD, whoever places it. Core puts the hero on the
 * dashboard; the Analytics plugin draws the same hero again on its own screen over any
 * range a merchant picks, and formats its own cards' money through {@see self::money()}.
 * If each caller built its own, the same figure would be formatted two ways and disagree
 * with itself the first time one of them was edited.
 *
 * Calls point plugin -> core: the plugin asks this for a card and names a layout core
 * ships. Nothing here knows the plugin exists.
 *
 * @since  1.10.0
 */
final class StatsCards
{
    /**
     * @param DashboardStatsService $stats The window every card reads from.
     *
     * @since  1.10.0
     */
    public function __construct(private readonly DashboardStatsService $stats)
    {
    }

    /**
     * The window these cards cover, for a caller that draws its own frame around them.
     *
     *
     * @since  1.10.0
     */
    public function stats(): DashboardStatsService
    {
        return $this->stats;
    }

    /**
     * Format an amount the way the store writes money, or a bare number when no
     * currency is configured yet.
     *
     * @param float $amount The amount.
     *
     *
     * @since  1.10.0
     */
    public function money(float $amount): string
    {
        $currency = $this->stats->currency();

        return $currency === null
            ? number_format($amount, 2)
            : Money::of($amount, $currency)->format();
    }

    /**
     * An amount as a spreadsheet reads it: at the store currency's own scale, '.' as
     * the point, no symbol and no grouping, rounded once as {@see Money::of()} rounds.
     * With no currency configured yet, the number alone at two decimals, as money()
     * prints it.
     *
     * @param float|int|string $amount The amount.
     *
     *
     * @since  1.14.0
     */
    public function moneyFigure(float|int|string $amount): string
    {
        $currency = $this->stats->currency();

        if ($currency === null) {
            return number_format((float) $amount, 2, '.', '');
        }

        $scale = $currency->getDecimalPlaces();
        $minor = Money::of(amount: $amount, currency: $currency)->getMinorUnits();
        $digits = str_pad((string) abs($minor), $scale + 1, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '') . ($scale > 0 ? substr($digits, 0, -$scale) . '.' . substr($digits, -$scale) : $digits);
    }

    /**
     * A unit price the way the store writes money, by the one unit rule
     * ({@see Currency::formatUnit()}): from its line's own whole amount, quantity and
     * explicit custom rate, under the shop's unit display setting, at the store
     * currency's scale. A unit is presentation: the line total is the money.
     *
     * @param float|int|string $unit The stored unit price.
     * @param float|int|string $lineTotal The line's whole amount, in the unit's tax space.
     * @param float|int|string $quantity The line's quantity.
     * @param float|int|string|null $rate The line's explicit custom rate, beside a gross unit only.
     * @param bool $plain The bare figure: '.' as the point, no symbol and no
     *                    grouping, always in its Auto form (a spreadsheet's
     *                    number is data and never follows a display choice).
     *
     *
     * @since  1.14.0
     */
    public function unit(
        float|int|string $unit,
        float|int|string $lineTotal,
        float|int|string $quantity,
        float|int|string|null $rate = null,
        bool $plain = false,
    ): string {
        $currency = $this->stats->currency();

        if ($currency !== null && !$plain) {
            return $currency->formatUnit(unit: $unit, lineTotal: $lineTotal, quantity: $quantity, rate: $rate);
        }

        // The bare figure at the currency's scale, always the Auto form; with no
        // currency configured yet, the number alone at two decimals, as money() prints it.
        $figure = Currency::unitFigure(
            unit: $unit,
            lineTotal: $lineTotal,
            quantity: $quantity,
            scale: $currency?->getDecimalPlaces() ?? 2,
            rate: $rate,
            auto: $plain || Currency::unitPrecisionIsAuto(),
        )['figure'];

        return $plain ? $figure : Currency::groupFigure(figure: $figure, decimalSeparator: '.', thousandSeparator: ',');
    }

    /**
     * THE HERO: takings, refunds and orders over the period, with the movement against
     * the period before it.
     *
     * The figures come first and the chart second on purpose. A merchant who only reads
     * the top line still learns what the shop took and whether that is better or worse
     * than last month; the chart is there for the one who wants to know WHICH days.
     *
     *
     * @since  1.10.0
     */
    public function trend(): array
    {
        $stats = $this->stats;
        $money = $stats->money();
        $days = $stats->days();

        // NOTHING HAPPENED IS NOT A TREND. `delta()` answers 0.0 both for "flat" and
        // for "there was no previous figure to divide by", so a shop that has never
        // taken a payment was reading its zeros back with a "0.0% against the previous
        // 30 days" badge beside each one: a comparison of nothing to nothing, stated
        // as if it were a measurement. The same condition suppresses the chart below.
        $blank = $money['orderCount'] === 0 && $money['total'] <= 0.0;
        $delta = static fn (float $percent): ?array => $blank ? null : [
            'percent' => $percent,
            'label' => Text::sprintf('COM_ALFA_DASHCARD_TREND_VS_PREVIOUS', $days),
        ];

        $figures = [
            [
                'value' => $this->money($money['total']),
                'label' => Text::sprintf('COM_ALFA_DASHCARD_TREND_TAKEN', $days),
                'delta' => $delta($money['revenueDelta']),
            ],
            [
                'value' => number_format($money['orderCount']),
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_ORDERS'),
                'minor' => true,
                'delta' => $delta($money['ordersDelta']),
            ],
            [
                // Average order value: the takings divided by the orders that actually
                // carried money, not by every order placed: an unpaid order has no
                // value to average and would quietly drag the figure down.
                'value' => $this->money($money['paidOrders'] > 0 ? $money['total'] / $money['paidOrders'] : 0.0),
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_AOV'),
                'minor' => true,
            ],
        ];

        if ($money['refunded'] > 0.0) {
            // Only when there is something to say. A permanent "0,00 refunded" is noise.
            $figures[] = [
                'value' => $this->money($money['refunded']),
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_REFUNDED'),
                'minor' => true,
            ];
        }

        if ($money['chargedBack'] > 0.0) {
            // Taken back by the bank: its own figure, never a refund.
            $figures[] = [
                'value' => $this->money($money['chargedBack']),
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_CHARGED_BACK'),
                'minor' => true,
            ];
        }

        if ($money['others'] > 0) {
            $figures[] = [
                'value' => (string) $money['others'],
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_OTHER_CURRENCIES'),
                'minor' => true,
            ];
        }

        if (($money['noCurrency'] ?? 0) > 0) {
            // The money of an order that names no currency is in none of
            // the figures above: such orders are counted here and said, and
            // never valued in the store's currency.
            $figures[] = [
                'value' => number_format($money['noCurrency']),
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_NO_CURRENCY'),
                'minor' => true,
            ];
        }

        $series = [
            [
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_TAKINGS_SERIES'),
                'values' => array_map(static fn (float $v): float => round($v, 2), $money['revenue']),
                'money' => true,
            ],
        ];

        if ($money['refunded'] > 0.0) {
            $series[] = [
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_REFUNDED'),
                'values' => array_map(static fn (float $v): float => round($v, 2), $money['refunds']),
                'money' => true,
            ];
        }

        if ($money['chargedBack'] > 0.0) {
            $series[] = [
                'label' => Text::_('COM_ALFA_DASHCARD_TREND_CHARGED_BACK'),
                'values' => array_map(static fn (float $v): float => round($v, 2), $money['chargebacks']),
                'money' => true,
            ];
        }

        $series[] = [
            'label' => Text::_('COM_ALFA_DASHCARD_TREND_ORDERS'),
            'values' => $money['orders'],
            'axis' => 'right',
        ];

        return [
            'figures' => $figures,
            'empty' => 'COM_ALFA_DASHCARD_TREND_EMPTY',
            'more' => 'COM_ALFA_DASHCARD_SEE_ALL',
            'chart' => $blank ? [] : array_merge([
                'key' => 'stats.money.trend',
                'kind' => 'area',
                'labels' => $money['labels'],
                'series' => $series,
            ], ChartHelper::moneyFormat($stats->currency())),
        ];
    }
}
