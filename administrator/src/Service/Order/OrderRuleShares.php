<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\CartRules\AppliedCartRule;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

/**
 * Each cart rule's share of each order line (#__alfa_order_item_cart_rule), after the order is placed.
 *
 * A rule is frozen on an order with its shares as the rules engine shared it out (freeze(): at placement, and when a
 * coupon is added to a placed order), and the order's rule row (#__alfa_order_cart_rule, marked line_shares = 1) is
 * their sum. The rules engine is not asked again about a frozen rule: a change to a line moves its shares by the line's
 * own facts alone, and the rule rows are summed again from the shares.
 *
 *  - A quantity change rescales the line's shares with it: each share's gross by the new quantity over the old,
 *    rounded once, half away from zero; its net is that gross split at the line's stored tax rate; the tax is the
 *    difference. A quantity cut takes the line's part of the coupon down in proportion.
 *  - A removed line takes its shares with it (the database's cascade); the rule rows are summed again, and a rule whose
 *    every share is gone keeps its row at zero: the order still used the code.
 *
 * A rule row held as one order-wide figure (an order placed before shares were kept, or a shipping rule) is not
 * touched: nothing is shared out for it by guess. A coupon removed from the order keeps its row (marked deleted) and its
 * shares as they were when it was removed: the documents that stated it read them.
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
     * Freeze one applied rule on an order: its row, and its share of each line it took money off.
     *
     * Each figure is the exact decimal of the engine's Money; the row's tax is the difference of its two figures. A
     * rule with shares is marked line_shares = 1 and its row is their sum; a shipping rule has none.
     *
     * @param DatabaseInterface $db The connection, inside the caller's transaction.
     * @param int $orderId The order.
     * @param AppliedCartRule $applied The rule as the engine applied it.
     * @param array<int, int> $orderLineOf The order line of each line id the engine was given.
     * @param Currency $currency The currency the engine computed in.
     *
     * @return int The order's rule row (#__alfa_order_cart_rule).
     *
     * @throws RuntimeException When a share names a line with no order line.
     *
     * @since  2.3.0
     */
    public static function freeze(DatabaseInterface $db, int $orderId, AppliedCartRule $applied, array $orderLineOf, Currency $currency): int
    {
        $incl = $applied->discountTaxInclMoney($currency);
        $row = (object) [
            'id_order' => $orderId,
            'id_cart_rule' => $applied->id,
            'name' => $applied->name,
            // NULL for an automatic rule; the canonical code otherwise.
            'code' => $applied->code,
            'value_tax_excl' => StoredDecimal::of($applied->discountTaxExcl),
            // The discount's own money, never derived from the items' rates afterwards.
            'value_tax_incl' => StoredDecimal::of($incl),
            'value_tax' => StoredDecimal::of($incl->subtract($applied->discountTaxExcl)),
            // A shipping benefit is told from an order discount by its class.
            'discount_class' => $applied->discountClass,
            'free_shipping' => $applied->freeShipping ? 1 : 0,
            'line_shares' => $applied->lineShares !== [] ? 1 : 0,
            'deleted' => 0,
        ];
        $db->insertObject('#__alfa_order_cart_rule', $row, 'id_order_cart_rule');
        $ruleId = (int) $row->id_order_cart_rule;

        foreach ($applied->lineShares as $share) {
            if (!isset($orderLineOf[$share->lineId])) {
                throw new RuntimeException('its share of line ' . $share->lineId . ' has no order line');
            }

            $shareRow = (object) [
                'id_order' => $orderId,
                'id_order_item' => $orderLineOf[$share->lineId],
                'id_order_cart_rule' => $ruleId,
                'value_tax_excl' => StoredDecimal::of($share->taxExcl),
                'value_tax_incl' => StoredDecimal::of($share->taxIncl),
            ];
            $db->insertObject(self::TABLE, $shareRow);
        }

        return $ruleId;
    }

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

        // The shares of the rules the order holds; a removed coupon's stay as they were.
        $shares = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['s.id', 's.value_tax_incl']))
                ->from($db->quoteName(self::TABLE, 's'))
                ->join('INNER', $db->quoteName('#__alfa_order_cart_rule', 'r'), $db->quoteName('r.id_order_cart_rule') . ' = ' . $db->quoteName('s.id_order_cart_rule'))
                ->where($db->quoteName('s.id_order_item') . ' = ' . $lineId)
                ->where($db->quoteName('r.deleted') . ' = 0'),
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
     * The order's rule rows held as shares are the sums of their shares again; the other rule rows, and those of
     * removed coupons, are left as they are.
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
            . ' AND ' . $db->quoteName('r.line_shares') . ' = 1'
            . ' AND ' . $db->quoteName('r.deleted') . ' = 0',
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
