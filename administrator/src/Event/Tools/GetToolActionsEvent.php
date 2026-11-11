<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Tools;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractEvent;

/**
 * Collects the maintenance actions offered on the Tools screen.
 *
 * A TOOL IS A CAPABILITY, NOT A PLUGIN TYPE. A search plugin wants a reindex
 * button, a media plugin an optimise button, an import plugin a validate button;
 * none of them is a "tools plugin". That is why this is an event any plugin may
 * answer rather than a base class something must extend: three of the fourteen
 * plugins in this component's own groups extend `CMSPlugin` directly and share no
 * base class with anything, yet any of them may legitimately need a tool.
 *
 * WHICH TAB AN ACTION LANDS ON is `meta('surface', ...)`. A known tab id places the
 * action inside that tab; an unknown one opens a tab of its own. One API, both
 * outcomes, and no new vocabulary: {@see \Alfa\Component\Alfa\Administrator\Plugin\PluginAction}
 * already carries metadata, ordering, icon, styling, confirmation and modals.
 *
 * EVERY ACTION NAMES ITS PLUGIN (2.2.0). The event reaches every listener on the
 * shared dispatcher, and each action is added with its contributor, group.element,
 * first (core's own tools as core.com_alfa): attribution, never an authorisation.
 * That is what lets {@see \Alfa\Component\Alfa\Administrator\Helper\ToolRegistry}
 * run the action on exactly ONE plugin, the one that listed it, through that
 * plugin's own declaration of onExecuteToolAction.
 *
 * CORE'S OWN TOOLS COME THROUGH HERE TOO. An extension point the host does not use
 * itself becomes second-class and rots: the admin column builder says it serves
 * "ANY admin list screen" and is used by exactly one.
 *
 * @since  2.0.0
 */
class GetToolActionsEvent extends AbstractEvent
{
    /**
     * The actions added so far, each owned by the plugin that named itself; written only through add() and addAction().
     *
     * @var PluginAction[]
     *
     * @since  2.2.0
     */
    private array $actions = [];

    /**
     * Add an action using the fluent API, owned by the plugin that names itself.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Action id, unique within the contributor.
     * @param string $label Button label (a language key).
     *
     * @return PluginAction For chaining: ->icon()->css()->confirm()
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function add(string $contributor, string $id, string $label): PluginAction
    {
        $action = new PluginAction(['id' => $id, 'label' => $label]);
        $this->addAction($contributor, $action);

        return $action;
    }

    /**
     * Add a pre-built action, owned by the plugin that names itself.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param PluginAction $action The action.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function addAction(string $contributor, PluginAction $action): void
    {
        $owner = PluginId::of($contributor);
        $action->metadata['owner_group'] = $owner->group;
        $action->metadata['owner_name'] = $owner->element;
        $this->actions[] = $action;
    }

    /**
     * Every valid action, INCLUDING disabled ones.
     *
     * A tool whose prerequisites are unmet should show as a greyed-out card explaining
     * itself, not vanish and leave the merchant hunting for a button that "was there
     * yesterday". Execution still refuses disabled actions server-side, so visibility
     * never becomes runnability.
     *
     * @return PluginAction[]
     *
     * @since  2.0.0
     */
    public function getActions(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (PluginAction $action): bool => $action->isValid(),
        ));
    }
}
