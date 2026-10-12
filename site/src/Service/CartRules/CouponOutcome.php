<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\CartRules;

defined('_JEXEC') or die;

/**
 * Why a coupon code did or did not take.
 *
 * The engine knows the difference between "no such code", "you already have a
 * better discount" and "you need to be logged in"; it just used to throw that
 * away and hand the storefront a bare false, which became one misleading
 * sentence for every case. These are the reasons; the presentation layer
 * decides which are safe to show and in what words.
 *
 * STORED outcomes keep the code ON THE CART (#__alfa_cart_coupons): the rule is
 * real and the customer's entry was legitimate, so the progress surface can
 * explain what is still missing. Everything else is refused outright.
 *
 * @since  1.9.0
 */
final class CouponOutcome
{
    /** The code activated a rule that is applying right now. */
    public const APPLIED = 'applied';

    /** Real code, real rule, conditions simply not met yet; kept, with progress. */
    public const CONDITIONS_NOT_MET = 'conditions_not_met';

    /** No rule carries this code. */
    public const NOT_FOUND = 'not_found';

    /** The rule exists but is unpublished. Deliberately indistinguishable
     *  from NOT_FOUND to the customer (an unpublished code is not their
     *  business) but distinct here for the merchant's log. */
    public const UNPUBLISHED = 'unpublished';

    /** Its window has not opened yet. */
    public const NOT_YET_ACTIVE = 'not_yet_active';

    /** Its window has closed. */
    public const EXPIRED = 'expired';

    /** The rule's total redemption limit is used up. */
    public const USAGE_LIMIT_REACHED = 'usage_limit_reached';

    /** This customer has personally used it as often as allowed. */
    public const CUSTOMER_LIMIT_REACHED = 'customer_limit_reached';

    /** Per-customer limited, and the visitor is a guest: there is no identity
     *  to count against, so it cannot be offered rather than being unlimited. */
    public const REQUIRES_LOGIN = 'requires_login';

    /** Restricted to customers or groups this visitor is not in. */
    public const NOT_ELIGIBLE = 'not_eligible';

    /** Valid and eligible, but a better discount already applies and the two
     *  do not combine. Emphatically NOT "invalid". */
    public const NOT_BETTER_THAN_EXISTING = 'not_better_than_existing';

    /**
     * Outcomes that mean the code should stay on the cart.
     *
     * @var string[]
     *
     * @since  1.9.0
     */
    private const STORED = [self::APPLIED, self::CONDITIONS_NOT_MET];

    /**
     * Should a code with this outcome be held by the cart?
     *
     * @param string $outcome One of the constants above.
     *
     *
     * @since  1.9.0
     */
    public static function isStored(string $outcome): bool
    {
        return \in_array($outcome, self::STORED, true);
    }

    /**
     * The language key describing this outcome to a customer.
     *
     * UNPUBLISHED collapses onto NOT_FOUND on purpose: whether a code is
     * unknown or merely switched off is the merchant's business, not the
     * shopper's, and distinguishing them leaks which codes exist.
     *
     * @param string $outcome One of the constants above.
     *
     *
     * @since  1.9.0
     */
    public static function languageKey(string $outcome): string
    {
        if ($outcome === self::UNPUBLISHED) {
            $outcome = self::NOT_FOUND;
        }

        return 'COM_ALFA_CART_COUPON_' . strtoupper($outcome);
    }

    /**
     * The Joomla message type this outcome should be enqueued as.
     *
     * @param string $outcome One of the constants above.
     *
     * @return string 'message' | 'notice' | 'warning'
     *
     * @since  1.9.0
     */
    public static function messageType(string $outcome): string
    {
        return match ($outcome) {
            self::APPLIED => 'message',
            // Nothing went wrong in these two: the code is real and kept.
            self::CONDITIONS_NOT_MET, self::NOT_BETTER_THAN_EXISTING => 'notice',
            default => 'warning',
        };
    }
}
