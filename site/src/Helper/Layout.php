<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\PluginHelper;

defined('_JEXEC') or die;

/**
 * Layout: the ONE rendering entry point for the site component.
 *
 * Everything the frontend renders goes through Layout::render(): a plain
 * component layout (price, stock_info, add_to_cart, filter_form...) when no
 * plugin owner is given, or a plugin's own layout (payments, shipments,
 * item-fields...) when a plugin group + element are passed. Same call, same
 * override story, everywhere:
 *
 *   Layout::render('item.stock_info', ['item' => $item]);                  // component
 *   Layout::render($layout, $data, $pluginType, $pluginName);         // plugin
 *
 * The view events carry the four pieces in sequence, so a call site stays a
 * one-liner: render($e->getLayout(), $e->getLayoutData(),
 * $e->getLayoutPluginType(), $e->getLayoutPluginName()).
 *
 * @since  1.0.0
 */
class Layout
{
    /**
     * Render a layout to a string. With an empty plugin group/element it is a
     * plain component layout (components/com_alfa/layouts, overridable per template
     * under html/layouts/com_alfa); with both set it is the named plugin's own
     * layout (template-override aware via pluginLayout()).
     *
     * @param string $layout The layout name (without .php).
     * @param array|object $data The display data passed to the layout.
     * @param string $pluginType The plugin group (e.g. alfa-item-fields); empty = component layout.
     * @param string $pluginName The plugin element (e.g. configurator3d); empty = component layout.
     *
     * @return string The rendered output.
     *
     * @since  1.0.0
     */
    public static function render(
        string $layout,
        array|object $data = [],
        string $pluginType = '',
        string $pluginName = '',
    ): string {
        if ($pluginType !== '' && $pluginName !== '') {
            return self::pluginLayout($pluginType, $pluginName, $layout)->render($data);
        }

        // Resolve by component, not by base path: a base path outranks every template
        // override, whereas the component option searches html/layouts/com_alfa of the
        // active template (and its parent) before components/com_alfa/layouts.
        return (new FileLayout($layout, null, ['component' => 'com_alfa', 'client' => 'site']))->render($data);
    }

    /**
     * The storefront searchtools bar of a list view (search input, list controls and
     * the Filter Options section), as core's `joomla.searchtools.default`, resolved by
     * component so the storefront's own sublayouts win (labels a shopper can read, the
     * section's initial state) while everything else falls back to core.
     *
     * ONE entry point, on purpose: the component's list/filter_form layout and any
     * engine that claims the search input through onAlfaSearchInputLayout both render
     * the bar through here, so the resolution and the options cannot drift between the
     * two paths; an engine rendering core's layout directly would silently lose every
     * storefront decision below.
     *
     * @param object $view The list HtmlView; exposes $filterForm and $activeFilters and,
     *                     where the merchant decides it, $filtersCollapsed.
     *
     * @return string The rendered bar.
     *
     * @since  1.0.0
     */
    public static function searchtools(object $view): string
    {
        $data = ['view' => $view];

        // Whether the Filter Options section opens by itself while the listing is
        // filtered is the VIEW's decision (collapse_items_filters_when_filtered on the
        // items listing). Passed only when it forces the section closed: core's option
        // can do nothing else, and a view that never decides must keep core behaviour
        // (open while any filter is active) rather than receive a `false` that would
        // override the computed value in core's option merge.
        if (!empty($view->filtersCollapsed)) {
            $data['options'] = ['filtersHidden' => true];
        }

        return (new FileLayout('joomla.searchtools.default', null, ['component' => 'com_alfa', 'client' => 'site']))->render($data);
    }

    /**
     * Resolve a layout shipped by a plugin, falling back to the component default (or an empty layout)
     * when arguments are missing or the plugin layout file does not exist.
     *
     * @param string $pluginType The plugin group/type (e.g. alfa-payments).
     * @param string $pluginName The plugin element name.
     * @param string $fileName The layout file name (without extension).
     *
     * @return FileLayout The resolved file layout.
     *
     * @since  1.0.0
     */
    public static function pluginLayout($pluginType, $pluginName, $fileName): FileLayout
    {
        if (empty($pluginType) || empty($pluginName) || empty($fileName)) {
            return self::getEmptyLayout();
        }

        $path = dirname(PluginHelper::getLayoutPath($pluginType, $pluginName, $fileName));

        if (file_exists($path . '/' . $fileName . '.php')) {
            return new FileLayout($fileName, $path);
        } else {
            if (JDEBUG) {
                Factory::getApplication()->enqueueMessage(
                    "Plugin layout not found: $pluginType / $pluginName / $fileName.",
                    'warning',
                );
            }

            return self::getDefaultPluginLayout($pluginType);
        }
    }

    /**
     * Build the component-level default layout for a plugin type, derived from the type suffix
     * (e.g. alfa-payments -> payments) and the current view's default filename.
     *
     * @param string $type The plugin group/type (e.g. alfa-payments).
     *
     * @return FileLayout The default component layout, or an empty layout when it cannot be resolved.
     *
     * @since  1.0.0
     */
    public static function getDefaultPluginLayout($type): FileLayout
    {
        $layoutType = explode('-', $type)[1] ?? '';
        $layoutFile = self::getLayoutDefaultFilename();

        if (!empty($layoutType) && !empty($layoutFile)) {
            $layout = new FileLayout($layoutType . '.' . $layoutFile, JPATH_SITE . '/components/com_alfa/layouts');
        } else {
            $layout = self::getEmptyLayout();
        }

        return $layout;
    }

    /**
     * Determine the default layout filename for the current request view (currently only the cart view).
     *
     * @return string The default layout filename, or an empty string when none applies.
     *
     * @since  1.0.0
     */
    public static function getLayoutDefaultFilename(): string
    {
        $app = Factory::getApplication();
        $input = $app->input;
        $view = $input->get('view', $input->get('controller', ''));

        $layoutFile = '';

        if ($view == 'cart') {
            $layoutFile = 'default_cart_view';
        }

        return $layoutFile;
    }

    /**
     * Build a no-op layout that renders to an empty string (no include paths, non-existent layout id).
     *
     * @return FileLayout A dummy layout whose render() output is ''.
     *
     * @since  1.0.0
     */
    public static function getEmptyLayout(): FileLayout
    {
        $empty_layout = new FileLayout('non.existing.layout'); // Or any layout ID that doesn't exist
        $empty_layout->clearIncludePaths(); // Optional: ensures no valid paths are searched

        return $empty_layout; // dummy empty layout which with render will output ''
    }
}
