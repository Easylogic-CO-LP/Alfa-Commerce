<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\Database\DatabaseInterface;

/**
 * Each cart rule's share of each order line (#__alfa_order_item_cart_rule), after the order is placed.
 *
 * Placement freezes the shares as the rules engine shared each rule out, and the order's rule row
 * (#__alfa_order_cart_rule, marked line_shares = 1) is their sum. The rules engine is never asked again: a change to a
 * line moves its shares by the line's own facts alone, and the rule rows are summed again from the shares.
 *
 *  - A quantity change rescales the line's shares with it: each share's gross by the new quantity over the old,
 *    rounded once, half away from zero; its net is that gross split at the line's stored tax rate; the tax is the
 *    difference. A quantity cut takes the line's part of the coupon down in proportion.
 *  - A removed line takes its shares with it (the database's cascade); the rule rows are summed again, and a rule whose
 *    every share is gone keeps its row at zero: the order still used the code.
 *
 * A rule row held as one order-wide figure (an order placed before shares were kept, or a shipping rule) is not
 * touched: nothing is shared out for it by guess.
 *
 * @since  2.3.0
 */
final class OrderRuleShares
{
    /**
     * The shares' table.
     *
     * @var string
     * @since 2.3.0
     */
    public const TABLE = '#__alfa_order_item_cart_rule';

    /**
     * A line's shares at its new quantity, then the order's rule rows summed again.
     *
     * @param DatabaseInterface $db The connection, inside the caller's transaction.
     * @param int $orderId The order.
     * @param int $lineId The order line.
     * @param int $oldQuantity The line's quantity before.
     * @param int $newQuantity Its quantity now.
     * @param Currency $currency The order's currency at its frozen scale.
     * @param float $taxRate The line's stored tax rate, a percentage.
     *
     * @since  2.3.0
     */
    public static function rescaleLine(
        DatabaseInterface $db,
        int $orderId,
        int $lineId,
        int $oldQuantity,
        int $newQuantity,
        Currency $currency,
        float $taxRate,
    ): void {
        if ($newQuantity === $oldQuantity || $oldQuantity <= 0 || $newQuantity < 0) {
            return;
        }

        $shares = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'value_tax_incl']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id_order_item') . ' = ' . $lineId),
        )->loadObjectList() ?: [];

        if ($shares === []) {
            return;
        }

        foreach ($shares as $share) {
            $gross = self::scaled(stored: $share->value_tax_incl, oldQuantity: $oldQuantity, newQuantity: $newQuantity, currency: $currency);
            $net = OrderLineMoney::netAtStoredRate(gross: $gross, taxRate: $taxRate);
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(self::TABLE))
                    ->set($db->quoteName('value_tax_incl') . ' = ' . $db->quote(StoredDecimal::of($gross)))
                    ->set($db->quoteName('value_tax_excl') . ' = ' . $db->quote(StoredDecimal::of($net)))
                    ->where($db->quoteName('id') . ' = ' . (int) $share->id),
            )->execute();
        }

        self::resum(db: $db, orderId: $orderId);
    }

    /**
     * The order's rule rows held as shares are the sums of their shares again; the other rule rows are left as they
     * are.
     *
     * @param DatabaseInterface $db The connection, inside the caller's transaction.
     * @param int $orderId The order.
     *
     * @since  2.3.0
     */
    public static function resum(DatabaseInterface $db, int $orderId): void
    {
        $sums = $db->getQuery(true)
            ->select([
                $db->quoteName('id_order_cart_rule'),
                'SUM(' . $db->quoteName('value_tax_incl') . ') AS ' . $db->quoteName('incl'),
                'SUM(' . $db->quoteName('value_tax_excl') . ') AS ' . $db->quoteName('excl'),
            ])
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('id_order') . ' = ' . $orderId)
            ->group($db->quoteName('id_order_cart_rule'));

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__alfa_order_cart_rule', 'r')
            . ' LEFT JOIN (' . $sums . ') AS ' . $db->quoteName('s')
            . ' ON ' . $db->quoteName('s.id_order_cart_rule') . ' = ' . $db->quoteName('r.id_order_cart_rule')
            . ' SET ' . $db->quoteName('r.value_tax_incl') . ' = COALESCE(' . $db->quoteName('s.incl') . ', 0),'
            . ' ' . $db->quoteName('r.value_tax_excl') . ' = COALESCE(' . $db->quoteName('s.excl') . ', 0),'
            . ' ' . $db->quoteName('r.value_tax') . ' = COALESCE(' . $db->quoteName('s.incl') . ', 0) - COALESCE(' . $db->quoteName('s.excl') . ', 0)'
            . ' WHERE ' . $db->quoteName('r.id_order') . ' = ' . $orderId
            . ' AND ' . $db->quoteName('r.line_shares') . ' = 1',
        )->execute();
    }

    /**
     * A stored amount at a new quantity: times the new quantity over the old, on integers, rounded once, half away from
     * zero, as a line's own amounts are rescaled ({@see OrderLineMoney::rescaledForQuantity()}).
     *
     * @param mixed $stored The stored amount.
     * @param int $oldQuantity The quantity it was for.
     * @param int $newQuantity The quantity it is for now.
     * @param Currency $currency The order's currency.
     *
     * @since  2.3.0
     */
    private static function scaled(mixed $stored, int $oldQuantity, int $newQuantity, Currency $currency): Money
    {
        $numerator = Money::of($stored, $currency)->getMinorUnits() * $newQuantity;
        $quotient = intdiv($numerator, $oldQuantity);
        $remainder = $numerator - $quotient * $oldQuantity;

        if (2 * abs($remainder) >= $oldQuantity) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return Money::ofMinor($quotient, $currency);
    }
}
