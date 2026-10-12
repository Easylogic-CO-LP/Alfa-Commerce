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
 * Which cart, and under what terms.
 *
 * The answer to "which cart is this" used to be a mutable integer that moved
 * three times during a single request (set from an argument, replaced by
 * discovery, replaced again by adoption), so no caller could hold it and no
 * reader could tell which of those had happened. This is that answer as a
 * value: settled, and carrying enough to say how it was reached.
 *
 * Identity facts ONLY. No lines, no totals, no offered methods, no addresses,
 * no application state; those belong to whatever hydrates this, not to the
 * question of which row it is.
 *
 * @since  1.10.0
 */
final class CartIdentity
{
    /**
     * @param int $cartId The resolved cart, or 0 when there is
     *                    none and none was created.
     * @param string $recognizeKey The recognition key this browser
     *                             carries; '' for a member with none.
     * @param CartResolution $mode How the caller asked.
     * @param int $namedCartId The id the caller named, or 0. Never
     *                         moves, so "did somebody name a cart"
     *                         stays answerable after discovery.
     * @param AdoptionOutcome $adoption What adoption did on the way here:
     *                                  an identity fact, because it is how
     *                                  this identity came to mean this row,
     *                                  and it names the row it destroyed.
     *
     * @since  1.10.0
     */
    private function __construct(
        public readonly int $cartId,
        public readonly string $recognizeKey,
        public readonly CartResolution $mode,
        public readonly int $namedCartId,
        public readonly AdoptionOutcome $adoption,
    ) {
    }

    /**
     * @param int $cartId The resolved cart, or 0.
     * @param string $recognizeKey The recognition key.
     * @param CartResolution $mode How the caller asked.
     * @param int $namedCartId The id the caller named, or 0.
     * @param AdoptionOutcome|null $adoption What adoption did, if it ran.
     *
     *
     * @since  1.10.0
     */
    public static function of(
        int $cartId,
        string $recognizeKey,
        CartResolution $mode,
        int $namedCartId = 0,
        ?AdoptionOutcome $adoption = null,
    ): self {
        return new self(
            max(0, $cartId),
            $recognizeKey,
            $mode,
            max(0, $namedCartId),
            $adoption ?? AdoptionOutcome::nothing(),
        );
    }

    /**
     * The same identity pointing at a different row.
     *
     * Adoption and creation both move which cart is meant, and both must say so
     * rather than reach into whoever is holding this.
     *
     * @param int $cartId The row it now means.
     *
     *
     * @since  1.10.0
     */
    public function at(int $cartId): self
    {
        return new self(max(0, $cartId), $this->recognizeKey, $this->mode, $this->namedCartId, $this->adoption);
    }

    /**
     * @param string $key The key this browser now carries.
     *
     *
     * @since  1.10.0
     */
    public function withRecognizeKey(string $key): self
    {
        return new self($this->cartId, $key, $this->mode, $this->namedCartId, $this->adoption);
    }

    /**
     * @return bool Whether a cart was found or made.
     *
     * @since  1.10.0
     */
    public function exists(): bool
    {
        return $this->cartId > 0;
    }

    /**
     * @return bool Whether the caller named this exact cart and got it.
     *
     * @since  1.10.0
     */
    public function isNamed(): bool
    {
        return $this->namedCartId > 0 && $this->cartId === $this->namedCartId;
    }
}
