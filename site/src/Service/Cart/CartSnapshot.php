<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\User\User;

defined('_JEXEC') or die;

/**
 * A cart as something to look at.
 *
 * A view is not handed the cart. The cart can add a line, take a coupon,
 * choose a carrier, empty itself and delete its own row, and a view holding
 * it could do any of that between two reads that then belonged to different
 * moments.
 *
 * This is one observation of a cart, assembled once and then fixed. It holds no
 * repository, no locator, no helper, no application, and no closure that could
 * reach back into any of them, so nothing read through it can change what it
 * describes.
 *
 * What it deliberately does NOT carry:
 *
 *   THE RAW CART ROW. The row is the repository's, and every write replaces
 *   the reading through the hydrator, so the fields a view actually needs are
 *   copied out and the row itself stays behind.
 *
 *   THE OFFERED METHODS THE CART-VIEW EVENTS DECORATE. Those are the very
 *   objects the payment and shipment plugins graft their layouts onto, and the
 *   selector templates read the graft back off them. Offers here are a read
 *   projection; a selector that needs the decorated ones still asks the cart.
 *
 * @since  1.10.0
 */
final class CartSnapshot
{
    /**
     * @param int $cartId The cart this describes, or 0 for none.
     * @param object[] $lines The hydrated lines, as the cart page reads them.
     * @param int $itemCount How many lines.
     * @param int $quantity How many units across them.
     * @param bool $isVirtual Nothing here needs shipping.
     * @param Currency $currency What it is priced in.
     * @param CartTotalsView $totals What it costs.
     * @param CartRulesView $rules What the rules concluded.
     * @param CartOffersView $offers What may carry and pay for it.
     * @param User $customer Whose cart it is.
     * @param int|null $placeId The delivery place fixed for this request.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly int $cartId,
        public readonly array $lines,
        public readonly int $itemCount,
        public readonly int $quantity,
        public readonly bool $isVirtual,
        public readonly Currency $currency,
        public readonly CartTotalsView $totals,
        public readonly CartRulesView $rules,
        public readonly CartOffersView $offers,
        public readonly User $customer,
        public readonly ?int $placeId,
    ) {
    }

    /**
     * @return bool Whether there is nothing in it.
     *
     * @since  1.10.0
     */
    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * @return bool Whether a cart was found at all.
     *
     * @since  1.10.0
     */
    public function exists(): bool
    {
        return $this->cartId > 0;
    }
}
