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
 * Alfa's targeted-handler declaration (2.2.0, design 2.15.2): the events Alfa addresses to this plugin
 * alone, each with the public method that handles it. It registers nothing on Joomla's shared dispatcher: the helper's
 * targeted mode reads it and calls the declared method on the instance Joomla booted. An event name is in this
 * declaration or in the plugin's getSubscribedEvents(), never in both; a plugin that declares targeted handlers is a
 * SubscriberInterface plugin, because Joomla registers every public on* method of any other plugin on the shared
 * dispatcher.
 *
 * A plugin that only Alfa Commerce loads (the alfa groups) implements this interface. A plugin of a group Joomla loads
 * itself (task, system, user, content and the others) declares with a public getTargetedHandlers() of the same shape
 * alone and names no Alfa class or interface at class level, so it still loads when Alfa Commerce is not installed;
 * the plugin helper reads both (AlfaPluginHelper::declarationOf(), A-C14B-15).
 *
 * @since  2.2.0
 */
interface TargetedHandlersInterface
{
    /**
     * The targeted events this plugin handles.
     *
     * @return array<string, string> Event name to the name of the public method that handles it.
     *
     * @since  2.2.0
     */
    public function getTargetedHandlers(): array;
}
