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
 * onAlfaAdminListQuery: the finished list query, before it runs.
 *
 * READ    entity, view, screen (list), user, state
 * WRITE   query: the live DatabaseQuery
 *
 * Filtering MUST happen in SQL. Cutting rows after the load would silently break pagination
 * and totals, because Joomla counts with this same query.
 *
 * Add an EXISTS subquery rather than a JOIN: a JOIN can multiply rows and change the grid's
 * count even when the filter matches nothing. And add NOTHING while your filter is inactive:
 * an always-on clause turns every other screen's query into your plugin's problem.
 *
 *     public function onAlfaAdminListQuery(AdminListQueryEvent $event): void
 *     {
 *         if (!$event->targets('order')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *         $risk    = $context->state?->get('filter.risk');
 *
 *         if ($risk === null || $risk === '') {
 *             return;
 *         }
 *
 *         $db = $this->getDatabase();
 *
 *         $context->query->where(
 *             'EXISTS (SELECT 1 FROM #__plg_fraud_scores s WHERE s.id_order = a.id AND s.band = '
 *             . $db->quote($risk) . ')'
 *         );
 *     }
 *
 * The filter's VALUE reaches the state through onAlfaAdminListFilters, which is where the
 * field is declared: the two halves of one filter, never a third seam.
 *
 * @since  1.8.0
 */
final class AdminListQueryEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminListQuery';
}
