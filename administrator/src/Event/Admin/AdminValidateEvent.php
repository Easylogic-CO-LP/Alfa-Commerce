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
 * onAlfaAdminValidate: the veto point of a save.
 *
 * READ    entity, view, screen (save), user, item (the STORED record, null when new), isNew,
 *         data (what was submitted), form
 * WRITE   errors: one message per objection
 *
 * Fires FIRST in the save round trip, before anything is written and before onAlfaAdminBeforeSave
 * can change anything. A non-empty errors list aborts the save cleanly and redisplays the form
 * with every message shown:
 *
 *     public function onAlfaAdminValidate(AdminValidateEvent $event): void
 *     {
 *         if (!$event->targets('product')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *
 *         if (($context->data['params']['warranty_months'] ?? 0) > 120) {
 *             $context->addError(Text::_('PLG_ALFA_WARRANTY_TOO_LONG'));
 *         }
 *     }
 *
 * VETO IS DATA, NOT AN EXCEPTION. Every handler gets to object and the user sees all of the
 * objections at once; throwing would hide the rest behind the first one and turn an ordinary
 * validation failure into an error page.
 *
 * Validate, do not repair: this phase must not write to the database and must not alter data.
 * Adjusting the record is what onAlfaAdminBeforeSave is for, and it only runs once every
 * handler has agreed the save should happen at all.
 *
 * @since  2.0.0
 */
final class AdminValidateEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminValidate';
}
