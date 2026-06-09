<?php

/**
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
 * Redirect tier for payment hooks fired outside a view — currently the gateway-return
 * handler `onPaymentResponse`. The plugin may set a redirect (where to send the buyer
 * after the bank); it renders no layout, because the controller that dispatches it has
 * no view. To show something instead of redirecting, redirect to a view layout.
 *
 * @since  1.0.0
 */
abstract class PaymentsRedirectEvent extends \Alfa\Component\Alfa\Administrator\Event\General\RedirectEvent
{
}
