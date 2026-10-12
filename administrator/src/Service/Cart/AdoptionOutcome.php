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
 * What adoption did.
 *
 * Adoption can delete a cart row. It used to say so by assigning a property on
 * the object that called it, which meant the only way to learn that a row had
 * gone was to have read the id before and to read it again after. An operation
 * that destroys persistent state has to be able to state what it destroyed.
 *
 * Four shapes, and the caller can tell them apart:
 *
 *   NOTHING HAPPENED - no member, no key, or this browser was already settled.
 *   NOTHING TO ADOPT - the key names no cart.
 *   CLAIMED - a guest cart became the member's, in place, keeping its id.
 *   MERGED - the guest cart's contents moved to the member's own and the
 *            emptied row was deleted; movedLines names what has to be
 *            re-judged against the pool the two now share.
 *
 * @since  1.10.0
 */
final class AdoptionOutcome
{
    /**
     * @param bool $adopted Whether a cart changed hands.
     * @param int $survivingId The cart the member holds afterwards, or 0.
     * @param int $deletedId The row adoption destroyed, or 0.
     * @param object[] $movedLines Lines that changed cart and must be
     *                             re-judged against their new neighbours.
     *
     * @since  1.10.0
     */
    private function __construct(
        public readonly bool $adopted,
        public readonly int $survivingId,
        public readonly int $deletedId,
        public readonly array $movedLines,
    ) {
    }

    /**
     * @return self Adoption did not run, or found nothing to run on.
     *
     * @since  1.10.0
     */
    public static function nothing(): self
    {
        return new self(false, 0, 0, []);
    }

    /**
     * @param int $cartId The guest cart the member now owns, unchanged in place.
     *
     *
     * @since  1.10.0
     */
    public static function claimed(int $cartId): self
    {
        return new self(true, max(0, $cartId), 0, []);
    }

    /**
     * @param int $survivingId The member's own cart, which kept its id.
     * @param int $deletedId The emptied guest row, now gone.
     * @param object[] $movedLines The lines that moved across.
     *
     *
     * @since  1.10.0
     */
    public static function merged(int $survivingId, int $deletedId, array $movedLines): self
    {
        return new self(true, max(0, $survivingId), max(0, $deletedId), array_values($movedLines));
    }

    /**
     * @return bool Whether a row was destroyed.
     *
     * @since  1.10.0
     */
    public function deletedACart(): bool
    {
        return $this->deletedId > 0;
    }

    /**
     * @return bool Whether anything needs re-judging against the shared pool.
     *
     * @since  1.10.0
     */
    public function needsReclamp(): bool
    {
        return $this->movedLines !== [];
    }
}
