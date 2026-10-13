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
 * onAlfaAdminPrepareData: the edit form's DATA, before it is bound to the form.
 *
 * READ    entity, view, screen (edit), user, item, isNew
 * WRITE   data: the record data, by handle
 *
 * The other half of onAlfaAdminForm: that one declares the field, this one fills it from
 * wherever the plugin actually keeps the value.
 *
 *     public function onAlfaAdminPrepareData(AdminPrepareDataEvent $event): void
 *     {
 *         if (!$event->isEditScreen() || !$event->targets('product')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *         $id      = (int) ($context->data['id'] ?? 0);
 *
 *         if ($id === 0) {
 *             return;
 *         }
 *
 *         $context->data['params']['warranty_months'] = $this->loadWarranty($id);
 *     }
 *
 * This event exists because Joomla's own equivalent does not fire here. AdminModel::getForm()
 * reaches loadFormData(), but com_alfa's models return their data without going through
 * preprocessData(), so onContentPrepareData never runs for any alfa entity; the contract
 * supplies the hook the CMS leaves inert.
 *
 * Data may also arrive from a failed save the framework kept, so a handler must tolerate a
 * value already being present and not overwrite what the user just typed.
 *
 * @since  2.0.0
 */
final class AdminPrepareDataEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminPrepareData';
}
