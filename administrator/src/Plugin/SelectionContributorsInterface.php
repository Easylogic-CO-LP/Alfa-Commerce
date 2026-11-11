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
 * A collecting event delivered over a selection of plugins (2.2.0, design 2.15.4): the helper tells it which
 * plugin is answering, so a contribution that names another plugin is dropped and logged. A consistency check on
 * attribution, never an authorisation.
 *
 * @since  2.2.0
 */
interface SelectionContributorsInterface
{
    /**
     * The plugin whose handler runs now; null once it returned.
     *
     * @internal The plugin helper calls it around each handler of a selection loop; a plugin never calls it.
     *
     * @param PluginId|null $plugin The plugin.
     *
     * @since  2.2.0
     */
    public function answering(?PluginId $plugin): void;
}
