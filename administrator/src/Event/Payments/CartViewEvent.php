<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CheckoutSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\MethodViewRequest;
use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use Alfa\Component\Alfa\Site\Service\Cart\Method;

/**
 * The cart's render hook for one offered payment method (onCartView): what the
 * method's plugin draws under the method in the cart's payment methods, through
 * paymentMethods(). A plugin that draws nothing leaves core's drawing of the method alone.
 *
 * @since  1.0.0
 */
class CartViewEvent extends PaymentsLayoutEvent
{
    use HasSurfaces;

    /**
     * The method's panel under its row in the cart's payment methods. Fluent:
     * paymentMethods()->setLayout('default_cart_view')->setData([...]).
     *
     * @since  2.3.0
     */
    public function paymentMethods(): SurfaceBuilder
    {
        return $this->surfaceFor(CheckoutSurface::PaymentMethods->value);
    }

    /**
     * Constructor.
     *
     * The method is not a separate argument here: it travels inside the
     * request, as an id, a type and its configuration. A layout event that
     * renders a method still requires one; this one renders what a method
     * asked for, and asks it with facts rather than with a database row.
     *
     * @param string $name The event name.
     * @param array $arguments Must carry the MethodViewRequest as 'subject'.
     *
     * @since  2.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        $arguments['method'] ??= null;

        parent::__construct($name, $arguments);
    }

    /**
     * What this method is being asked to show the shopper.
     *
     * Settled facts: which method, how it is configured, whether it is the
     * chosen one, what the cart comes to, and, on the request, the cart it
     * comes to that on.
     *
     *
     * @since  2.0.0
     */
    public function getRequest(): MethodViewRequest
    {
        return $this->getSubject();
    }

    /**
     * The method being rendered, entire.
     *
     * Its id, type, name, description, configuration and anything else the
     * record carries; see {@see Method}. One accessor rather than five,
     * because a plugin should never be blocked from reading its own method by
     * a getter the component has not written yet.
     *
     *
     * @since  2.0.0
     */
    public function getMethod(): Method
    {
        return $this->getRequest()->method;
    }

    /**
     * The cart being offered.
     *
     * A convenience over `getRequest()->cart`. The payments twin of the
     * shipments hook by design: both draw a method row in the same selector,
     * both are handed the same MethodViewRequest, so both expose the same
     * accessor rather than diverging on the same job.
     *
     * READ ONLY, AND A SNAPSHOT: see the shipments event for the reasoning;
     * Cart cannot mutate the cart and nothing on it recomputes.
     *
     *
     * @since  2.0.0
     */
    public function getCart(): Cart
    {
        return $this->getRequest()->cart;
    }
}
