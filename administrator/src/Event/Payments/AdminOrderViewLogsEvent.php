<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license        GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

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
class AdminOrderViewLogsEvent extends PaymentsLayoutEvent
{
    public function getOrder()
    {
        return $this->getSubject();
    }

    /*
     *  Base Plugin class provides its own layout for displaying logs.
     *  Derived plugins need to provide information about whether they
     *      are providing their own template, or if the one from the
     *      base class should be used.
     *
     *  layout_type == base     // Base layout
     *  layout_type == derived  // Derived, layout provided by plugin.
     */
    public function getLayoutType(){
        return $this->arguments['layout_type'];
    }

    public function setLayoutType($type){
        // Excluding invalid values.
        if(
            $type == "base" ||
            $type == "derived"
        ){
            $this->setArgument('layout_type', $type);
        }
    }

}