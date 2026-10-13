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
 * What a visitor put in a cart is theirs across signing in.
 *
 * Adoption is the one moment both identities exist at once: an account to give
 * the cart to, and a key saying which cart to give. Both are the ACTOR's: it
 * is the actor's browser that carries the cookie, and a cart abandoned in that
 * browser is the actor's to claim, never the customer's on whose behalf they
 * might be working.
 *
 * Three arms, and which one runs depends only on what exists:
 *
 *   NOTHING TO ADOPT - the key names no cart. Settled for the session.
 *
 *   CLAIM IN PLACE - the member has no cart of their own, so the guest row
 *   survives with its id and its lines, selections and held codes stay attached
 *   with nothing to move. The repository's update is conditional on the cart
 *   still being unowned, so the loser of a race changes nothing rather than
 *   taking a cart twice.
 *
 *   MERGE - both carts exist. The guest cart's contents move to the member's
 *   and the emptied guest row goes. Identical configurations stay separate
 *   lines; merging quantities is the add-to-cart path's rule, not this one's.
 *   Order is the whole point and the repository owns it: the lines move, then
 *   the codes, and only then is the guest row deleted, because that delete
 *   cascades onto the coupon table.
 *
 * What it does NOT do: re-judge the moved lines. Two carts each respected the
 * stock; one cart must too, but that decision needs the catalogue and speaks to
 * a shopper, so the moved lines are reported and whoever holds the cart applies
 * the quantity policy to them.
 *
 * @since  2.0.0
 */
final class GuestCartAdoption
{
    /**
     * @param CartRepository $repository The only path to the cart tables.
     * @param AdoptionMemory $memory Whether this session already tried.
     *
     * @since  2.0.0
     */
    public function __construct(
        private CartRepository $repository,
        private AdoptionMemory $memory,
    ) {
    }

    /**
     * Give this browser's guest cart to the member who just signed in.
     *
     * @param int $memberId The actor's account, or 0 for a guest.
     * @param string $recognizeKey The key the actor's browser carries.
     *
     * @return AdoptionOutcome What happened, including the row it destroyed.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  2.0.0
     */
    public function adopt(int $memberId, string $recognizeKey): AdoptionOutcome
    {
        if ($memberId <= 0 || !CartOwner::isWellFormedKey($recognizeKey)) {
            return AdoptionOutcome::nothing();
        }

        if ($this->memory->settled($memberId, $recognizeKey)) {
            return AdoptionOutcome::nothing();
        }

        // The very cart the visitor was shown: same predicate, same tie-break
        // as the lookup that showed it.
        $guestCartId = $this->repository->findCartIdFor(CartOwner::guest($recognizeKey));

        if ($guestCartId <= 0) {
            $this->memory->settle($memberId, $recognizeKey);

            return AdoptionOutcome::nothing();
        }

        // Never the row being adopted: a concurrent request that claimed it
        // first must not be seen here as "the member's own cart", or the merge
        // below would delete the cart it had just been given.
        $ownCartId = $this->repository->findCartIdFor(
            CartOwner::member($memberId),
            excludeCartId: $guestCartId,
        );

        if ($ownCartId <= 0) {
            $this->repository->claimCart($guestCartId, $memberId);
            $this->memory->settle($memberId, $recognizeKey);

            return AdoptionOutcome::claimed($guestCartId);
        }

        $moved = $this->repository->mergeCartInto($guestCartId, $ownCartId);
        $this->memory->settle($memberId, $recognizeKey);

        return AdoptionOutcome::merged($ownCartId, $guestCartId, $moved);
    }
}
