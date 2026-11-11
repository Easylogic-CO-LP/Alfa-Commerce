<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Summary;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;

\defined('_JEXEC') or die;

/**
 * One labelled line of a money summary, ready to print.
 *
 * The cart.summary layout prints these rows and decides nothing: which rows exist,
 * what each is called and every figure arrive prepared by the builder that read them.
 *
 * @since  2.2.0
 */
final class SummaryRow
{
    /** @since 2.2.0 */
    public const SUBTOTAL = 'subtotal';

    /** @since 2.2.0 */
    public const TAX = 'tax';

    /** @since 2.2.0 */
    public const DISCOUNT = 'discount';

    /** @since 2.2.0 */
    public const SHIPPING = 'shipping';

    /** @since 2.2.0 */
    public const CONTRIBUTION = 'contribution';

    /** @since 2.2.0 */
    public const RULE = 'rule';

    /** @since 2.2.0 */
    public const TOTAL = 'total';

    /**
     * @param string $type One of the constants above; the layout's styling hook.
     * @param string $label The row's name: translated, or as the merchant or a plugin wrote it.
     * @param Money $amount The figure shown.
     * @param bool $reduction Printed as a reduction, with the minus sign.
     * @param Money|null $was A struck figure before a benefit, when there is one.
     * @param bool $free Shipping the rules made free: the word is printed, not the zero.
     * @param bool $later Shipping not chosen yet: the words "Chosen at checkout" are printed, not the zero.
     * @param string|null $removeCode The coupon code the row's remove control posts, or null for none.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly Money $amount,
        public readonly bool $reduction = false,
        public readonly ?Money $was = null,
        public readonly bool $free = false,
        public readonly bool $later = false,
        public readonly ?string $removeCode = null,
    ) {
    }
}
