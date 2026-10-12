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
 * onAlfaAdminListRowData: the current page's rows, once, before anything renders.
 *
 * READ    entity, view, screen (list), user, state, items, ids
 * WRITE   rowData: [id => [key => value]]
 *
 * THIS IS THE N+1 FIREBREAK, and the reason it fires before the column phase. The whole page's
 * primary keys arrive together in `ids`, so a plugin resolves its values in exactly ONE query
 * however many rows are on screen and however many other plugins are doing the same:
 *
 *     public function onAlfaAdminListRowData(AdminListRowDataEvent $event): void
 *     {
 *         if (!$event->targets('order') || $event->getAdminContext()->ids === []) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *         $db      = Factory::getContainer()->get(DatabaseInterface::class);
 *         $query   = $db->getQuery(true)
 *             ->select($db->quoteName(['id_order', 'band']))
 *             ->from($db->quoteName('#__plg_fraud_scores'))
 *             ->whereIn($db->quoteName('id_order'), $context->ids);
 *
 *         foreach ($db->setQuery($query)->loadObjectList() as $row) {
 *             $context->rowData[(int) $row->id_order]['risk'] = $row->band;
 *         }
 *     }
 *
 * The accumulator is SHARED by every contributor, so key your values distinctly; the plugin's
 * own name is the obvious prefix. Contributed columns read straight out of it at render time;
 * a column resolver that queries per row defeats the entire arrangement.
 *
 * `items` are the model's finished, enriched rows. Enriching them in place is allowed and is
 * how a plugin adds something the core row object simply lacks, but rowData is the supported
 * channel; a row object's shape is the model's business and can change.
 *
 * @since  1.8.0
 */
final class AdminListRowDataEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminListRowData';
}
