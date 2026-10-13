<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Throwable;

/**
 * The one door a chart goes through.
 *
 * WHOEVER OWNS THE DATA OWNS THE DTO; NOBODY OWNS THE LIBRARY. A caller hands over a
 * plain `['key' => ..., 'kind' => ..., ...]` structure with every string already translated
 * and every amount already formatted, and this helper loads the renderer and publishes
 * it. `media/com_alfa/js/admin/charts.js` is the only file that knows which charting
 * library draws it, which is what makes the library replaceable.
 *
 * Three things this owns so no layout repeats them:
 *
 *  - **The document guard.** A card is not only rendered into a web page: the probe
 *    harness, the API and any CLI render reach a layout with no HTML document at all,
 *    and asking such a context for a WebAssetManager is a fatal. Charts are
 *    presentation: a chart that cannot be attached degrades to whatever the layout
 *    drew beside it rather than taking the render down.
 *
 *  - **The asset.** `com_alfa.charts` pulls `com_alfa.echarts` through its declared
 *    dependency, so a screen with no chart on it never loads a megabyte of library.
 *
 *  - **The merge shape.** `Document::addScriptOptions()` merges with
 *    `array_replace_recursive`, which on a NUMERIC-indexed list replaces by position:
 *    two cards each publishing `[$dto]` would silently clobber one another at index 0.
 *    Everything is keyed by the chart's own key here, which is the only shape that
 *    merges correctly, and the reason no caller should ever call addScriptOptions()
 *    for charts itself.
 *
 * @since  2.0.0
 */
final class ChartHelper
{
    /**
     * @var string The script options bag every chart is published into.
     * @since  2.0.0
     */
    public const OPTIONS_KEY = 'com_alfa.charts';

    /**
     * Publish one chart and make sure the renderer is on the page.
     *
     * @param array $chart The DTO. Must carry `key` and `kind`; everything else is
     *                     the kind's own business.
     *
     * @return bool True when it was attached, false when this context cannot draw
     *              (no HTML document, assets locked, no key); the caller renders
     *              its figures either way and skips the container.
     *
     * @since  2.0.0
     */
    public static function attach(array $chart): bool
    {
        $key = (string) ($chart['key'] ?? '');

        if ($key === '' || ($chart['kind'] ?? '') === '') {
            return false;
        }

        try {
            $document = Factory::getApplication()->getDocument();

            if (!$document instanceof HtmlDocument) {
                return false;
            }

            $document->getWebAssetManager()
                ->useScript('com_alfa.charts');

            self::attachStyles();

            // Keyed, never appended; see the merge note in the class docblock.
            $document->addScriptOptions(self::OPTIONS_KEY, [$key => $chart]);
        } catch (Throwable) {
            // Locked manager (we came late), no document, no application: all the same
            // answer: draw no container and let the layout speak for itself.
            return false;
        }

        return true;
    }

    /**
     * The chart stylesheet on its own, with no chart to draw.
     *
     * A chart card is styled by `com_alfa.charts` whether or not it ends up drawing a
     * frame: its figures, its lede and its table all live in that file. Registering
     * the sheet inside {@see self::attach()} alone tied the card's whole appearance to
     * having DATA: a shop with no orders yet suppresses the chart, nothing asked for
     * the stylesheet, and the dashboard's takings card came out as raw unstyled markup
     * with full-width arrow glyphs. So the layout says it needs the sheet, and the
     * chart says it needs the sheet AND the renderer.
     *
     *
     * @since  2.0.0
     */
    public static function attachStyles(): void
    {
        try {
            $document = Factory::getApplication()->getDocument();

            if ($document instanceof HtmlDocument) {
                $document->getWebAssetManager()->useStyle('com_alfa.charts');
            }
        } catch (Throwable) {
            // Same answer as attach(): a context that cannot register assets renders
            // what it can and says nothing about it.
        }
    }

    /**
     * Attach several charts at once.
     *
     * @param array[] $charts DTOs.
     *
     * @return string[] The keys that were actually attached, so a layout can decide
     *                  per chart whether to draw a container.
     *
     * @since  2.0.0
     */
    public static function attachAll(array $charts): array
    {
        $attached = [];

        foreach ($charts as $chart) {
            if (\is_array($chart) && self::attach($chart)) {
                $attached[] = (string) $chart['key'];
            }
        }

        return $attached;
    }

    /**
     * How the renderer should print a money value in a tooltip.
     *
     * The number itself is formatted by the browser's own locale grouping, but the
     * SHAPE around it (symbol, placement, how many decimals the currency actually
     * has) is the store's, so it is resolved here from the same Currency every
     * other figure goes through. A currency with no decimals (JPY) prints none;
     * one with three (BHD) prints three. Never guessed, never hard-coded to two.
     *
     * @param Currency|null $currency The currency, or null for the store's default.
     *
     * @return array{valueFormat: string, valueDecimals: int} DTO fragment: merge into
     *                                                        the chart that shows money.
     *
     * @since  2.0.0
     */
    public static function moneyFormat(?Currency $currency = null): array
    {
        try {
            $currency ??= Currency::getDefault();

            // The currency's OWN pattern, with its own token swapped for the renderer's
            // placeholder, not a formatted sample parsed back apart. `{number}` and
            // `{symbol}` are the only two tokens Currency::format() knows.
            $pattern = strtr($currency->getFormatPattern(), [
                '{number}' => '%s',
                '{symbol}' => $currency->getSymbol(),
            ]);

            return [
                'valueFormat' => str_contains($pattern, '%s') ? $pattern : '%s',
                'valueDecimals' => $currency->getDecimalPlaces(),
            ];
        } catch (Throwable) {
            // No default currency configured yet: a bare number is honest.
            return ['valueFormat' => '%s', 'valueDecimals' => 2];
        }
    }
}
