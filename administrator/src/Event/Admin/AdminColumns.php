<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Admin;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Lists\ColumnBuilder;
use Alfa\Component\Alfa\Administrator\Event\Lists\HasColumns;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;

/**
 * The one result accumulator of onAlfaAdminListColumns: every entity's grid, sharing the
 * column kit the orders grid already proved.
 *
 * The fluent descriptor ({@see ColumnBuilder}) and the template-side renderer
 * ({@see \Alfa\Component\Alfa\Administrator\Helper\Columns}) are unchanged and NOT re-invented
 * here; this class adds only what a broadcast needs that a per-plugin event did not: the
 * contributor. Every plugin writes into one accumulator, so each column names the plugin that
 * declares it (its `group.element`, 2.2.0), which defaults the column's layouts to that plugin's
 * own tmpl folder. The identity is attribution, never an authorisation; nothing is inferred from
 * the listener.
 *
 * A plugin may still point at another plugin's layout by calling setPluginType()/setPluginName()
 * on the descriptor: the contributor is a default, not a lock.
 *
 * @since  2.0.0
 */
final class AdminColumns
{
    use HasColumns {
        column as private openColumn;
    }

    /**
     * Declare a list column. Identical contract to the orders grid:
     *
     *     $context->columns->column('alfa.fraud', 'risk')
     *         ->title('PLG_ALFA_FRAUD_RISK_COLUMN')
     *         ->after('status')
     *         ->setLayout('column_risk')
     *         ->setData(fn (object $row): ?array => $context->rowValues((int) $row->id));
     *
     * The anchor passed to after() is one of the dispatching screen's core column keys; an
     * unknown or omitted anchor appends the column after the last core column. The data
     * resolver runs once per row at render time and returning null renders an empty cell, so
     * the table always stays rectangular.
     *
     * Resolve the row's value out of {@see AlfaAdminContext::rowValues()} rather than querying
     * inside the closure: onAlfaAdminListRowData hands the whole page's ids exactly so the
     * whole column costs ONE query no matter how many rows or plugins are involved.
     *
     * @param string $contributor The declaring plugin, group.element (2.2.0).
     * @param string $key The column's identity key (unique per contributor).
     *
     * @return ColumnBuilder For chaining.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function column(string $contributor, string $key): ColumnBuilder
    {
        $owner = PluginId::of($contributor);

        return $this->openColumn($key)
            ->setPluginType($owner->group)
            ->setPluginName($owner->element);
    }
}
