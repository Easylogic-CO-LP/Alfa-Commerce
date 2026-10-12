/**
 * @package    Alfa Commerce
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Draws every chart in the Alfa admin from LIBRARY-AGNOSTIC DTOs.
 *
 * THE DTO IS THE CONTRACT, ECHARTS IS AN IMPLEMENTATION DETAIL. Whoever owns the data
 * emits a plain `{key, kind, ...}` structure with every string already translated
 * server-side; this file is the ONLY place in the codebase that knows ECharts exists,
 * so the charting library is replaceable by rewriting exactly this file. ECharts is
 * vendored in com_alfa's own media (Apache-2.0); nothing loads from a CDN, ever.
 *
 * One container per chart: `<div data-alfa-chart="<key>">`. A DTO without a container,
 * or a container without a DTO, is silently skipped: charts are additive presentation
 * and must never break the screen that hosts them.
 *
 * Options arrive KEYED BY CHART KEY, not as a list. `Document::addScriptOptions()`
 * merges with `array_replace_recursive`, which on a numeric-indexed list replaces by
 * POSITION: two contributors each pushing `[$dto]` would silently clobber one another
 * at index 0. A string key is the only shape that merges correctly, which is why
 * ChartHelper::attach() is the single door in.
 */
(function () {
    'use strict';

    /** @var {Object<string, Object>} Live instances, so a re-theme can dispose them. */
    var instances = {};

    /**
     * The admin's own colours, read once per render.
     *
     * ECharts paints its axes and labels in a fixed near-black, which disappears on
     * Atum's dark theme. The template already publishes flipping tokens, so the chart
     * borrows them instead of carrying a second palette that could disagree. Only
     * tokens that actually flip are read: `--white` and the `--gray-*` ramp do not.
     */
    function palette() {
        var css = getComputedStyle(document.documentElement);
        var read = function (name, fallback) {
            var value = css.getPropertyValue(name);

            return value && value.trim() ? value.trim() : fallback;
        };

        return {
            text: read('--body-color', '#212529'),
            muted: read('--secondary-color', '#6c757d'),
            line: read('--border-color', '#dee2e6'),
            card: read('--card-bg', '#ffffff'),
            accent: read('--link-color', '#2a69b8'),
            success: read('--success-text-emphasis', '#286c3c'),
            warn: read('--warning-text-emphasis', '#b4762a'),
            danger: read('--danger-text-emphasis', '#a52834')
        };
    }

    /**
     * A merchant asked their operating system not to animate; ECharts animates on
     * every setOption by default. One read, honoured by every kind below.
     */
    function still() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /** Every declared chart, keyed by its own key. */
    function options() {
        var declared = (window.Joomla && Joomla.getOptions)
            ? Joomla.getOptions('com_alfa.charts')
            : null;

        return declared && typeof declared === 'object' ? declared : {};
    }

    /**
     * A soft vertical wash under a line: the fill says "volume" without a second
     * axis, and fading it out stops the area competing with the line itself.
     */
    function wash(colour) {
        if (!window.echarts || !echarts.graphic || !echarts.graphic.LinearGradient) {
            return { opacity: 0.12 };
        }

        return {
            opacity: 1,
            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                { offset: 0, color: fade(colour, 0.28) },
                { offset: 1, color: fade(colour, 0.01) }
            ])
        };
    }

    /**
     * Alpha applied to whatever the token gave us. Atum publishes hex, but a theme
     * is free to publish rgb()/hsl(), so anything not parseable as hex falls back to
     * colour-mix() and, failing that, to the colour untouched.
     */
    function fade(colour, alpha) {
        // FOUR HEX LENGTHS, not two. Atum's dark tokens are EIGHT digits (`--border-color`
        // is `#ffffff1a`), and a regex that only knew #rgb/#rrggbb sent those down the
        // colour-mix() path, which zrender cannot parse: the heatmap ramp and the area
        // wash simply did not paint in dark mode.
        var hex = /^#([0-9a-f]{3,8})$/i.exec(colour.trim());

        if (!hex || [3, 4, 6, 8].indexOf(hex[1].length) === -1) {
            return 'color-mix(in srgb, ' + colour + ' ' + Math.round(alpha * 100) + '%, transparent)';
        }

        var body = hex[1];

        if (body.length < 6) {
            // #rgb and #rgba: every digit doubles.
            body = body.split('').map(function (c) { return c + c; }).join('');
        }

        // An existing alpha is not discarded, it is compounded: asking for 30% of a
        // token that is already 10% opaque must not hand back something 30% opaque.
        if (body.length === 8) {
            alpha *= parseInt(body.slice(6, 8), 16) / 255;
        }

        return 'rgba(' + parseInt(body.slice(0, 2), 16)
            + ',' + parseInt(body.slice(2, 4), 16)
            + ',' + parseInt(body.slice(4, 6), 16)
            + ',' + alpha.toFixed(3) + ')';
    }

    /** Shared axis furniture, so eight charts cannot drift apart. */
    function axes(colours, chart) {
        return {
            category: {
                type: 'category',
                data: chart.labels || [],
                boundaryGap: false,
                axisLine: { lineStyle: { color: colours.line } },
                axisTick: { show: false },
                axisLabel: { color: colours.muted, fontSize: 10, hideOverlap: true }
            },
            value: {
                type: 'value',
                minInterval: 1,
                splitLine: { lineStyle: { color: colours.line, opacity: 0.6 } },
                axisLabel: { color: colours.muted, fontSize: 10 }
            }
        };
    }

    /** Map one DTO to an ECharts option by its declared kind. */
    function toOption(chart) {
        var colours = palette();
        var axis = axes(colours, chart);
        var base = {
            animation: !still(),
            title: chart.title ? { text: chart.title, left: 'center', textStyle: { fontSize: 14, color: colours.text } } : undefined,
            tooltip: { trigger: 'axis' },
            grid: { left: 48, right: 24, top: chart.title ? 48 : 24, bottom: 32 }
        };

        if (chart.kind === 'funnel') {
            return {
                animation: base.animation,
                title: base.title,
                tooltip: { trigger: 'item' },
                // One accent, darkening down the funnel. ECharts' default palette gives
                // each step an unrelated hue, which reads as four categories rather than
                // as one quantity narrowing, the opposite of what a funnel says.
                color: (chart.steps || []).map(function (step, index, all) {
                    return fade(colours.accent, 0.35 + (0.6 * index) / Math.max(1, all.length - 1));
                }),
                series: [{
                    type: 'funnel',
                    top: chart.title ? 48 : 16,
                    bottom: chart.note ? 28 : 8,
                    sort: 'none',
                    minSize: '12%',
                    gap: 2,
                    label: { position: 'inside', formatter: '{b}: {c}', color: colours.text },
                    data: (chart.steps || []).map(function (step) {
                        return { name: step.label, value: step.value };
                    })
                }],
                graphic: chart.note ? [{
                    type: 'text',
                    left: 'center',
                    bottom: 4,
                    style: { text: chart.note, fontSize: 12, fill: colours.muted }
                }] : []
            };
        }

        if (chart.kind === 'lines') {
            if (chart.compact) {
                // The dashboard card: the card draws the title and the figures, so the
                // chart is only the shape of the days; nothing it repeats.
                return {
                    animation: base.animation,
                    color: [colours.accent, colours.warn, colours.muted, colours.line],
                    tooltip: { trigger: 'axis' },
                    legend: {
                        top: 0,
                        right: 0,
                        itemWidth: 10,
                        itemHeight: 10,
                        textStyle: { color: colours.muted, fontSize: 11 }
                    },
                    grid: { left: 34, right: 8, top: 28, bottom: 22 },
                    xAxis: axis.category,
                    yAxis: axis.value,
                    series: (chart.series || []).map(function (s, index) {
                        return {
                            name: s.label,
                            type: 'line',
                            smooth: 0.35,
                            symbol: 'none',
                            lineStyle: { width: index === 0 ? 2 : 1.4, type: index === 1 ? 'dashed' : 'solid' },
                            areaStyle: index === 0 ? { opacity: 0.12 } : undefined,
                            data: s.values
                        };
                    })
                };
            }

            return Object.assign({}, base, {
                legend: { bottom: 0, textStyle: { color: colours.muted } },
                grid: { left: 48, right: 24, top: chart.title ? 48 : 24, bottom: 56 },
                xAxis: Object.assign({}, axis.category, { axisLabel: { color: colours.muted } }),
                yAxis: axis.value,
                series: (chart.series || []).map(function (s) {
                    return { name: s.label, type: 'line', smooth: true, data: s.values };
                })
            });
        }

        if (chart.kind === 'area') {
            // The hero: money is the filled line on the left axis, counts are a thin
            // line on the right. A series declares `axis: 'right'` and `money: true`;
            // everything else is a plain count on the left.
            var moneyFirst = (chart.series || []).some(function (s) { return s.money; });

            return {
                animation: base.animation,
                color: [colours.accent, colours.success, colours.warn, colours.danger],
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'line', lineStyle: { color: colours.line } },
                    valueFormatter: undefined
                },
                legend: {
                    top: 0,
                    right: 0,
                    icon: 'roundRect',
                    itemWidth: 10,
                    itemHeight: 10,
                    textStyle: { color: colours.muted, fontSize: 11 }
                },
                grid: { left: 8, right: 8, top: 30, bottom: 4, containLabel: true },
                xAxis: axis.category,
                yAxis: [
                    Object.assign({}, axis.value, {
                        minInterval: moneyFirst ? null : 1,
                        axisLabel: { color: colours.muted, fontSize: 10, formatter: shorten }
                    }),
                    Object.assign({}, axis.value, {
                        splitLine: { show: false },
                        axisLabel: { color: colours.muted, fontSize: 10, formatter: shorten }
                    })
                ],
                series: (chart.series || []).map(function (s, index) {
                    var colour = [colours.accent, colours.success, colours.warn, colours.danger][index % 4];

                    return {
                        name: s.label,
                        type: 'line',
                        yAxisIndex: s.axis === 'right' ? 1 : 0,
                        smooth: 0.3,
                        symbol: 'circle',
                        symbolSize: 1,
                        showSymbol: false,
                        lineStyle: { width: s.axis === 'right' ? 1.4 : 2.2, type: s.axis === 'right' ? 'dashed' : 'solid' },
                        areaStyle: s.axis === 'right' ? undefined : wash(colour),
                        emphasis: { focus: 'series' },
                        data: s.values,
                        tooltip: s.money ? { valueFormatter: formatterFor(chart) } : undefined
                    };
                })
            };
        }

        if (chart.kind === 'bars') {
            // Horizontal bars: category labels are words: readable on the y-axis,
            // clipped on the x.
            // A bar's label is the whole point of a bar chart on a dashboard, so it gets
            // the same treatment as any other figure: money formatted the store's way,
            // long numbers shortened so 8.964.154,00 € does not push the bar off the card.
            var barLabel = chart.valueFormat
                ? function (params) { return money(chart, params.value, true); }
                : function (params) { return shorten(params.value) + (chart.unit || ''); };

            return Object.assign({}, base, {
                tooltip: {
                    trigger: 'axis',
                    valueFormatter: chart.valueFormat
                        ? function (value) { return money(chart, value, false); }
                        : function (value) { return value + (chart.unit || ''); }
                },
                color: [colours.accent, colours.muted],
                grid: { left: 8, right: 56, top: chart.title ? 48 : 16, bottom: 8, containLabel: true },
                xAxis: Object.assign({}, axis.value, {
                    boundaryGap: false,
                    axisLabel: { color: colours.muted, fontSize: 10, formatter: shorten }
                }),
                yAxis: {
                    type: 'category',
                    data: chart.labels || [],
                    inverse: true,
                    axisLine: { lineStyle: { color: colours.line } },
                    axisTick: { show: false },
                    axisLabel: { color: colours.muted, fontSize: 11, width: 150, overflow: 'truncate' }
                },
                series: (chart.series || []).map(function (s) {
                    return {
                        name: s.label,
                        type: 'bar',
                        barMaxWidth: 16,
                        itemStyle: { borderRadius: [0, 3, 3, 0] },
                        data: s.values,
                        label: {
                            show: true,
                            position: 'right',
                            color: colours.muted,
                            fontSize: 11,
                            formatter: barLabel
                        }
                    };
                })
            });
        }

        if (chart.kind === 'donut') {
            // Slices carry their OWN colour when the DTO gave one: order statuses are
            // the merchant's own palette, and repainting them here would make the same
            // status two different colours on two screens.
            var fallbacks = [colours.accent, colours.success, colours.warn, colours.danger, colours.muted];

            return {
                animation: base.animation,
                title: base.title,
                tooltip: {
                    trigger: 'item',
                    valueFormatter: formatterFor(chart)
                },
                // NO LEGEND. Every donut on this dashboard sits beside the table it
                // summarises, and that table already names every slice against its own
                // colour; a legend would repeat it in less space and crowd the ring.
                // A donut used without a table should carry `legend: true` in the DTO.
                legend: chart.legend ? {
                    orient: 'vertical',
                    right: 0,
                    top: 'middle',
                    icon: 'circle',
                    itemWidth: 9,
                    itemHeight: 9,
                    textStyle: { color: colours.muted, fontSize: 11 }
                } : { show: false },
                series: [{
                    type: 'pie',
                    radius: ['58%', '82%'],
                    center: chart.legend ? ['32%', '50%'] : ['50%', '50%'],
                    avoidLabelOverlap: true,
                    itemStyle: { borderColor: colours.card, borderWidth: 2 },
                    label: { show: false },
                    emphasis: { scale: true, scaleSize: 4 },
                    data: (chart.slices || []).map(function (slice, index) {
                        return {
                            name: slice.label,
                            value: slice.value,
                            itemStyle: { color: slice.color || fallbacks[index % fallbacks.length] }
                        };
                    })
                }]
            };
        }

        if (chart.kind === 'heatmap') {
            // Cells arrive as [xIndex, yIndex, value]; the ramp is one accent at rising
            // opacity rather than a rainbow, so "busier" reads as "darker" and nothing
            // has to be looked up in a key.
            var peak = 0;

            (chart.cells || []).forEach(function (cell) {
                peak = Math.max(peak, cell[2] || 0);
            });

            return {
                animation: base.animation,
                title: base.title,
                tooltip: {
                    position: 'top',
                    formatter: function (params) {
                        return (chart.yLabels || [])[params.value[1]]
                            + ' ' + (chart.xLabels || [])[params.value[0]]
                            + ': ' + params.value[2];
                    }
                },
                grid: { left: 8, right: 8, top: chart.title ? 44 : 8, bottom: 4, containLabel: true },
                xAxis: {
                    type: 'category',
                    data: chart.xLabels || [],
                    splitArea: { show: false },
                    axisLine: { show: false },
                    axisTick: { show: false },
                    axisLabel: { color: colours.muted, fontSize: 10, interval: chart.xInterval || 1 }
                },
                yAxis: {
                    type: 'category',
                    data: chart.yLabels || [],
                    // A category axis puts index 0 at the BOTTOM, which drew the week
                    // upside down (Monday under Sunday). A week is read downwards.
                    inverse: true,
                    splitArea: { show: false },
                    axisLine: { show: false },
                    axisTick: { show: false },
                    axisLabel: { color: colours.muted, fontSize: 10 }
                },
                visualMap: {
                    show: false,
                    min: 0,
                    max: peak || 1,
                    inRange: { color: [fade(colours.accent, 0.06), fade(colours.accent, 0.95)] }
                },
                series: [{
                    type: 'heatmap',
                    data: chart.cells || [],
                    itemStyle: { borderRadius: 2, borderColor: colours.card, borderWidth: 1 },
                    emphasis: { itemStyle: { borderColor: colours.text, borderWidth: 1 } }
                }]
            };
        }

        return null;
    }

    /**
     * Money is formatted SERVER-SIDE and handed over as a pattern with `%s` where the
     * number goes, so the currency's own symbol, separators and exponent survive into
     * a tooltip without this file owning a single formatting rule.
     */
    function formatterFor(chart) {
        if (!chart.valueFormat) {
            return undefined;
        }

        return function (value) {
            return money(chart, value, false);
        };
    }

    /**
     * One amount, written the store's way.
     *
     * `brief` drops the decimals and shortens the magnitude: what a bar label beside a
     * 40px gutter can carry. A tooltip has room for the real number and gets it.
     */
    function money(chart, value, brief) {
        var pattern = chart.valueFormat || '%s';
        var number  = Number(value);

        if (brief && Math.abs(number) >= 1000) {
            return pattern.replace('%s', shorten(number));
        }

        return pattern.replace('%s', number.toLocaleString(undefined, {
            minimumFractionDigits: brief ? 0 : (chart.valueDecimals || 0),
            maximumFractionDigits: brief ? 0 : (chart.valueDecimals || 0)
        }));
    }

    /** Axis labels: 12.4k beats 12400 when the gutter is 40px wide. */
    function shorten(value) {
        var abs = Math.abs(value);

        if (abs >= 1000000) {
            return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }

        if (abs >= 1000) {
            return (value / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        }

        return String(value);
    }

    /**
     * A card can be laid out after its chart was drawn: a flex row reflowing, a tab
     * opening, the window changing. ECharts only resizes when told, so each container
     * is observed rather than every chart listening to the window.
     */
    function observe(host, instance) {
        if (typeof ResizeObserver !== 'function') {
            window.addEventListener('resize', function () {
                instance.resize();
            });

            return;
        }

        new ResizeObserver(function () {
            instance.resize();
        }).observe(host);
    }

    /**
     * Paint one chart into its container, replacing whatever was there.
     */
    function draw(key, chart, host) {
        var option = toOption(chart);

        if (!option) {
            return;                                     // a kind this renderer does not know
        }

        if (instances[key]) {
            instances[key].dispose();
        }

        var instance = echarts.init(host);

        instance.setOption(option);
        instances[key] = instance;
        observe(host, instance);
    }

    /**
     * A container inside a closed tab has no width, and a chart drawn into it would
     * be 0x0 for good. Wait for the tab to open (the first resize that gives the
     * container a width) and draw then.
     */
    function whenVisible(host, callback) {
        if (typeof ResizeObserver !== 'function') {
            document.addEventListener('joomla.tab.shown', function shown() {
                if (host.clientWidth > 0) {
                    document.removeEventListener('joomla.tab.shown', shown);
                    callback();
                }
            });

            return;
        }

        var watcher = new ResizeObserver(function () {
            if (host.clientWidth === 0) {
                return;
            }

            watcher.disconnect();
            callback();
        });

        watcher.observe(host);
    }

    function init() {
        if (typeof echarts === 'undefined') {
            return;
        }

        var declared = options();

        Object.keys(declared).forEach(function (key) {
            var chart = declared[key];
            var host = document.querySelector('[data-alfa-chart="' + (chart.key || key) + '"]');

            if (!host) {
                return;                                 // no container: nothing to draw into
            }

            if (host.clientWidth === 0) {
                whenVisible(host, function () {
                    draw(key, chart, host);
                });

                return;
            }

            draw(key, chart, host);
        });
    }

    /**
     * Atum swaps its tokens live when the colour scheme changes; a chart painted for
     * the previous palette would otherwise keep its old greys.
     */
    function repaint() {
        Object.keys(instances).forEach(function (key) {
            instances[key].dispose();
            delete instances[key];
        });

        init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('joomla:color-scheme-change', repaint);

    /**
     * A chart that arrives after the page (a panel fetched into a tab) is handed
     * over as `alfa:charts` with its DTOs; it is drawn like any declared at load,
     * waiting for its container to have a width if it has none yet.
     */
    document.addEventListener('alfa:charts', function (event) {
        var charts = (event.detail && event.detail.charts) || [];

        charts.forEach(function (chart) {
            if (!chart || !chart.key) {
                return;
            }

            var host = document.querySelector('[data-alfa-chart="' + chart.key + '"]');

            if (!host) {
                return;
            }

            if (host.clientWidth === 0) {
                whenVisible(host, function () {
                    draw(chart.key, chart, host);
                });

                return;
            }

            draw(chart.key, chart, host);
        });
    });

    // A card refreshed over ajax brings its own container and its own DTO; the host
    // says so and the renderer picks up whatever is now on the page.
    document.addEventListener('alfa:charts-changed', init);
}());
