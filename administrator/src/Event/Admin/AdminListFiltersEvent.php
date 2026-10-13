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
 * onAlfaAdminListFilters: the grid's filter form, before it renders.
 *
 * READ    entity, view, screen (list), user, state
 * WRITE   form: the live filter Form
 *
 * The filter field is declared HERE and read in onAlfaAdminListQuery, so one plugin owns both
 * halves of its filter. This is what retires the third seam the orders grid still has, where a
 * filter's field rode a generic onContentPrepareForm guarded by the form's name while its
 * SQL rode a different event entirely.
 *
 *     public function onAlfaAdminListFilters(AdminListFiltersEvent $event): void
 *     {
 *         if (!$event->targets('order')) {
 *             return;
 *         }
 *
 *         $event->getAdminContext()->form->load(
 *             '<form><fields name="filter">'
 *             . '<field name="risk" type="list" label="PLG_FRAUD_FILTER_RISK">'
 *             . '<option value="">JOPTION_SELECT_PUBLISHED</option>'
 *             . '<option value="high">PLG_FRAUD_RISK_HIGH</option>'
 *             . '</field></fields></form>'
 *         );
 *     }
 *
 * The submitted VALUE carries automatically: ListModel::populateState() copies every submitted
 * filter into state as `filter.<name>` unless the model lists the name in its
 * $filterForbiddenList (empty by default), so declaring the field here is genuinely all a
 * plugin needs before reading `$context->state->get('filter.<name>')` in onAlfaAdminListQuery.
 * (filter_fields is a different whitelist: it validates ORDERING columns, not filter values.)
 *
 * @since  2.0.0
 */
final class AdminListFiltersEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminListFilters';
}
