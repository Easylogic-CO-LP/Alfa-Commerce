<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_alfa_filters
 *
 * @copyright   Copyright (C) 2025 Your Name. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Alfa\Module\AlfaFilters\Site\Dispatcher;

use Alfa\Module\AlfaFilters\Site\Helper\AlfaFiltersHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use stdClass;

defined('_JEXEC') or die;

/**
 * Dispatcher for mod_alfa_filters.
 *
 * Flow:
 *   dispatch()       -> guards, registers assets, calls parent (which calls getLayoutData + renders)
 *   getLayoutData()  -> returns only data for the layout template
 *
 * @since  1.0.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * Resolved once in the constructor so every method can use it directly.
     */
    protected WebAssetManager $wa;

    /**
     * @param stdClass $module The module instance.
     * @param CMSApplicationInterface $app The application.
     * @param Input $input The input.
     */
    public function __construct(stdClass $module, CMSApplicationInterface $app, Input $input)
    {
        parent::__construct($module, $app, $input);

        $this->wa = $this->app->getDocument()->getWebAssetManager();
    }

    // Dispatch

    /**
     * Guards, registers all front-end assets, then delegates rendering to the parent.
     *
     * Asset registration lives here (NOT in getLayoutData) because assets are
     * a side-effect, not data. getLayoutData should only return values the template
     * needs to render.
     */
    public function dispatch(): void
    {
        // Make com_alfa language strings available even on non-component pages
        // so filter labels resolve correctly wherever the module is positioned.
        $this->app->getLanguage()->load('com_alfa', JPATH_SITE);

        $params = new Registry($this->module->params);
        $option = $this->app->getInput()->get('option');
        $view = $this->app->getInput()->get('view');
        $moduleId = (int) $this->module->id;

        // Skip rendering on item detail pages unless the param explicitly opts in.
        if ($option === 'com_alfa' && $view === 'item' && !(bool) $params->get('item_page_loading', 0)) {
            return;
        }

        $isDynamic = ($option === 'com_alfa' && $view === 'items') && (bool) $params->get('dynamic_filtering', 0);

        // Add Css and Javascript
        $this->registerCss($params, $moduleId);
        $this->registerJs($params, $moduleId, $isDynamic);
        $this->wa->getRegistry()->addExtensionRegistryFile('mod_alfa_filters');
        // The palette is com_alfa's and this module renders on any page, so the owner's
        // registry is added unconditionally; the ShowOn branch below registers it too,
        // but only when a facet carries a rule, which is not when the panel needs colours.
        $this->wa->getRegistry()->addExtensionRegistryFile('com_alfa');
        $this->wa->useStyle('com_alfa.tokens');
        $this->wa->usePreset('mod_alfa_filters_preset');

        // ShowOn engine: only when a facet actually carries a rule. The server has
        // already pruned the state, so this is pure progressive enhancement: it re-runs
        // the SAME rule in the browser so a facet appears/disappears as the shopper
        // ticks, instead of waiting for the next AJAX round-trip. The facet lookup is
        // memoised, so asking here costs nothing when getLayoutData asks again.
        if ($this->hasFacetShowonRules($params)) {
            // The engine is COM_ALFA's asset, and this module renders on any page (not
            // only inside com_alfa), so its registry cannot be assumed present. Without
            // this line useScript() throws UnknownAssetException the moment a rendered
            // facet carries a rule on a non-com_alfa page, taking the whole page with it.
            // Same reason mod_alfa_cart registers it before using com_alfa's assets.
            $this->wa->getRegistry()->addExtensionRegistryFile('com_alfa');
            $this->wa->useScript('com_alfa.showon.engine');
        }

        // Choices.js: only when a facet renders as a dropdown. This is Joomla's own
        // vendor copy, the one the catalogue's searchtools facet bar already uses, so
        // the asset manager loads it once however many callers ask. filters.js drives
        // it over the plain <select> itself: no wrapper element, no second copy.
        if ($this->hasSelectFacets($params)) {
            $this->wa->usePreset('choicesjs');
        }

        parent::dispatch();
    }

    /**
     * Whether any rendered item-field facet carries a ShowOn rule.
     *
     * @param Registry $params Module parameters.
     *
     *
     * @since  1.0.0
     */
    protected function hasFacetShowonRules(Registry $params): bool
    {
        foreach (AlfaFiltersHelper::getAvailableFilters($params)['fields'] ?? [] as $facet) {
            if (!empty($facet['showon'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any rendered item-field facet renders as a dropdown (Choices.js).
     *
     * @param Registry $params Module parameters.
     *
     *
     * @since  1.0.0
     */
    protected function hasSelectFacets(Registry $params): bool
    {
        foreach (AlfaFiltersHelper::getAvailableFilters($params)['fields'] ?? [] as $facet) {
            if (($facet['render_as'] ?? '') === 'select') {
                return true;
            }
        }

        return false;
    }

    // Layout data  (pure data, no side-effects)

    /**
     * Returns all variables the layout template needs.
     * No asset registration here; that belongs in dispatch().
     */
    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();
        $params = $data['params'];
        $moduleId = (int) $data['module']->id;

        $option = $this->app->getInput()->get('option');
        $view = $this->app->getInput()->get('view');

        $data['dynamic_filtering'] = ($option === 'com_alfa' && $view === 'items') && (bool) $params->get('dynamic_filtering', 0);
        $data['svg_close_button'] = $this->sanitizeSvg((string) $params->get('imageInline', ''));
        $data['svg_loading_icon'] = $this->sanitizeSvg((string) $params->get('loadingImageInline', ''));
        $data['alfa_filters'] = AlfaFiltersHelper::getAvailableFilters($params);
        $data['alfa_active_filters'] = AlfaFiltersHelper::getActiveFilters();
        $data['getMinMax'] = AlfaFiltersHelper::getMinMaxPrice();
        $data['form_fields'] = AlfaFiltersHelper::getFormFields($moduleId);
        $data['sectionOrder'] = AlfaFiltersHelper::getSectionOrder($params, $data['alfa_filters']);

        return $data;
    }

    // Asset registration

    /**
     * Injects instance-scoped CSS custom properties and, when the fixed button
     * is disabled, the responsive off-canvas override block.
     *
     * Using .mod-alfa-filters-{id} scopes every rule to this instance so
     * multiple modules on the same page don't bleed into each other.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance ID.
     */
    protected function registerCss(Registry $params, int $moduleId): void
    {
        $instanceClass = '.mod-alfa-filters-' . $moduleId;

        $checkboxColor = $params->get('checkbox_color', '#007bff');
        $checkboxColorBefore = $params->get('checkbox_color_before', '#0056b3');
        $checkboxColorDisabled = $params->get('checkbox_color_disabled', '#cccccc');
        $submitColor = $params->get('submit_color', '#007bff');
        $submitColorHover = $params->get('submit_color_hover', '#0056b3');
        $filterBtnColor = $params->get('filter_btn_color', '#0056b3');
        $subcategoryToggle = $params->get('subcategory_toggle_color', '#0056b3');
        $submit_color_text = $params->get('submit_color_text', '#FFFFFF');
        // Empty means "follow the site". Defaulting these to black is what kept the
        // panel's writing black on a dark page: the value was emitted into the
        // instance's own custom properties, so it beat every stylesheet under it.
        $filter_text_color = $params->get('filter_text_color', '') ?: 'var(--alfa-surface-color, inherit)';
        $subcategory_hover_toggle_color = $params->get('subcategory_hover_toggle_color', '#0056b3');
        $filter_text_color_hover = $params->get('filter_text_color_hover', '') ?: 'var(--alfa-filters-accent)';
        $slider_color = $params->get('slider_color', '#1a1a1a');

        // Everything the merchant can set on this instance. Each is emitted ONLY when
        // it holds a value: a declaration written unconditionally beats the module's
        // own light/dark values and the storefront palette alike, which is how an
        // unset setting would silently become a hardcoded one.
        $len = static fn (string $v): string => trim($v) === '' ? '' : max(0, min(80, (int) $v)) . 'px';

        $styleCss = '';

        foreach ([
            '--alfa-filters-panel-padding' => $len((string) $params->get('panel_padding', '')),
            // The inline half of the same setting, so the desktop actions bar (a sibling
            // of the form) can take the inset the form takes and the two resets align.
            '--alfa-filters-panel-padding-inline' => $len((string) $params->get('panel_padding', '')),
            '--alfa-filters-surface' => trim((string) $params->get('style_surface', '')),
            '--alfa-filters-text-secondary' => trim((string) $params->get('style_muted', '')),
            '--alfa-filters-border' => trim((string) $params->get('style_hairline', '')),
            '--alfa-filters-radius-base' => $len((string) $params->get('style_radius', '')),
        ] as $token => $value) {
            if ($value !== '') {
                $styleCss .= $token . ':' . $value . ';';
            }
        }

        // The dark twin of every colour above. Emitted as its own rule under the same
        // two selectors the module's own dark values use, so a merchant's dark choice
        // replaces the default exactly where that default sat, and a light choice
        // stops leaking into dark, which one value per colour could not avoid.
        $darkCss = '';

        foreach ([
            '--mod-alfa-filters-checkbox-color' => 'checkbox_color',
            '--mod-alfa-filters-checkbox-color-before' => 'checkbox_color_before',
            '--mod-alfa-filters-checkbox-color-disabled' => 'checkbox_color_disabled',
            '--mod-alfa-filters-submit-color' => 'submit_color',
            '--mod-alfa-filters-submit-color-hover' => 'submit_color_hover',
            '--mod-alfa-filters-submit-color-text' => 'submit_color_text',
            '--mod-alfa-filters-toggler-color' => 'filter_btn_color',
            '--mod-alfa-filters-subcategory-toggle-color' => 'subcategory_toggle_color',
            '--alfa-filters-toggle-color-hover' => 'subcategory_hover_toggle_color',
            '--alfa-filters-text' => 'filter_text_color',
            '--mod-alfa-filters-filter-text-color-hover' => 'filter_text_color_hover',
            '--alfa-filters-track-active-color' => 'slider_color',
            '--alfa-filters-surface' => 'style_surface',
            '--alfa-filters-text-secondary' => 'style_muted',
            '--alfa-filters-border' => 'style_hairline',
        ] as $token => $param) {
            $value = trim((string) $params->get($param . '_dark', ''));

            if ($value !== '') {
                $darkCss .= $token . ':' . $value . ';';
            }
        }

        if ($darkCss !== '') {
            $this->wa->addInlineStyle(
                ':is(:root[data-theme="dark"],:root[data-theme-default="dark"]:not([data-theme])) '
                . $instanceClass . '{' . $darkCss . '}'
                . '@media (prefers-color-scheme:dark){'
                . ':is(:root[data-theme="auto"],:root[data-theme-default="auto"]:not([data-theme]),'
                . ':root:not([data-theme]):not([data-theme-default])) '
                . $instanceClass . '{' . $darkCss . '}}',
            );
        }

        $offsetVertical = (float) $params->get('offset_vertical', 10.0);
        $offsetVerticalUnit = $params->get('offset_vertical_unit', 'px');
        $offsetHorizontal = $params->get('offset_horizontal', 10.0);
        $offsetHorizontalUnit = $params->get('offset_horizontal_unit', 'px');

        $offsetV = $offsetVertical . $offsetVerticalUnit;
        $offsetH = $offsetHorizontal . $offsetHorizontalUnit;

        $loadingAnimation = match ((string) $params->get('loadingAnimation', 'pulsate')) {
            'spinner' => 'alfa-filters-spinner 0.8s linear infinite',
            'none' => '',
            default => 'alfa-filters-pulsate 0.7s ease-in-out infinite',
        };

        // CSS custom properties scoped to this module instance
        $this->wa->addInlineStyle(<<<CSS
                {$instanceClass} {
                    --mod-alfa-filters-checkbox-color:           {$checkboxColor};
                    --mod-alfa-filters-checkbox-color-before:    {$checkboxColorBefore};
                    --mod-alfa-filters-checkbox-color-disabled:  {$checkboxColorDisabled};
                    --mod-alfa-filters-submit-color:             {$submitColor};
                    --mod-alfa-filters-submit-color-hover:       {$submitColorHover};
                    --mod-alfa-filters-form-height:              calc(100% - 3em);
                    --mod-alfa-filters-vertical-offset:          {$offsetV};
                    --mod-alfa-filters-horizontal-offset:        {$offsetH};
                    --mod-alfa-filters-toggler-width:            clamp(180px, 10vw, 220px);
                    --mod-alfa-filters-toggler-height:           clamp(45px,  5vw,  55px);
                    --mod-alfa-filters-toggler-color:            {$filterBtnColor};
                    --mod-alfa-filters-loading-animation:        {$loadingAnimation};
                    --mod-alfa-filters-subcategory-toggle-color: {$subcategoryToggle};
                    --alfa-filters-thumb-size:                             22px;
                    --mod-alfa-filters-submit-color-text:        {$submit_color_text};
                    --alfa-filters-text:                                   {$filter_text_color};
                    --alfa-filters-toggle-color-hover:                     {$subcategory_hover_toggle_color};
                    --mod-alfa-filters-filter-text-color-hover:  {$filter_text_color_hover};
                  	--alfa-filters-track-active-color: {$slider_color};
                    {$styleCss}
                }
            CSS);

        // On desktop, collapse the off-canvas panel into a plain sidebar.
        // Only needed when the fixed floating button is disabled.
        $fixedBtn = (int) $params->get('fixed_btn', 0);
        $responsiveChange = (int) $params->get('responsiveChange', 800);

        if (!$fixedBtn) {
            $this->wa->addInlineStyle(<<<CSS
                    @media (min-width: {$responsiveChange}px) {

                        /* Remove overlay backdrop */
                        {$instanceClass} .alfa-filters-offcanvas-wrapper.alfa-filters-offcanvas {
                            position: unset;
                            background: unset;
                        }

                        /* Hide the mobile header */
                        {$instanceClass} .alfa-filters-offcanvas .alfa-filters-header {
                            display: none;
                        }

                        /* ...and give desktop its own reset in the slot the header leaves.
                           THE FORM'S OWN INLINE INSET, from the same setting: the bar is a
                           SIBLING of the form, not a child, so the panel padding insets the
                           sections and not the bar; without this, Reset All hangs outside
                           the per-section reset links it is supposed to line up with.
                           Inline only. The bar is 0px tall until the button inside it is
                           revealed, and block padding would open a gap above the first
                           section. */
                        {$instanceClass} .alfa-filters-offcanvas .alfa-filters-actions {
                            display: flex;
                            justify-content: flex-end;
                            padding-inline: var(--alfa-filters-panel-padding-inline);
                        }

                        /* Hide the open/close toggle */
                        {$instanceClass} .alfa-filters-offcanvas-toggler {
                            display: none;
                        }

                        /* Reset all slide-in transforms so the panel renders inline */
                        {$instanceClass} .alfa-filters-offcanvas.fromLeft  .alfa-filters-wrapper-inner,
                        {$instanceClass} .alfa-filters-offcanvas.zoomIn    .alfa-filters-wrapper-inner,
                        {$instanceClass} .alfa-filters-offcanvas.fromRight .alfa-filters-wrapper-inner,
                        {$instanceClass} .alfa-filters-offcanvas.fromTop   .alfa-filters-wrapper-inner {
                            width:       100%;
                            height:      100%;
                            background:  var(--alfa-filters-surface);
                            transform:   none;
                            will-change: unset;
                            contain:     unset;
                            transition:  width 0.25s ease;
                            align-self:  unset;
                            margin:      0;
                            z-index:     unset;
                        }

                        /* Clear the visible-state transform override */
                        {$instanceClass} .alfa-filters-offcanvas.alfa-filters-offcanvas--visible-inner.fromLeft  .alfa-filters-wrapper-inner,
                        {$instanceClass} .alfa-filters-offcanvas.alfa-filters-offcanvas--visible-inner.zoomIn    .alfa-filters-wrapper-inner,
                        {$instanceClass} .alfa-filters-offcanvas.alfa-filters-offcanvas--visible-inner.fromRight .alfa-filters-wrapper-inner,
                        {$instanceClass} .alfa-filters-offcanvas.alfa-filters-offcanvas--visible-inner.fromTop   .alfa-filters-wrapper-inner {
                            transform: unset;
                        }

                        /* Let the module fill its column naturally */
                        {$instanceClass} .mod-alfa-filters {
                            height: 100%;
                        }

                        /* Remove the overlay pseudo-element */
                        {$instanceClass} .alfa-filters-offcanvas::after {
                            display: none !important;
                        }
                    }
                CSS);
        }
    }

    /**
     * Injects the per-instance JS configuration object consumed by mod_alfa_filters.js.
     *
     * The global map lets multiple module instances coexist on the same page,
     * each keyed by their numeric ID.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance ID.
     * @param bool $dynamic Whether AJAX filtering is active.
     */
    protected function registerJs(Registry $params, int $moduleId, bool $dynamic): void
    {
        $responsiveChange = (int) $params->get('responsiveChange', 800);
        $fixedBtn = (int) $params->get('fixed_btn', 0);

        $this->wa->addInlineScript(
            "window.alfaFiltersModules = window.alfaFiltersModules || {};\n" .
            "window.alfaFiltersModules[{$moduleId}] = {\n" .
            '    dynamicFiltering: ' . ($dynamic ? 'true' : 'false') . ",\n" .
            "    responsiveChange: {$responsiveChange},\n" .
            "    fixedPos:         {$fixedBtn}\n" .
            '};',
        );
    }

    // Helpers

    /**
     * Sanitizes an SVG string for safe inline output.
     *
     * Uses enshrined/svg-sanitizer when available (shipped with Joomla 4+),
     * which strips any element or attribute not on its allow-list.
     * Falls back to a manual blocklist of common XSS vectors if the library
     * is not present.
     *
     * @param string $svg Raw SVG string from the module parameter.
     *
     * @return string Sanitized SVG string, or empty string if invalid or dangerous.
     */
    protected function sanitizeSvg(string $svg): string
    {
        $svg = trim($svg);

        if (empty($svg) || strpos($svg, '<svg') !== 0) {
            return '';
        }

        // Use enshrined/svg-sanitizer if available (shipped with Joomla 4+)
        if (class_exists(\enshrined\svgSanitize\Sanitizer::class)) {
            $sanitizer = new \enshrined\svgSanitize\Sanitizer();
            $clean = $sanitizer->sanitize($svg);

            if ($clean === false) {
                return '';
            }

            return $clean;
        }

        // Fallback: manual blocklist for common SVG XSS vectors
        if (
            stripos($svg, '<script') !== false ||
            stripos($svg, 'onload=') !== false ||
            stripos($svg, 'onclick=') !== false ||
            stripos($svg, 'onerror=') !== false ||
            stripos($svg, 'onmouseover=') !== false ||
            stripos($svg, '<foreignObject') !== false ||
            stripos($svg, 'javascript:') !== false ||
            stripos($svg, 'data:text/html') !== false
        ) {
            return '';
        }

        return $svg;
    }
}
