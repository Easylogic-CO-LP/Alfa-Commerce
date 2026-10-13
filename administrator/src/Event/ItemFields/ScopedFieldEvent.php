<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

defined('_JEXEC') or die;

/**
 * A field event PRE-BOUND to one row+field at one scope: the shared shape of
 * GetFieldActionsEvent and ExecuteFieldActionEvent. It carries WHICH row the
 * action targets (scope/scopeId/itemId/field) and nothing else; the config I/O
 * lives in ItemFieldsPlugin (getConfig()/saveConfig()), fed this event, so one
 * helper serves every scoped surface.
 *
 * @since  2.0.0
 */
interface ScopedFieldEvent
{
    /**
     * The surface asking: 'item' | 'combination' | 'option' | 'order'.
     *
     *
     * @since  2.0.0
     */
    public function getScope(): string;

    /**
     * The scope row id (item / combination / option-value / order-item id).
     *
     *
     * @since  2.0.0
     */
    public function getScopeId(): int;

    /**
     * The owning item id.
     *
     *
     * @since  2.0.0
     */
    public function getItemId(): int;

    /**
     * The field definition row.
     *
     *
     * @since  2.0.0
     */
    public function getField(): object;
}
