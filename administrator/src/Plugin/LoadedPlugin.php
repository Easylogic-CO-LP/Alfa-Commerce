<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

\defined('_JEXEC') or die;

/**
 * A plugin booted for this request through the helper (2.2.0, design 2.15.2): its identity only. The instance Joomla
 * holds stays inside the helper, so business code can address the plugin through the helper's targeted calls and
 * cannot call a plugin method on it directly.
 *
 * @since  2.2.0
 */
final class LoadedPlugin
{
    /**
     * Made by AlfaPluginHelper::bootSingle() and ::resolve() only.
     *
     * @param PluginId $id The plugin.
     *
     * @internal
     *
     * @since  2.2.0
     */
    public function __construct(public readonly PluginId $id)
    {
    }
}
