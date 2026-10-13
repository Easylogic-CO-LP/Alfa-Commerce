<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Lists;

defined('_JEXEC') or die;

/**
 * Shared column store for the admin list render events. A screen's list event
 * (onAlfaAdminListColumns via AdminColumns) stays thin (event name + its rows + this trait)
 * and every list gains the same fluent column contract:
 *
 *   $event->column('subscription')->title(...)->setLayout(...)->setData(...);
 *
 * Every column() call opens a NEW builder. The model harvests getColumns()
 * per invoked plugin (defaulting each descriptor's layout owner to that
 * plugin), and the template renders them through the Columns helper.
 *
 * @since  2.0.0
 */
trait HasColumns
{
    /**
     * @var ColumnBuilder[] Contributed columns, insertion order.
     * @since  2.0.0
     */
    private array $columns = [];

    /**
     * Declare a list column: the table's kind of surface.
     *
     * @param string $key The column's identity key (unique per contributor).
     *
     *
     * @since  2.0.0
     */
    public function column(string $key): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder(key: $key);
    }

    /**
     * The declared columns as plain descriptors: insertion order, only
     * contributions that actually set a cell layout.
     *
     * @return array[] Column descriptors (see ColumnBuilder::toDescriptor()).
     *
     * @since  2.0.0
     */
    public function getColumns(): array
    {
        $out = [];

        foreach ($this->columns as $column) {
            if ($column->hasLayout()) {
                $out[] = $column->toDescriptor();
            }
        }

        return $out;
    }
}
