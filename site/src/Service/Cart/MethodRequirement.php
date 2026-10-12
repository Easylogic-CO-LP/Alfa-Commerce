<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Whether a cart must name a shipping or payment method, and why.
 *
 * An empty method list answered two unrelated questions with one silence, and
 * placement could not tell them apart:
 *
 *   NOT APPLICABLE is a fact about the cart or the shop. Nothing in this cart
 *   needs carrying, or the shop takes no payment for it at all. No method is
 *   required, and none may be named either, because there is no list a name
 *   could have come from.
 *
 *   UNAVAILABLE is a fact about this submission. The dimension applies, but
 *   nothing currently serves it: no carrier reaches the address, no gateway
 *   works with the chosen carrier, no method is left after the operator policy.
 *   That is a refusal, never a licence to place without one.
 *
 *   REQUIRED is the ordinary case: choose one of the offered methods.
 *
 * The distinction is load-bearing. Reading UNAVAILABLE as NOT APPLICABLE is how
 * a shop that lost its last carrier keeps selling physical goods it cannot
 * ship; reading NOT APPLICABLE as permission is how a cart of downloads takes a
 * carrier id nobody offered it, and is charged carriage for it.
 *
 * @since  1.10.0
 */
enum MethodRequirement: string
{
    /** The dimension is not part of this checkout: the id must be absent. */
    case NotApplicable = 'not_applicable';

    /** It applies, but nothing serves this cart right now: refuse. */
    case Unavailable = 'unavailable';

    /** One of the offered methods must be named. */
    case Required = 'required';

    /**
     * @return bool Whether a method id must be named for this cart.
     *
     * @since  1.10.0
     */
    public function mustBeNamed(): bool
    {
        return $this === self::Required;
    }

    /**
     * @return bool Whether placing with no method at all is legitimate here.
     *
     * @since  1.10.0
     */
    public function permitsNone(): bool
    {
        return $this === self::NotApplicable;
    }
}
