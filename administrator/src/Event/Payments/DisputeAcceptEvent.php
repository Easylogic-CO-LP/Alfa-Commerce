<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

\defined('_JEXEC') or die;

/**
 * onAlfaDisputeAccept: staff pressed Accept at the provider on an inquiry or an open chargeback. The gateway tells its
 * provider the shop accepts and, once the provider confirms, applies the loss itself with
 * $this->dispute(...)->lost()->save(); the provider's later notification of the same loss is then a repeat, written
 * with nothing new. Core writes nothing on Accept: the money moves only when the loss is recorded. It answers with
 * setVerdict(), or setError() in its own words.
 *
 *     public function onAlfaDisputeAccept(DisputeAcceptEvent $event): void
 *
 * A plugin without the method takes no part, and the dispute's card offers no button for it.
 *
 * @since  2.2.2
 */
final class DisputeAcceptEvent extends DisputeEvent
{
    /**
     * The event's name, the method a gateway writes.
     *
     * @since  2.2.2
     */
    public const NAME = 'onAlfaDisputeAccept';
}
