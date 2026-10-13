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

use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Product-page render hook for a payment method. Beyond its default display in
 * the payment-methods area, a payment plugin may contribute to shared page slots
 * (e.g. a finance badge by the title) via the same surfaces vocabulary the item
 * fields use: one container model, source-agnostic rendering.
 *
 * @since  1.0.0
 */
class ItemViewEvent extends PaymentsLayoutEvent
{
    use HasSurfaces;

    /**
     * Get the item/product subject carried by the event.
     *
     * @return mixed The item object
     *
     * @since  1.0.0
     */
    public function getItem()
    {
        return $this->getSubject();
    }

    /**
     * The method's display in the payment-methods area (its default slot). Fluent,
     * no enum import: paymentMethods()->setLayout('x')->setData([...]).
     *
     *
     * @since  2.0.0
     */
    public function paymentMethods(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::PaymentMethods->value);
    }

    /**
     * A surface beside the product title, e.g. a finance badge.
     *
     *
     * @since  2.0.0
     */
    public function title(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::Title->value);
    }

    /**
     * A surface next to the add-to-cart button.
     *
     *
     * @since  2.0.0
     */
    public function nearAddToCart(): SurfaceBuilder
    {
        return $this->surfaceFor(ItemSurface::NearAddToCart->value);
    }
}
