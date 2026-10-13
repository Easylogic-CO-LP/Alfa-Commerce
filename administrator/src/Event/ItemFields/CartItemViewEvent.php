<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Alfa\Component\Alfa\Administrator\Event\General\LayoutEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern\HasSurfaces;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Cart line render hook (onCartItemView): the plugin sets a layout fragment
 * to render the customer's stored configuration on the cart line. Frontend =
 * layout only: anything (buttons, an edit link, a thumbnail) lives in the
 * layout. Same LayoutEvent contract as payments. Dispatch lands with task #7.
 *
 * Arguments: subject = the cart line, method = the field definition row,
 * snapshot = the stored configuration snapshot of this line+field.
 *
 * @since  2.0.0
 */
class CartItemViewEvent extends LayoutEvent
{
    use HasSurfaces;

    /**
     * The field's presentation on the cart line (the one cart-line slot). Fluent,
     * no enum import: main()->setLayout('cart_view')->setData([...]).
     *
     *
     * @since  2.0.0
     */
    public function main(): SurfaceBuilder
    {
        return $this->surfaceFor(CartSurface::Main->value);
    }

    /**
     * The cart line being rendered.
     *
     * @return mixed
     *
     * @since  2.0.0
     */
    public function getLine()
    {
        return $this->getSubject();
    }

    /**
     * The field definition this snapshot belongs to.
     *
     * @since  2.0.0
     */
    public function getField(): object
    {
        return $this->arguments['method'];
    }

    /**
     * The stored payload snapshot (what onValidateCartAdd accepted).
     *
     * @since  2.0.0
     */
    public function getSnapshot(): array
    {
        return $this->arguments['snapshot'] ?? [];
    }

    /**
     * The per-item plugin config of this field.
     *
     * @since  2.0.0
     */
    public function getConfig(): Registry
    {
        return $this->arguments['config'] ?? new Registry();
    }
}
