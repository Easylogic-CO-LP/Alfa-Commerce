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
class CalculateShippingCostEvent extends ShipmentsEvent
{
    public function getCart()
    {
        return $this->getSubject();
    }

    public function onSetShippingCost(int $cost)
    {
        $this->setShippingCost($cost);
    }
    public function setShippingCost(int $cost)
    {
        $this->arguments['shippingCost'] = $cost;
    }
    
    public function getShippingCost(): ?int
    {
        return $this->arguments['shippingCost'] ?? 0;
    }
    
}