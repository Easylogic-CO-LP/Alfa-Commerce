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
class AdminOrderBeforeSaveEvent extends ShipmentsEvent
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
     * Joomla event setter hook for 'can_save'; returns the current stored flag.
     *
     * @param   bool  $canSave  Incoming value (ignored; current flag is returned)
     *
     * @return  bool  The currently stored can-save flag
     *
     * @since  5.0.0
     */
    public function onSetCanSave(bool $canSave): bool
    {
        return $this->getCanSave();
    }

    /**
     * Store the can-save flag that tells the caller whether the order may be persisted.
     *
     * @param   bool  $canSave  Whether the order may be saved
     *
     * @return  void
     *
     * @since  5.0.0
     */
    public function setCanSave(bool $canSave): void
    {
        $this->arguments['can_save'] = $canSave;
    }

    /**
     * Get the can-save flag.
     *
     * @return  bool  Whether the order may be saved
     *
     * @since  5.0.0
     */
    public function getCanSave(): bool
    {
        return $this->arguments['can_save'];
    }
}
