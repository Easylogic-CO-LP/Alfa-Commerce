<?php

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Helper
 * @version     3.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2025 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Plugin Layout Helper
 *
 * Resolves layout files for plugin actions with a two-step fallback:
 *   1. Plugin's own tmpl/ directory (plugin override)
 *   2. Component defaults:
 *      - action_button → layouts/orders/actions/button.php (ONE shared file)
 *      - anything else → empty (plugin's responsibility to provide it)
 *
 * Errors are ALWAYS shown as Joomla warning messages.
 * Step-by-step trace only appears when JDEBUG is enabled.
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\PluginHelper;
use Throwable;

defined('_JEXEC') or die;

class PluginLayoutHelper
{
    /**
     * Resolve a plugin layout file.
     *
     * Resolution order:
     *   1. plugins/{pluginType}/{pluginName}/tmpl/{fileName}.php  (plugin override)
     *   2. Component default (only for action_button — shared at layouts/orders/actions/button.php)
     *   3. Empty layout (renders nothing)
     *
     * @param string $pluginType Plugin group (e.g. 'alfa-payments', 'alfa-shipments')
     * @param string $pluginName Plugin element name (e.g. 'standard')
     * @param string $fileName Layout filename without .php (e.g. 'action_button')
     *
     * @return FileLayout Ready to call ->render($displayData)
     *
     * @since   3.0.0
     */
    public static function pluginLayout(string $pluginType, string $pluginName, string $fileName): FileLayout
    {
        // ── Validate inputs ──────────────────────────────────────
        if (empty($pluginType) || empty($pluginName) || empty($fileName)) {
            self::warn(
                'pluginLayout() called with empty arguments:'
                . " type=\"{$pluginType}\", name=\"{$pluginName}\", file=\"{$fileName}\"."
                . ' Cannot resolve layout.',
            );
            return self::getEmptyLayout();
        }

        // ── Step 1: Try plugin's own tmpl/ directory ─────────────
        $pluginPath = dirname(PluginHelper::getLayoutPath($pluginType, $pluginName, $fileName));
        $pluginFile = $pluginPath . '/' . $fileName . '.php';

        if (file_exists($pluginFile)) {
            self::trace("Layout \"{$fileName}\" → Plugin override: {$pluginFile}");
            return new FileLayout($fileName, $pluginPath);
        }

        self::trace(
            "Layout \"{$fileName}\" not found in plugin ({$pluginType}/{$pluginName}/tmpl/)."
            . ' Checking component default...',
        );

        // ── Step 2: Component default ────────────────────────────
        return self::getDefaultLayout($fileName);
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
     * @since   3.0.0
     */
    private static function getDefaultLayout(string $fileName): FileLayout
    {
        $basePath = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';

        // ── Shared action button — ONE file for all contexts ─────
        if ($fileName === 'action_button') {
            $expectedFile = $basePath . '/orders/actions/button.php';

            if (!file_exists($expectedFile)) {
                self::warn(
                    "Shared action button layout MISSING: {$expectedFile}"
                    . ' — No action buttons will render.'
                    . ' Deploy: layouts/orders/actions/button.php',
                );
                return self::getEmptyLayout();
            }

            self::trace("Layout \"action_button\" → Shared default: {$expectedFile}");
            return new FileLayout('orders.actions.button', $basePath);
        }

        // ── Response layouts — plugin must provide them ──────────
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
     * @since   3.0.0
     */
    public static function getEmptyLayout(): FileLayout
    {
        $empty = new FileLayout('non.existing.layout');
        $empty->clearIncludePaths();

        return $empty;
    }

    /**
     * Show a warning message — ALWAYS visible to the admin.
     *
     * Used for actual problems (missing files, bad config).
     * These are not debug noise — they indicate something is broken.
     *
     * @param string $message Warning message
     *
     *
     * @since   3.0.0
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
     * Show a trace message — only when JDEBUG is enabled.
     *
     * Used for step-by-step resolution trace (success paths).
     * Not needed in production — just noise.
     *
     * @param string $message Trace message
     *
     *
     * @since   3.0.0
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
