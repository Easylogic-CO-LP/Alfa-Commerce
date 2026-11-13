<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Alfa\Component\Alfa\Site\Service\Order\Order;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * onAdminOrderDelete for a carrier: asked before an order is deleted, once for each shipment its plugin recorded, so
 * the plugin may refuse the delete (setResult(false)). getOrder() is the order and getMethod() the shipment, as the
 * payment twin's getMethod() is the payment (2.2.1).
 *
 * @since  1.0.0
 */
class AdminOrderDeleteEvent extends ShipmentsEvent
{
    /**
     * Get the order/cart subject carried by the event.
     *
     * @return mixed The order or cart object
     *
     * @since  1.0.0
     */
    public function getOrder(): Order
    {
        return Order::of($this->getSubject());
    }

    /**
     * The shipment the plugin is asked about: the order's shipment row this plugin recorded.
     *
     * @return object The shipment row.
     *
     * @since  2.2.1
     */
    public function getMethod(): object
    {
        return $this->arguments['method'];
    }

    /**
     * Joomla event setter hook for 'result'; returns the current stored result.
     *
     * @param bool $result Incoming value (ignored; current result is returned)
     *
     * @return bool The currently stored result flag
     *
     * @since  1.0.0
     */
    public function onSetResult(bool $result): bool
    {
        return $this->getResult();
    }

    /**
     * Store the result flag reported back by the handling plugin.
     *
     * @param bool $result The result of the operation
     *
     *
     * @since  1.0.0
     */
    public function setResult(bool $result): void
    {
        $this->arguments['result'] = $result;
    }

    /**
     * Get the result flag reported by the handling plugin.
     *
     * @return bool The operation result
     *
     * @since  1.0.0
     */
    public function getResult(): bool
    {
        // Default to allow: a handler that never set a result must not veto (or crash) the delete.
        return $this->arguments['result'] ?? true;
    }
}
