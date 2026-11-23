<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Summary\SummaryRow;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * How a price of zero is printed for the shopper: its figure, nothing, or the "Call for price" text.
 *
 * One setting in the component Options (Cart, Checkout, `zero_price_display`): show (the figure, 0,00), hide, or
 * text (the language string COM_ALFA_PRICE_ON_REQUEST, which a language override words the shop's way). It applies to
 * the prices a shopper reads: a product's price on its page and its cards, a line's unit price and line total, the
 * subtotal and the total, in the storefront, the cart module, the order e-mails and the printed documents. Tax,
 * shipping, fees and discounts print their figures as they are. The figure itself never changes: the cart, the
 * order, the ledger and the REST API carry the zero; only its printing follows the setting.
 *
 * @since  2.3.0
 */
final class PriceDisplay
{
    /**
     * What a price of zero prints instead of its figure: null to print the figure, '' to print nothing, else the
     * shop's text.
     *
     * @since  2.3.0
     */
    public static function zero(): ?string
    {
        return match ((string) ComponentHelper::getParams('com_alfa')->get('zero_price_display', 'show')) {
            'hide' => '',
            'text' => Text::_('COM_ALFA_PRICE_ON_REQUEST'),
            default => null,
        };
    }

    /**
     * A price as the shopper reads it: its figure, or what the Options print for a price of zero.
     *
     * @param Money $price The price.
     *
     * @since  2.3.0
     */
    public static function price(Money $price): string
    {
        return self::figure(formatted: $price->format(), zero: $price->isZero());
    }

    /**
     * A price already formatted (a unit by the one unit rule, a document's figure at its frozen scale), as the
     * shopper reads it.
     *
     * @param string $formatted The figure as it would print.
     * @param bool $zero Whether the price is zero.
     *
     * @since  2.3.0
     */
    public static function figure(string $formatted, bool $zero): string
    {
        return $zero ? (self::zero() ?? $formatted) : $formatted;
    }

    /**
     * The summary rows without a subtotal or total of zero when the Options hide a price of zero: a row that would
     * print nothing is not drawn.
     *
     * @param SummaryRow[] $rows The rows, in display order.
     *
     * @return SummaryRow[]
     *
     * @since  2.3.0
     */
    public static function summary(array $rows): array
    {
        if (self::zero() !== '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (SummaryRow $row): bool => !(self::isPrice(row: $row) && $row->amount->isZero()),
        ));
    }

    /**
     * Whether a summary row states a price (the subtotal or the total), so it prints by this class's rule.
     *
     * @param SummaryRow $row The row.
     *
     * @since  2.3.0
     */
    public static function isPrice(SummaryRow $row): bool
    {
        return \in_array($row->type, [SummaryRow::SUBTOTAL, SummaryRow::TOTAL], true);
    }
}
