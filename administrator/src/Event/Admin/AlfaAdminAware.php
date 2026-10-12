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

use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Throwable;

/**
 * What every participant in the admin contract needs before it can dispatch: its entity name, the
 * acting user, the request's form token, and the one question that decides whether to build a
 * context at all.
 *
 * Shared by the base list model, the base edit model and the base views, so an entity's name and
 * the opt-in rule are declared once instead of three times.
 *
 * @since  1.8.0
 */
trait AlfaAdminAware
{
    /**
     * The entity this class serves, singular and lower case ('order', 'usergroup', 'currency'),
     * the name a plugin matches with {@see AlfaAdminContext::targets()}.
     *
     * OPT-IN BY DECLARATION. An empty name dispatches NOTHING, which is what makes the base
     * classes safe to introduce ahead of the entities that use them: a screen that has not named
     * itself yet behaves exactly as it did before. Every entity rolled onto the contract sets it:
     *
     *     protected string $alfaEntity = 'currency';
     *
     * List and edit screens of one entity share the SINGULAR name; the screen is what tells them
     * apart, not the entity.
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = '';

    /**
     * Whether this class should dispatch the given event at all: it has named its entity AND some
     * enabled plugin in the `alfa` platform group actually implements the event.
     *
     * Ask BEFORE doing any work a dispatch needs: building a context, re-deriving rows, re-binding
     * a form, re-reading a record. With no contributor the answer is "do nothing": measured on the
     * pilot screens, adopting the contract left the rendered bytes, the query count, the SQL itself
     * and the set of booted plugins all unchanged.
     *
     * @param string $event The event name (an AlfaAdminEvent::NAME).
     *
     *
     * @since  1.8.0
     */
    protected function alfaDispatches(string $event): bool
    {
        return $this->alfaEntity !== '' && AlfaAdminDispatcher::hasSubscribers($event);
    }

    /**
     * The raw Joomla view this class serves, lower case ('currencies', 'currency'): the context's
     * `view`, next to the entity. Models and views both answer it from their own MVC name, so a
     * plugin sees the same string whichever side raised the event.
     *
     *
     * @since  1.8.0
     */
    protected function alfaView(): string
    {
        return strtolower($this->getName());
    }

    /**
     * The acting user, for the context's actor band.
     *
     *
     * @since  1.8.0
     */
    protected function alfaUser(): User
    {
        return $this->getCurrentUser();
    }

    /**
     * The session's CSRF form-token name, so a contributed action or layout can post back without
     * knowing how the component builds its forms.
     *
     * @return string The token name, or '' when there is no session (CLI, install).
     *
     * @since  1.8.0
     */
    protected function alfaToken(): string
    {
        try {
            return Session::getFormToken();
        } catch (Throwable) {
            return '';
        }
    }
}
