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

/**
 * onAlfaAdminListColumns: extra columns in the grid.
 *
 * READ    entity, view, screen (list), user, state, items, ids, rowData
 * WRITE   columns: {@see AdminColumns}
 *
 * Fires immediately AFTER onAlfaAdminListRowData, so the page's values are already collected
 * and a column resolver is a lookup, never a query:
 *
 *     public function onAlfaAdminListColumns(AdminListColumnsEvent $event): void
 *     {
 *         if (!$event->isListScreen() || !$event->targets('order')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *
 *         $context->columns->column('risk')
 *             ->title('PLG_FRAUD_RISK')
 *             ->after('status')
 *             ->setLayout('column_risk')
 *             ->setData(fn (object $row): ?array => isset($context->rowData[(int) $row->id]['risk'])
 *                 ? ['band' => $context->rowData[(int) $row->id]['risk']]
 *                 : null);
 *     }
 *
 * The cell renders the named layout from the contributing plugin's own tmpl folder with
 * whatever the resolver returned; returning null renders an empty cell, so the table stays
 * rectangular no matter which rows have a value. after() names one of the screen's core column
 * keys; an unknown or omitted anchor puts the column after the last core one.
 *
 * @since  2.0.0
 */
final class AdminListColumnsEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminListColumns';
}
