<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;

defined('_JEXEC') or die;

/**
 * What the cart rules concluded, as of one observation.
 *
 * @since  2.0.0
 */
final class CartRulesView
{
    /**
     * @param object[] $applied The rules that are taking money off.
     * @param string[] $codes The coupon codes the cart holds.
     * @param array $progress Rules the shopper is close to earning.
     * @param Money $total What the rules came to.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $codes,
        public readonly array $progress,
        public readonly Money $total,
    ) {
    }
}
