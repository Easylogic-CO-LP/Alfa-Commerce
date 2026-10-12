<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Plugin Layout Helper
 *
 * Resolves layout files for plugin actions with a two-step fallback:
 *   1. Plugin's own tmpl/ directory (plugin override)
 *   2. Component defaults:
 *      - action_button -> layouts/actions/button.php (ONE shared file)
 *      - anything else -> empty (plugin's responsibility to provide it)
 *
 * Item-field surfaces an admin screen draws as the storefront drew them (the
 * order-line and cart-line popups) resolve through storefrontLayout() instead:
 * the default site template's overrides, never the administrator template's.
 *
 * Errors are ALWAYS shown as Joomla warning messages.
 * Step-by-step trace only appears when JDEBUG is enabled.
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

defined('_JEXEC') or die;

class PluginLayoutHelper
{
    /**
     * The default site template and the parent it inherits from, once per request.
     *
     * @var string[]|null
     *
     * @since  1.10.0
     */
    private static ?array $siteTemplates = null;

    /**
     * Resolve a plugin layout file.
     *
     * Resolution order:
     *   1. plugins/{pluginType}/{pluginName}/tmpl/{fileName}.php  (plugin override)
     *   2. Component default (only for action_button, shared at layouts/actions/button.php)
     *   3. Empty layout (renders nothing)
     *
     * @param string $pluginType Plugin group (e.g. 'alfa-payments', 'alfa-shipments')
     * @param string $pluginName Plugin element name (e.g. 'standard')
     * @param string $fileName Layout filename without .php (e.g. 'action_button')
     *
     * @return FileLayout Ready to call ->render($displayData)
     *
     * @since  1.0.0
     */
    public static function pluginLayout(string $pluginType, string $pluginName, string $fileName): FileLayout
    {
        // Validate inputs
        if (empty($pluginType) || empty($pluginName) || empty($fileName)) {
            self::warn(
                'pluginLayout() called with empty arguments:'
                . " type=\"{$pluginType}\", name=\"{$pluginName}\", file=\"{$fileName}\"."
                . ' Cannot resolve layout.',
            );
            return self::getEmptyLayout();
        }

        // Step 1: Try plugin's own tmpl/ directory
        $pluginPath = dirname(PluginHelper::getLayoutPath($pluginType, $pluginName, $fileName));
        $pluginFile = $pluginPath . '/' . $fileName . '.php';

        if (file_exists($pluginFile)) {
            self::trace("Layout \"{$fileName}\" -> Plugin override: {$pluginFile}");
            return new FileLayout($fileName, $pluginPath);
        }

        self::trace(
            "Layout \"{$fileName}\" not found in plugin ({$pluginType}/{$pluginName}/tmpl/)."
            . ' Checking component default...',
        );

        // Step 2: Component default
        return self::getDefaultLayout($fileName);
    }

    /**
     * Resolve an item-field surface layout for an admin screen that draws the
     * storefront's own field layouts (the order-line and cart-line popups).
     *
     * The administrator template is never searched. The file is found the way
     * the storefront's default page finds it, so a layout that exists only as a
     * site template override, which the field's layout picker offers, draws here
     * as it did for the customer:
     *   1. templates/<default site template>/html/plg_{pluginType}_{pluginName}/{fileName}.php
     *   2. the same folder of the template it inherits from
     *   3. plugins/{pluginType}/{pluginName}/tmpl/{fileName}.php
     *   4. Empty layout (renders nothing), with a warning
     *
     * @param string $pluginType Plugin group (e.g. 'alfa-item-fields')
     * @param string $pluginName Plugin element name (e.g. 'choice')
     * @param string $fileName Layout filename without .php (e.g. 'item-select')
     *
     * @return FileLayout Ready to call ->render($displayData)
     *
     * @since  1.10.0
     */
    public static function storefrontLayout(string $pluginType, string $pluginName, string $fileName): FileLayout
    {
        if (empty($pluginType) || empty($pluginName) || empty($fileName)) {
            self::warn(
                'storefrontLayout() called with empty arguments:'
                . " type=\"{$pluginType}\", name=\"{$pluginName}\", file=\"{$fileName}\"."
                . ' Cannot resolve layout.',
            );

            return self::getEmptyLayout();
        }

        $folders = [];

        foreach (self::siteTemplates() as $template) {
            $folders[] = JPATH_SITE . '/templates/' . $template . '/html/plg_' . $pluginType . '_' . $pluginName;
        }

        $folders[] = JPATH_PLUGINS . '/' . $pluginType . '/' . $pluginName . '/tmpl';

        foreach ($folders as $folder) {
            if (is_file($folder . '/' . $fileName . '.php')) {
                self::trace("Layout \"{$fileName}\" -> Storefront: {$folder}/{$fileName}.php");

                return new FileLayout($fileName, $folder);
            }
        }

        self::warn(
            "Layout \"{$fileName}\" of {$pluginType}/{$pluginName} exists neither in the default site template"
            . " (html/plg_{$pluginType}_{$pluginName}/) nor in the plugin's tmpl/."
            . ' Nothing will render for this field.',
        );

        return self::getEmptyLayout();
    }

    /**
     * The default site template style's template, then the parent it inherits
     * from: the folders the storefront's default page searches for overrides.
     *
     * @return string[] Template element names; empty when no default style is set.
     *
     * @since  1.10.0
     */
    private static function siteTemplates(): array
    {
        if (self::$siteTemplates !== null) {
            return self::$siteTemplates;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $clientId = 0;
        $home = '1';

        $query = $db->createQuery()
            ->select($db->quoteName(['template', 'parent']))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('client_id') . ' = :clientId')
            ->where($db->quoteName('home') . ' = :home')
            ->bind(':clientId', $clientId, ParameterType::INTEGER)
            ->bind(':home', $home, ParameterType::STRING);

        $style = $db->setQuery($query, 0, 1)->loadObject();

        return self::$siteTemplates = $style === null
            ? []
            : array_values(array_filter([(string) $style->template, (string) $style->parent]));
    }

    /**
     * Get a component-level default layout.
     *
     * Only action_button has a shared default.
     * All other layouts (response layouts like action_view_details,
     * action_tracking) are the plugin's responsibility.
     *
     * @param string $fileName Layout filename without .php
     *
     *
     * @since  1.0.0
     */
    private static function getDefaultLayout(string $fileName): FileLayout
    {
        $basePath = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';

        // Shared action button: ONE file for all contexts
        if ($fileName === 'action_button') {
            $expectedFile = $basePath . '/actions/button.php';

            if (!file_exists($expectedFile)) {
                self::warn(
                    "Shared action button layout MISSING: {$expectedFile}"
                    . '. No action buttons will render.'
                    . ' Deploy: layouts/actions/button.php',
                );
                return self::getEmptyLayout();
            }

            self::trace("Layout \"action_button\" -> Shared default: {$expectedFile}");
            return new FileLayout('actions.button', $basePath);
        }

        // Core-owned action layouts
        // Actions the COMPONENT itself offers have no plugin to provide a
        // layout; store credit is the case: it takes money with no gateway,
        // so there is no tmpl/ folder to look in.
        $coreFile = $basePath . '/actions/' . $fileName . '.php';

        if (file_exists($coreFile)) {
            self::trace("Layout \"{$fileName}\" -> Core default: {$coreFile}");

            return new FileLayout('actions.' . $fileName, $basePath);
        }

        // Response layouts: plugin must provide them
        self::warn(
            "Layout \"{$fileName}\" has no component default."
            . " The plugin must provide tmpl/{$fileName}.php."
            . ' Nothing will render for this action response.',
        );

        return self::getEmptyLayout();
    }

    /**
     * Create an empty layout that renders nothing.
     *
     * Used as a safe fallback when no layout file can be found.
     * Calling ->render() on this returns an empty string.
     *
     *
     * @since  1.0.0
     */
    public static function getEmptyLayout(): FileLayout
    {
        $empty = new FileLayout('non.existing.layout');
        $empty->clearIncludePaths();

        return $empty;
    }

    /**
     * Show a warning message: ALWAYS visible to the admin.
     *
     * Used for actual problems (missing files, bad config).
     * These are not debug noise; they indicate something is broken.
     *
     * @param string $message Warning message
     *
     *
     * @since  1.0.0
     */
    private static function warn(string $message): void
    {
        try {
            Factory::getApplication()->enqueueMessage(
                '<strong>[PluginLayout]</strong> ' . htmlspecialchars($message),
                'warning',
            );
        } catch (Throwable $e) {
            // Application not available (CLI, tests)
        }
    }

    /**
     * Show a trace message: only when JDEBUG is enabled.
     *
     * Used for step-by-step resolution trace (success paths).
     * Not needed in production, just noise.
     *
     * @param string $message Trace message
     *
     *
     * @since  1.0.0
     */
    private static function trace(string $message): void
    {
        if (defined('JDEBUG') && JDEBUG) {
            try {
                Factory::getApplication()->enqueueMessage(
                    '<strong>[PluginLayout]</strong> ' . htmlspecialchars($message),
                    'info',
                );
            } catch (Throwable $e) {
                // Silently ignore
            }
        }
    }
}
