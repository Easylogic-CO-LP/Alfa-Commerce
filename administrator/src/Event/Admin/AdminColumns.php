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

/**
 * The one result accumulator of onAlfaAdminListColumns: every entity's grid, sharing the
 * column kit the orders grid already proved.
 *
 * The fluent descriptor ({@see ColumnBuilder}) and the template-side renderer
 * ({@see \Alfa\Component\Alfa\Administrator\Helper\Columns}) are unchanged and NOT re-invented
 * here; this class adds only what a broadcast dispatch needs that a per-plugin event did not:
 * an owner stamp. The orders dialect handed each plugin its OWN fresh event and defaulted the
 * layout owner afterwards, which cannot work when every plugin writes into one accumulator, so
 * the dispatcher names the current contributor between handlers and each new column is stamped
 * as it is opened.
 *
 * A plugin may still point at another plugin's layout by calling setPluginType()/setPluginName()
 * on the descriptor: the stamp is a default, not a lock.
 *
 * @since  1.8.0
 */
final class AdminColumns
{
    use HasColumns {
        column as private openColumn;
    }

    /**
     * @var string The plugin group currently contributing.
     * @since  1.8.0
     */
    private string $ownerGroup = '';

    /**
     * @var string The plugin element currently contributing.
     * @since  1.8.0
     */
    private string $ownerElement = '';

    /**
     * Declare a list column. Identical contract to the orders grid:
     *
     *     $context->columns->column('risk')
     *         ->title('PLG_ALFA_FRAUD_RISK_COLUMN')
     *         ->after('status')
     *         ->setLayout('column_risk')
     *         ->setData(fn (object $row): ?array => $context->rowData[(int) $row->id] ?? null);
     *
     * The anchor passed to after() is one of the dispatching screen's core column keys; an
     * unknown or omitted anchor appends the column after the last core column. The data
     * resolver runs once per row at render time and returning null renders an empty cell, so
     * the table always stays rectangular.
     *
     * Resolve the row's value out of {@see AlfaAdminContext::$rowData} rather than querying
     * inside the closure: onAlfaAdminListRowData hands the whole page's ids exactly so the
     * whole column costs ONE query no matter how many rows or plugins are involved.
     *
     * @param string $key The column's identity key (unique per contributor).
     *
     * @return ColumnBuilder For chaining.
     *
     * @since  1.8.0
     */
    public function column(string $key): ColumnBuilder
    {
        return $this->openColumn($key)
            ->setPluginType($this->ownerGroup)
            ->setPluginName($this->ownerElement);
    }

    /**
     * Name the plugin whose handler is about to run, so its columns' layouts default to its
     * own tmpl folder.
     *
     * FRAMEWORK ONLY: called by {@see AlfaAdminDispatcher} between handlers.
     *
     * @param string $group The contributing plugin's group.
     * @param string $element The contributing plugin's element.
     *
     *
     * @since  1.8.0
     */
    public function setOwner(string $group, string $element): void
    {
        $this->ownerGroup = $group;
        $this->ownerElement = $element;
    }
}
