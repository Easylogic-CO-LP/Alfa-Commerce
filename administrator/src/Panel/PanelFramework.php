<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Panel;

\defined('_JEXEC') or die;

/**
 * The Panel framework's public-contract version: the ONE thing a plugin ties itself to
 * instead of a Joomla version.
 *
 * The contract is the set of classes a plugin depends on: {@see PanelIdentity},
 * {@see PanelDispatcher}, the Panel and Menu events, {@see \Alfa\Component\Alfa\Administrator\Toolbar\PanelToolbar},
 * {@see \Alfa\Component\Alfa\Administrator\Menu\MenuBuilder}. Everything BELOW them (how
 * Joomla boots plugins, renders toolbars, resolves layouts) may change freely; these
 * evolve slowly and deliberately, and this number rises only when they change in a way a
 * plugin would notice.
 *
 * A plugin may one day declare "supports Panel Framework v1" rather than pinning a Joomla
 * major. Today it is simply 1.
 *
 * @since  1.7.0
 */
final class PanelFramework
{
    /**
     * The current contract version.
     *
     * @var int
     * @since  1.7.0
     */
    public const VERSION = 1;
}
