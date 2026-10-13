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

use Alfa\Component\Alfa\Administrator\Plugin\Traits\HasPluginActionsTrait;
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
 * OWNERSHIP IS NOT SELF-DECLARED. Each plugin is booted and called individually and
 * whatever it registers is tagged with the plugin that was called, so a contributor
 * cannot claim another's identity and an action always knows who executes it. That
 * is what lets {@see \Alfa\Component\Alfa\Administrator\Helper\ToolRegistry} boot
 * exactly ONE plugin when the action runs.
 *
 * CORE'S OWN TOOLS COME THROUGH HERE TOO. An extension point the host does not use
 * itself becomes second-class and rots: the admin column builder says it serves
 * "ANY admin list screen" and is used by exactly one.
 *
 * @since  2.0.0
 */
class GetToolActionsEvent extends AbstractEvent
{
    use HasPluginActionsTrait;

    /**
     * Actions registered so far, tagged with the contributor that was called.
     *
     * Called by the registry between contributors, never by a plugin. Reads the
     * trait's raw list rather than the filtered one so an action a plugin has
     * deliberately disabled still carries an owner and can be reported honestly
     * instead of vanishing without explanation.
     *
     *
     * @return void
     *
     * @since  2.0.0
     */
    /**
     * Every valid action, INCLUDING disabled ones.
     *
     * Overrides the shared trait, which filters disabled actions out, right for the
     * modal surfaces, where a disabled action has no page to open, and wrong here: a
     * tool whose prerequisites are unmet should show as a greyed-out card explaining
     * itself, not vanish and leave the merchant hunting for a button that "was there
     * yesterday". Execution still refuses disabled actions server-side, so visibility
     * never becomes runnability.
     *
     * @return \Alfa\Component\Alfa\Administrator\Plugin\PluginAction[]
     *
     * @since  2.0.0
     */
    public function getActions(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn ($action): bool => $action->isValid(),
        ));
    }

    public function attributeTo(string $group, string $name): void
    {
        foreach ($this->actions as $action) {
            if (($action->metadata['owner_group'] ?? '') !== '') {
                continue;
            }

            $action->metadata['owner_group'] = $group;
            $action->metadata['owner_name'] = $name;
        }
    }
}
