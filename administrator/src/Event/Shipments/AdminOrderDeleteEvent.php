<?php

/**
 * Alfa Commerce
 *
 * @copyright  (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Class for CustomFields events
 *
 * @since  5.0.0
 */
class AdminOrderDeleteEvent extends ShipmentsEvent
{
    /**
     * Get the order/cart subject carried by the event.
     *
     * @return  mixed  The order or cart object
     *
     * @since  5.0.0
     */
    public function getOrder()
    {
        return $this->getSubject();
    }

    /**
     * Joomla event setter hook for 'result'; returns the current stored result.
     *
     * @param   bool  $result  Incoming value (ignored; current result is returned)
     *
     * @return  bool  The currently stored result flag
     *
     * @since  5.0.0
     */
    public function onSetResult(bool $result): bool
    {
        return $this->getResult();
    }

    /**
     * Store the result flag reported back by the handling plugin.
     *
     * @param   bool  $result  The result of the operation
     *
     * @return  void
     *
     * @since  5.0.0
     */
    public function setResult(bool $result): void
    {
        $this->arguments['result'] = $result;
    }

    /**
     * Get the result flag reported by the handling plugin.
     *
     * @return  bool  The operation result
     *
     * @since  5.0.0
     */
    public function getResult(): bool
    {
        return $this->arguments['result'];
    }
}
