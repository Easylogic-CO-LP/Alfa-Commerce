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
 * Whose cart this is.
 *
 * A cart has exactly one owner and there are exactly two kinds. A MEMBER is
 * identified by their account; a GUEST by a recognition key that lives in a
 * cookie. The two are not additive and never both apply: a signed-in shopper's
 * cart is found by their account even when the browser still carries a key from
 * before they signed in, because the cookie outlives the session and the person
 * at a shared machine is not the person who left it there.
 *
 * That precedence used to be a pair of `if ($this->user->id > 0)` branches
 * written out three times (in the cart lookup and twice in adoption), each
 * carrying a comment saying it had to agree with the others. It is one rule, so
 * it is stated once, here.
 *
 * THE KEY'S SHAPE IS PART OF THE IDENTITY. It is written verbatim into a
 * char(64) column, so a tampered, truncated or over-length value silently
 * truncates on write and can never match on read: a new cart row on every page
 * view, unbounded, on the table this schema indexes hardest. A value that is not
 * exactly 64 lowercase hex characters identifies nobody.
 *
 * @since  2.0.0
 */
final class CartOwner
{
    /**
     * The shape a recognition key must have to identify anyone.
     *
     * @since  2.0.0
     */
    public const KEY_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @param int $customerId The account, or 0 for a guest.
     * @param string $recognizeKey The recognition key, or '' for none.
     *
     * @since  2.0.0
     */
    private function __construct(private int $customerId, private string $recognizeKey)
    {
    }

    /**
     * A signed-in shopper.
     *
     * @param int $customerId The account.
     *
     *
     * @since  2.0.0
     */
    public static function member(int $customerId): self
    {
        return new self(max(0, $customerId), '');
    }

    /**
     * A visitor carrying a recognition key. A key of the wrong shape identifies
     * nobody, so it produces an anonymous owner rather than a broken one.
     *
     * @param string $recognizeKey The key from the cookie.
     *
     *
     * @since  2.0.0
     */
    public static function guest(string $recognizeKey): self
    {
        return new self(0, self::isWellFormedKey($recognizeKey) ? $recognizeKey : '');
    }

    /**
     * Nobody: a first-time visitor who has not been given a key yet.
     *
     *
     * @since  2.0.0
     */
    public static function nobody(): self
    {
        return new self(0, '');
    }

    /**
     * The owner behind a request that has both an identity and a cookie.
     *
     * THE ACCOUNT WINS. This is the whole precedence rule, and the reason it
     * exists: the cookie survives sign-out, so honouring it for a signed-in
     * shopper would hand them the cart of whoever used the browser before.
     *
     * @param int $customerId The account id, or 0 when not signed in.
     * @param string $recognizeKey The key the browser presented.
     *
     *
     * @since  2.0.0
     */
    public static function of(int $customerId, string $recognizeKey): self
    {
        return $customerId > 0 ? self::member($customerId) : self::guest($recognizeKey);
    }

    /**
     * Is this value usable as a recognition key at all?
     *
     * @param string $value The candidate.
     *
     *
     * @since  2.0.0
     */
    public static function isWellFormedKey(string $value): bool
    {
        return preg_match(self::KEY_PATTERN, $value) === 1;
    }

    /**
     * Does this owner identify anyone? A first-time visitor does not, and has
     * no cart to find.
     *
     *
     * @since  2.0.0
     */
    public function isIdentified(): bool
    {
        return $this->customerId > 0 || $this->recognizeKey !== '';
    }

    /**
     * @return bool True when the owner is an account rather than a cookie.
     *
     * @since  2.0.0
     */
    public function isMember(): bool
    {
        return $this->customerId > 0;
    }

    /**
     * @return int The account, or 0 for a guest.
     *
     * @since  2.0.0
     */
    public function customerId(): int
    {
        return $this->customerId;
    }

    /**
     * @return string The recognition key, or '' when the owner is a member or nobody.
     *
     * @since  2.0.0
     */
    public function recognizeKey(): string
    {
        return $this->recognizeKey;
    }
}
