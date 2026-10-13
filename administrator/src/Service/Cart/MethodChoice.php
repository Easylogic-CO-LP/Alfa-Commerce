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
 * What to do with a chosen shipping or payment method when the offer changes.
 *
 * The rule is two sentences and it is the same rule for both kinds:
 *
 *   A CHOICE THAT IS NO LONGER OFFERED IS DROPPED. Whatever the merchant has
 *   changed (a method retired, a restriction added, an address that moved the
 *   cart out of a zone), a cart must never carry a method the shopper cannot
 *   actually use to the point of payment.
 *
 *   A KIND WITH EXACTLY ONE OFFER CHOOSES IT. Asking someone to pick from a
 *   list of one is not a choice, and leaving it unpicked blocks placement.
 *
 * Both can apply at once: a dropped method leaves nothing chosen, and if only
 * one other is offered, that one is taken. That is why this reports two things
 * rather than one: it is what the cart used to express as two `if` blocks
 * written out twice, once per kind, with the order between them load-bearing
 * and unstated.
 *
 * Pure. It knows the offer and the choice, and nothing about carts, requests or
 * databases, which is what makes the rule testable without any of them.
 *
 * @since  2.0.0
 */
final class MethodChoice
{
    /**
     * @param bool $dropCurrent The chosen method is no longer offered.
     * @param int|null $chooseInstead The only remaining offer, when there is
     *                                exactly one and nothing is chosen.
     *
     * @since  2.0.0
     */
    private function __construct(
        public readonly bool $dropCurrent,
        public readonly ?int $chooseInstead,
    ) {
    }

    /**
     * Decide, given what is offered and what is chosen.
     *
     * @param array $offered The methods on offer, keyed by id.
     * @param int $chosen The method the cart currently carries, or 0.
     *
     *
     * @since  2.0.0
     */
    public static function reconcile(array $offered, int $chosen): self
    {
        $drop = $chosen > 0 && !isset($offered[$chosen]);
        $standing = $drop ? 0 : $chosen;

        return new self(
            $drop,
            $standing === 0 && \count($offered) === 1 ? (int) array_key_first($offered) : null,
        );
    }

    /**
     * @return bool True when nothing needs writing at all.
     *
     * @since  2.0.0
     */
    public function isSettled(): bool
    {
        return !$this->dropCurrent && $this->chooseInstead === null;
    }
}
