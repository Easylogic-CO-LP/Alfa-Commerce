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

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Class for CustomFields events
 *
 * @since  5.0.0
 */
class AdminOrderAfterSaveEvent extends PaymentsEvent
{
    public function getOrder()
    {
        return $this->getSubject();
    }

    public function onSetCanSave(bool $canSave): bool
    {
        return $this->getCanSave();
    }

    public function setCanSave(bool $canSave): void
    {
        $this->arguments['can_save'] = $canSave;
    }

    public function getCanSave(): bool
    {
        return $this->arguments['can_save'];
    }
}
