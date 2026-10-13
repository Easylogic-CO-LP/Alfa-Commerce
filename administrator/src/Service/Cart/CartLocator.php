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
 * Which cart this request is about.
 *
 * The question has three answers and they are not interchangeable (a look, an
 * intent to change, and an id somebody supplied), so the mode is asked for
 * rather than guessed. What comes back is a settled {@see CartIdentity} saying
 * which row is meant and what adoption did on the way to deciding.
 *
 * NARROW ON PURPOSE. It resolves identity and nothing else: no totals, no
 * quantity rules, no offered methods, no plugin dispatch, no messages, and it
 * does not hydrate a cart. Those belong to whoever holds the identity.
 *
 * A NAMED CART IS NOT A CART BEING DISCOVERED. When the caller has already said
 * which cart they mean there is nothing to discover, and adopting anyway is not
 * merely redundant but destructive: adoption's merge arm moves the identity to
 * the actor's own cart, and the cart that WAS named is the one the merge
 * deletes. A recognition cookie is set on path "/", so an operator who also
 * shops brings one to every screen.
 *
 * @since  2.0.0
 */
final class CartLocator
{
    /**
     * @param GuestCartAdoption $adoption Gives this browser's guest cart to the
     *                                    member who just signed in.
     *
     * @since  2.0.0
     */
    public function __construct(private GuestCartAdoption $adoption)
    {
    }

    /**
     * Settle which cart is meant.
     *
     * @param CartSubject $subject Who the cart is for and who is
     *                             operating it.
     * @param CartResolution $mode A look, an intent to change, or a
     *                             named cart.
     * @param int $namedCartId The cart the caller named, or 0.
     * @param string $recognizeKey The key this browser carries.
     *
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  2.0.0
     */
    public function resolve(
        CartSubject $subject,
        CartResolution $mode,
        int $namedCartId = 0,
        string $recognizeKey = '',
    ): CartIdentity {
        if ($namedCartId > 0 || !$mode->discovers()) {
            return CartIdentity::of($namedCartId, $recognizeKey, $mode, $namedCartId);
        }

        $outcome = $this->adoption->adopt($subject->actorId(), $recognizeKey);

        // ONLY THE MERGE MOVES THE IDENTITY. A cart CLAIMED in place keeps its
        // id and is now owned by the member, so the ordinary owner lookup finds
        // it exactly as it finds any other cart of theirs; naming it here
        // would answer through a different predicate than the one that decides
        // whose cart is whose. A merge is different: the row the member ends up
        // with is not the one the key pointed at, and nothing else knows that.
        return CartIdentity::of(
            $outcome->deletedACart() ? $outcome->survivingId : 0,
            $recognizeKey,
            $mode,
            0,
            $outcome,
        );
    }
}
