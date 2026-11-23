<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Site\Service\Summary\SummaryRow;
use Joomla\CMS\Component\ComponentHelper;

defined('_JEXEC') or die;

/**
 * Which amounts the cart shows the shopper, and whether it offers the coupon field: the one reader of the six switches
 * in the component Options (Cart, The cart shows), each on by default.
 *
 * The cart page with its checkout summary, the cart module and the added-to-cart card read them here. Line price is
 * a line's price, discount and total; Subtotal is the subtotal row and the discount row under it; Shipping is the
 * shipping row; Tax is a line's tax and the tax row; Total is the total row with the fees and the cart rules that
 * lead to it. With every amount switch off the cart lists its lines and their quantities only. Coupon field is the
 * cart page's coupon form, the one storefront way to enter a code: off, the storefront takes no code (a code the cart
 * already holds keeps its remove button). Only the showing changes: the
 * cart, its totals, the placement and the order carry every amount, and the order's own pages, e-mails and documents
 * show them.
 *
 * @since  2.3.0
 */
final class CartShows
{
    /**
     * @param bool $linePrice A line's price, discount and total.
     * @param bool $subtotal The subtotal row and the discount row under it.
     * @param bool $shipping The shipping row.
     * @param bool $tax A line's tax and the tax row.
     * @param bool $total The total row, with the fee and cart rule rows.
     * @param bool $coupon The coupon field.
     *
     * @since  2.3.0
     */
    private function __construct(
        public readonly bool $linePrice,
        public readonly bool $subtotal,
        public readonly bool $shipping,
        public readonly bool $tax,
        public readonly bool $total,
        public readonly bool $coupon,
    ) {
    }

    /**
     * The switches as the shop set them, from the component's own Options.
     *
     * @since  2.3.0
     */
    public static function options(): self
    {
        $params = ComponentHelper::getParams('com_alfa');
        $on = static fn (string $name): bool => (int) $params->get($name, 1) === 1;

        return new self(
            linePrice: $on('cart_show_line_price'),
            subtotal: $on('cart_show_subtotal'),
            shipping: $on('cart_show_shipping'),
            tax: $on('cart_show_tax'),
            total: $on('cart_show_total'),
            coupon: $on('coupon_field'),
        );
    }

    /**
     * The summary rows these switches show, in their order.
     *
     * @param SummaryRow[] $rows The rows, in display order.
     *
     * @return SummaryRow[]
     *
     * @since  2.3.0
     */
    public function rows(array $rows): array
    {
        return array_values(array_filter($rows, fn (SummaryRow $row): bool => $this->shows(row: $row)));
    }

    /**
     * Whether a summary row is shown.
     *
     * @param SummaryRow $row The row.
     *
     * @since  2.3.0
     */
    public function shows(SummaryRow $row): bool
    {
        return match ($row->type) {
            SummaryRow::SUBTOTAL, SummaryRow::DISCOUNT => $this->subtotal,
            SummaryRow::TAX => $this->tax,
            SummaryRow::SHIPPING => $this->shipping,
            SummaryRow::CONTRIBUTION, SummaryRow::RULE, SummaryRow::TOTAL => $this->total,
        };
    }
}
