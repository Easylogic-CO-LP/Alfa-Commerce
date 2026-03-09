<?php

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Event.Payments
 * @version     3.5.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Get Payment Actions Event
 *
 * Triggered when the admin order page needs to render action buttons
 * for a payment. Each payment plugin's onGetActions() receives this
 * event and registers its buttons via the fluent API.
 *
 * ═══════════════════════════════════════════════════════════════
 *  REGISTERING ACTIONS (in plugin's onGetActions)
 * ═══════════════════════════════════════════════════════════════
 *
 *   $event->add('mark_paid', 'Mark as Paid')
 *       ->icon('checkmark')->css('btn-success')
 *       ->confirm('Mark this payment as paid?');
 *
 * ═══════════════════════════════════════════════════════════════
 *  READING CONTEXT
 * ═══════════════════════════════════════════════════════════════
 *
 *   $payment = $event->getPayment();
 *   $order   = $event->getOrder();
 *
 * ═══════════════════════════════════════════════════════════════
 *  CONSUMING ACTIONS (in controller/view)
 * ═══════════════════════════════════════════════════════════════
 *
 *   $actions = $event->getActions();
 *   // PluginAction[] sorted by priority desc, valid + enabled only
 *
 * Path: administrator/components/com_alfa/src/Event/Payments/GetPaymentActionsEvent.php
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Administrator\Plugin\HasPluginActionsTrait;
use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class GetPaymentActionsEvent extends AbstractImmutableEvent
{
    use HasPluginActionsTrait;

    /**
     * Get the payment record (with decoded params and method info).
     *
     *
     * @since   3.0.0
     */
    public function getPayment(): object
    {
        return $this->arguments['payment'];
    }

    /**
     * Get the order record.
     *
     *
     * @since   3.0.0
     */
    public function getOrder(): object
    {
        return $this->arguments['order'];
    }
}
