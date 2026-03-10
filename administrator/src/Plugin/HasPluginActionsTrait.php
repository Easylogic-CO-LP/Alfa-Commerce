<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * HasPluginActionsTrait
 *
 * Shared actions management for GetPaymentActionsEvent and
 * GetShipmentActionsEvent. Extracted as a trait to avoid code
 * duplication between the two event classes.
 *
 * ═══════════════════════════════════════════════════════════════
 *  THREE WAYS TO REGISTER ACTIONS
 * ═══════════════════════════════════════════════════════════════
 *
 *   // 1. FLUENT API (preferred — fewest errors, most readable)
 *   $event->add('mark_paid', 'Mark as Paid')
 *       ->icon('checkmark')
 *       ->css('btn-success')
 *       ->confirm('Are you sure?')
 *       ->priority(200);
 *
 *   // 2. OBJECT (full control)
 *   $event->addAction(new PluginAction([
 *       'id'    => 'mark_paid',
 *       'label' => 'Mark as Paid',
 *       'icon'  => 'checkmark',
 *       'class' => 'btn-success',
 *   ]));
 *
 *   // 3. ARRAY SHORTHAND (trait wraps it in PluginAction for you)
 *   $event->addFromArray([
 *       'id'    => 'mark_paid',
 *       'label' => 'Mark as Paid',
 *       'icon'  => 'checkmark',
 *       'class' => 'btn-success',
 *   ]);
 *
 * Path: administrator/components/com_alfa/src/Plugin/HasPluginActionsTrait.php
 *
 * @since  3.5.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

defined('_JEXEC') or die;

trait HasPluginActionsTrait
{
    /** @var PluginAction[] */
    protected array $actions = [];

    /**
     * Add an action using the fluent API (preferred).
     *
     * @param string $id Unique action identifier
     * @param string $label Button label text
     *
     * @return PluginAction For chaining: ->icon()->css()->confirm()
     *
     * @since   3.5.0
     */
    public function add(string $id, string $label): PluginAction
    {
        $action = new PluginAction(['id' => $id, 'label' => $label]);
        $this->actions[] = $action;

        return $action;
    }

    /**
     * Add a pre-built PluginAction object.
     *
     *
     *
     * @since   3.5.0
     */
    public function addAction(PluginAction $action): void
    {
        $this->actions[] = $action;
    }

    /**
     * Add an action from a raw config array.
     *
     * @param array $config PluginAction constructor config
     *
     * @return PluginAction For chaining
     *
     * @since   3.5.0
     */
    public function addFromArray(array $config): PluginAction
    {
        $action = new PluginAction($config);
        $this->actions[] = $action;

        return $action;
    }

    /**
     * Get all actions sorted by priority (desc), filtered for valid + enabled.
     *
     * @return PluginAction[]
     *
     * @since   3.5.0
     */
    public function getActions(): array
    {
        usort($this->actions, fn (PluginAction $a, PluginAction $b) => $b->priority <=> $a->priority);

        return array_values(array_filter($this->actions, fn (PluginAction $a) => $a->isValid() && $a->enabled));
    }

    /**
     * Get all actions without filtering or sorting.
     *
     * @return PluginAction[]
     *
     * @since   3.5.0
     */
    public function getRawActions(): array
    {
        return $this->actions;
    }

    /**
     * Replace all actions.
     *
     * @param PluginAction[] $actions
     *
     *
     * @since   3.5.0
     */
    public function setActions(array $actions): void
    {
        $this->actions = $actions;
    }

    /**
     * Check whether any actions have been registered.
     *
     *
     * @since   3.5.0
     */
    public function hasActions(): bool
    {
        return !empty($this->actions);
    }

    /**
     * Get count of registered actions (before filtering).
     *
     *
     * @since   3.5.0
     */
    public function countActions(): int
    {
        return count($this->actions);
    }
}
