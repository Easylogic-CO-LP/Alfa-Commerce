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

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\MethodViewRequest;
use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use Alfa\Component\Alfa\Site\Service\Cart\Method;

/**
 * Class for CustomFields events
 *
 * @since  1.0.0
 */
class CartViewEvent extends ShipmentsLayoutEvent
{
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.10.0
     */
    public function getMethod(): Method
    {
        return $this->getRequest()->method;
    }

    /**
     * The cart being offered.
     *
     * A convenience over `getRequest()->cart`, for the carrier or gateway whose
     * question the request's named fields do not answer: whether the basket
     * fits a parcel locker, where it is going, what is in it.
     *
     * READ ONLY, AND A SNAPSHOT. Cart wraps a HydratedCart whose properties are private readonly
     * properties and copy-returning mutators, so this cannot change the cart and
     * a reference kept past the dispatch reads the cart as it was when the
     * dispatch happened. Nothing on it recomputes, which is what keeps rendering
     * out of the totals.
     *
     *
     * @since  1.10.0
     */
    public function getCart(): Cart
    {
        return $this->getRequest()->cart;
    }
}
