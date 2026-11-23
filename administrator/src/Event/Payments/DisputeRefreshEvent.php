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
 * onAlfaDisputeRefresh: staff pressed Ask the provider again. The gateway reads the case at its provider and applies it
 * with $this->dispute(...)->...->save(), then answers with setVerdict(), or setError() in its own words.
 *
 *     public function onAlfaDisputeRefresh(DisputeRefreshEvent $event): void
 *
 * A plugin without the method takes no part, and the dispute's card offers no button for it.
 *
 * @since  2.2.2
 */
final class DisputeRefreshEvent extends DisputeEvent
{
    /**
     * The event's name, the method a gateway writes.
     *
     * @since  2.2.2
     */
    public const NAME = 'onAlfaDisputeRefresh';
}
