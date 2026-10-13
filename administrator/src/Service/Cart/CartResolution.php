<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

/**
 * Which cart a caller means, and what it is allowed to cost.
 *
 * Three different questions were being asked through one door, and the door
 * could not tell them apart:
 *
 *   CURRENT is a look. A page that renders a cart, a form that reloads its
 *   method list: nothing it does may leave a row behind, and a shopper who has
 *   never added anything must still have no cart after it.
 *
 *   MUTABLE is an intent to change something. The cart may not exist yet, and
 *   the operation that needs one is entitled to create it, at the moment it
 *   needs it, not before.
 *
 *   NAMED is an id somebody supplied: an operator working a specific cart, or
 *   the checkout re-opening one. It never discovers, never adopts and never
 *   creates, because all three would answer a question that was not asked.
 *
 *   FRESH is "mint one, whatever else exists". It is the back office asking for
 *   a NEW cart on a customer's behalf, and the only mode that creates without
 *   first looking. It must not discover, because the operator's browser and the
 *   operator's account are not the customer's: discovering would hand back the
 *   operator's own storefront cart, or the customer's, or (for a second
 *   walk-in) the first walk-in. "New cart" has to mean a new cart.
 *
 * The distinction is load-bearing rather than descriptive: reading a cart in
 * MUTABLE mode inserts rows for visitors who are only looking, and naming a
 * cart in an ambient mode hands back somebody else's.
 *
 * @since  2.0.0
 */
enum CartResolution: string
{
    /** Look only: discover and adopt, but never create. */
    case Current = 'current';

    /** A change is coming: the operation that needs a cart may create one. */
    case Mutable = 'mutable';

    /** This exact cart: no discovery, no adoption, no creation. */
    case Named = 'named';

    /** A NEW cart, minted for a named customer: creation without discovery. */
    case Fresh = 'fresh';

    /**
     * @return bool Whether an operation under this mode may create a cart.
     *
     * @since  2.0.0
     */
    public function mayCreate(): bool
    {
        return $this === self::Mutable || $this === self::Fresh;
    }

    /**
     * @return bool Whether the shopper's own cart is discovered, and a guest
     *              cart left in this browser adopted along with it.
     *
     * @since  2.0.0
     */
    public function discovers(): bool
    {
        return $this !== self::Named && $this !== self::Fresh;
    }
}
