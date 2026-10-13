<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * SlotResolver: turns a configured sub-template preference into a safe
 * loadTemplate() argument.
 *
 * The hierarchy is the caller's one-liner on already-loaded data
 * ($entity ?: $params->get(...)); valid values are guaranteed by the backend
 * pickers, so the only job here is existence: HtmlView::loadTemplate() throws
 * a fatal 500 for a missing sub-template argument (its silent fallback only
 * covers alternative MAIN layouts), so a variant is returned only when its
 * file resolves in the active template's override dirs or the component tmpl
 * dir. A stale value (deleted override, variant of a disabled template)
 * therefore degrades silently to the baseline slot, mirroring core layout
 * behaviour.
 *
 * @since  2.0.0
 */
final class SlotResolver
{
    /**
     * Resolve an effective sub-template name for one slot of a view.
     *
     * @param string $value Preferred sub-template from the settings chain ("" = baseline)
     * @param string $view Site view owning the slot (e.g. items)
     * @param string $slot Baseline slot name (e.g. item)
     * @param string $layout Active main layout of the view, for variants shipped
     *                       by an alternative layout ("fancy_item-compact.php")
     *
     * @return string loadTemplate() argument: the variant when valid, else the baseline slot
     * @since  2.0.0
     */
    public static function resolve(string $value, string $view, string $slot, string $layout = 'default'): string
    {
        $value = trim($value);

        if ($value === '' || $value === $slot) {
            return $slot;
        }

        foreach (self::searchDirs($view) as $dir) {
            if (is_file($dir . '/' . $layout . '_' . $value . '.php')
                || is_file($dir . '/default_' . $value . '.php')
            ) {
                return $value;
            }
        }

        return $slot;
    }

    /**
     * Resolve a slot preference to a concrete file for FileLayout callers
     * (AJAX partials that render tmpl files outside the view pipeline).
     *
     * Honours template overrides for the baseline too, so partial refreshes
     * return the same markup the full page rendered.
     *
     * @param string $value Preferred sub-template from the settings chain ("" = baseline)
     * @param string $view Site view owning the slot
     * @param string $slot Baseline slot name
     *
     * @return array{0: string, 1: string} [layout basename without extension, base path]
     * @since  2.0.0
     */
    public static function resolveFile(string $value, string $view, string $slot): array
    {
        $file = 'default_' . self::resolve(value: $value, view: $view, slot: $slot);

        foreach (self::searchDirs($view) as $dir) {
            if (is_file($dir . '/' . $file . '.php')) {
                return [$file, $dir];
            }
        }

        return ['default_' . $slot, JPATH_SITE . '/components/com_alfa/tmpl/' . $view];
    }

    /**
     * Locate a fixed (non-pickable) tmpl file, honouring template overrides.
     *
     * @param string $view Site view owning the file
     * @param string $file Layout basename without extension (e.g. default_cart_empty)
     *
     * @return array{0: string, 1: string} [layout basename, base path]
     * @since  2.0.0
     */
    public static function findFile(string $view, string $file): array
    {
        foreach (self::searchDirs($view) as $dir) {
            if (is_file($dir . '/' . $file . '.php')) {
                return [$file, $dir];
            }
        }

        return [$file, JPATH_SITE . '/components/com_alfa/tmpl/' . $view];
    }

    /**
     * Directories loadTemplate() will search for this view, most specific first
     * (child template override, parent template override, component tmpl).
     *
     * @param string $view Site view name
     *
     * @return string[] Existing candidate directories
     * @since  2.0.0
     */
    private static function searchDirs(string $view): array
    {
        $template = Factory::getApplication()->getTemplate(true);
        $dirs = [JPATH_THEMES . '/' . $template->template . '/html/com_alfa/' . $view];

        if (!empty($template->parent)) {
            $dirs[] = JPATH_THEMES . '/' . $template->parent . '/html/com_alfa/' . $view;
        }

        $dirs[] = JPATH_SITE . '/components/com_alfa/tmpl/' . $view;

        return array_filter($dirs, 'is_dir');
    }
}
