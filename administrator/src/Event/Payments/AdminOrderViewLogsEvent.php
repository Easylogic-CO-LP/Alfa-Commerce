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
 * @since  1.0.0
 */
class AdminOrderViewLogsEvent extends PaymentsLayoutEvent
{
    /**
     * Get the order/cart subject carried by the event.
     *
     * @return mixed The order or cart object
     *
     * @since  1.0.0
     */
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
    public function getLayoutType()
    {
        return $this->arguments['layout_type'];
    }

    /**
     * Set the log layout type, accepting only the 'base' or 'derived' values.
     *
     * @param string $type Either 'base' (base plugin layout) or 'derived' (plugin-provided layout)
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function setLayoutType($type)
    {
        // Excluding invalid values.
        if (
            $type == 'base' ||
            $type == 'derived'
        ) {
            $this->setArgument('layout_type', $type);
        }
    }
}
