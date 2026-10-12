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
 * onAlfaAdminBeforeDelete: the veto point of a delete.
 *
 * READ    entity, view, screen (delete), user, ids
 * WRITE   errors: one message per objection
 *
 * Fires once with the whole set, before any of it is touched, so a refusal stops the operation
 * rather than leaving half the selection gone. `ids` is what the model is ABOUT to delete: an
 * entity that refuses some of the selection itself (a currency still in use, a category with
 * children) has already narrowed the list before delegating here.
 *
 *     public function onAlfaAdminBeforeDelete(AdminBeforeDeleteEvent $event): void
 *     {
 *         if (!$event->targets('product')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *
 *         if ($this->countActiveContracts($context->ids) > 0) {
 *             $context->addError(Text::_('PLG_ALFA_WARRANTY_STILL_ACTIVE'));
 *         }
 *     }
 *
 * VETO IS DATA, NOT AN EXCEPTION: a non-empty errors list aborts cleanly and shows every
 * objection; throwing would hide the rest behind the first one.
 *
 * This is also the last moment the records still exist, so a handler that needs to READ them
 * before they go (to archive, to log what was there) must do it here. Cleaning up the plugin's
 * own rows belongs in onAlfaAdminAfterDelete, once the delete has actually happened.
 *
 * @since  1.8.0
 */
final class AdminBeforeDeleteEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminBeforeDelete';
}
