<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license        GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Event\Result\ResultAware;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Event\Result\ResultTypeStringAware;

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
    public function getOrder()
    {
        return $this->getSubject();
    }

    public function onSetCanDelete(bool $canDelete): bool
    {
        return $this->getCanDelete();
    }

    public function setCanDelete(bool $canDelete): void
    {
        $this->arguments['can_delete'] = $canDelete;
    }

    public function getCanDelete(): bool
    {
        return $this->arguments['can_delete'];
    }


}
