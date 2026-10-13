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
 * Who created a cart, as `#__alfa_cart.source` records it.
 *
 * The words are the orders table's ({@see \Alfa\Component\Alfa\Site\Service\Checkout\PlacementCommand}
 * SOURCE_STOREFRONT and SOURCE_ADMIN). The cart row stores one of them once,
 * when it is created, and nothing rewrites it: opening a shopper's cart in the
 * back office leaves it a shopper's cart.
 *
 * The column is a varchar rather than an enum, so a later source (a renewal, a
 * marketplace) is a new case here and no schema change at all.
 *
 * @since  2.0.0
 */
enum CartSource: string
{
    /** A shopper started it, in their own session. */
    case Storefront = 'storefront';

    /** An operator started it in the order builder. */
    case Admin = 'admin';

    /**
     * The source a stored value names. Anything unknown or empty reads as a
     * shopper's cart, so an unrecognised row never gains builder semantics.
     *
     * @param string|null $value The column value.
     *
     *
     * @since  2.0.0
     */
    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Storefront;
    }

    /**
     * Whether an operator also needs Carts: Edit to change or place it.
     *
     *
     * @since  2.0.0
     */
    public function requiresTakeover(): bool
    {
        return $this !== self::Admin;
    }
}
