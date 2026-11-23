<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Site\Service\CartRules\AppliedCartRule;
use Alfa\Component\Alfa\Site\Service\CartRules\CartRulesEngine;
use Alfa\Component\Alfa\Site\Service\CartRules\CouponOutcome;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseDriver;
use RuntimeException;
use Throwable;

/**
 * Coupons on a placed order: a code added, a code put in place of another, a code removed.
 *
 * A code added is asked of the rules engine as the storefront asks it: the order's customer and customer groups, the
 * rule's window, targeting and usage limits, its conditions read on the order's lines as they stand. Only the code's
 * own rule is asked: the automatic rules were frozen when the order was placed and are not asked again. The rule
 * measures what each line has left after the rules already on the order (their frozen shares; a line one of them
 * discounts as a product rule is not discounted by another), and is frozen with its shares as placement freezes a rule
 * ({@see OrderRuleShares::freeze()}). It joins the rules of its class already on the order only when it and every one
 * of them combine with that class; otherwise it is refused and nothing on the order moves.
 *
 * A code removed keeps its row, marked deleted, and its shares as they were: the documents that stated it read them,
 * and the rule's usage no longer counts it. A code put in place of another is the removal and the addition in one
 * transaction: when the addition is refused, the code on the order stays.
 *
 * An order holding a product or order discount frozen before shares were kept is left as it is: its coupons are not
 * changed here.
 *
 * Documents issued before a change keep what they state. The next invoice states each line's share of the rules on
 * the order for the quantity it invoices; what an invoice issued before the change stated against the order now is
 * the correction flow's (a credit note grants a discount added, a sales document reverses one removed).
 *
 * Each change is made under the order's row lock (and the rule's, for a code added, so two redemptions of its last use
 * queue), written with its history entry, and commits whole or not at all.
 *
 * Usage: OrderCoupons::add(orderId: 42, code: 'WELCOME10') answers an object {outcome, rule, ruleId}; outcome is
 * CouponOutcome::APPLIED when the code was added, otherwise the reason it was not (a CouponOutcome constant or one of
 * this class's). OrderCoupons::remove(orderId: 42, orderRuleId: 7) answers self::REMOVED or the reason it did not.
 *
 * @since  2.3.0
 */
final class OrderCoupons
{
    /**
     * The code was removed from the order.
     *
     * @var string
     * @since 2.3.0
     */
    public const REMOVED = 'removed';

    /**
     * The code is already on the order.
     *
     * @var string
     * @since 2.3.0
     */
    public const ON_ORDER = 'on_order';

    /**
     * A rule of the code's class is on the order, and the two do not both combine with that class.
     *
     * @var string
     * @since 2.3.0
     */
    public const NOT_COMBINABLE = 'not_combinable';

    /**
     * The code's rule takes nothing off the order: the lines it covers are left out of it, or have nothing left.
     *
     * @var string
     * @since 2.3.0
     */
    public const NOTHING_OFF = 'nothing_off';

    /**
     * The order holds a discount frozen before shares were kept: its coupons stay as they are.
     *
     * @var string
     * @since 2.3.0
     */
    public const SHARES_NOT_KEPT = 'shares_not_kept';

    /**
     * The coupon named is not on the order (removed meanwhile, or never there).
     *
     * @var string
     * @since 2.3.0
     */
    public const GONE = 'gone';

    /**
     * Add a code to a placed order, or put it in place of a code the order holds.
     *
     * @param int $orderId The order.
     * @param string $code The code as typed.
     * @param int $replaces The coupon it replaces (#__alfa_order_cart_rule id), or 0 to add it.
     *
     * @return object{outcome: string, rule: ?AppliedCartRule, ruleId: int, replaced: ?string} The rule, its order row and
     *                                                                                    the code it replaced, when it
     *                                                                                    was added.
     *
     * @throws Throwable What stopped the write; nothing on the order moved.
     *
     * @since  2.3.0
     */
    public static function add(int $orderId, string $code, int $replaces = 0): object
    {
        $code = strtoupper(trim($code));
        $answer = static fn (string $outcome, ?AppliedCartRule $rule = null, int $ruleId = 0, ?string $replaced = null): object
            => (object) ['outcome' => $outcome, 'rule' => $rule, 'ruleId' => $ruleId, 'replaced' => $replaced];

        if ($code === '') {
            return $answer(CouponOutcome::NOT_FOUND);
        }

        $db = self::db();
        $currency = OrderCurrency::of(orderId: $orderId);
        $db->transactionStart(true);

        try {
            $order = self::lockOrder(db: $db, orderId: $orderId);
            // The code's rule is locked too: a redemption racing for its last use queues here, and the usage the
            // engine counts below is read after it.
            $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_cart_rules')
                . ' WHERE UPPER(' . $db->quoteName('code') . ') = ' . $db->quote($code) . ' FOR UPDATE',
            )->loadColumn();

            $held = self::heldRules(db: $db, orderId: $orderId);
            $refusal = match (true) {
                $replaces > 0 && (!isset($held[$replaces]) || $held[$replaces]->code === null) => self::GONE,
                self::sharesNotKept($held) => self::SHARES_NOT_KEPT,
                \in_array($code, array_map(
                    static fn (object $rule): string => strtoupper((string) $rule->code),
                    array_filter($held, static fn (object $rule): bool => (int) $rule->id_order_cart_rule !== $replaces),
                ), true) => self::ON_ORDER,
                default => null,
            };

            if ($refusal !== null) {
                TransactionState::rollBackLevel($db);

                return $answer($refusal);
            }

            $replaced = $replaces > 0 ? $held[$replaces] : null;
            $kept = array_filter($held, static fn (object $rule): bool => (int) $rule->id_order_cart_rule !== $replaces);
            $lines = self::lineSnapshot(db: $db, orderId: $orderId, without: $replaces, currency: $currency);
            $totalsBefore = OrderTotalHelper::getBreakdown($orderId);

            // The shipping the order's shipping rules leave, the replaced one's part given back.
            $shippingIncl = Money::of((float) $totalsBefore->shipping_tax_incl, $currency);
            $shippingExcl = Money::of((float) $totalsBefore->shipping_tax_excl, $currency);

            if ($replaced !== null && $replaced->discount_class === 'shipping') {
                $shippingIncl = $shippingIncl->add(Money::of((float) $replaced->value_tax_incl, $currency));
                $shippingExcl = $shippingExcl->add(Money::of((float) $replaced->value_tax_excl, $currency));
            }

            $customer = (int) ($order->id_user ?? 0) > 0
                ? Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $order->id_user)
                : new User();
            $result = $lines === []
                ? null
                : (new CartRulesEngine())->evaluate($lines, (object) [
                    'userId' => (int) ($customer->id ?? 0),
                    'userGroups' => PriceContext::customerGroups(customer: $customer),
                    'codes' => [$code],
                    'codesOnly' => true,
                    'shipping_tax_incl' => $shippingIncl->getAmount(),
                    'shipping_tax_excl' => $shippingExcl->getAmount(),
                    'currency' => $currency,
                ]);
            $outcome = $result === null ? self::NOTHING_OFF : ($result->code_outcomes[$code] ?? CouponOutcome::NOT_FOUND);
            $applied = null;

            foreach ($result->applied ?? [] as $candidate) {
                if (strtoupper((string) $candidate->code) === $code) {
                    $applied = $candidate;
                }
            }

            // With its own rule the only candidate, a code that loses takes nothing off.
            if ($outcome === CouponOutcome::NOT_BETTER_THAN_EXISTING || ($outcome === CouponOutcome::APPLIED && $applied === null)) {
                $outcome = self::NOTHING_OFF;
            }

            if ($outcome === CouponOutcome::APPLIED && !self::combines(db: $db, rule: $applied, held: $kept)) {
                $outcome = self::NOT_COMBINABLE;
            }

            if ($outcome !== CouponOutcome::APPLIED) {
                TransactionState::rollBackLevel($db);

                return $answer($outcome);
            }

            if ($replaced !== null) {
                self::markRemoved(db: $db, orderRuleId: $replaces);
            }

            $lineIds = array_map(static fn (object $line): int => (int) $line->id, $lines);
            $ruleId = OrderRuleShares::freeze(
                db: $db,
                orderId: $orderId,
                applied: $applied,
                orderLineOf: array_combine($lineIds, $lineIds),
                currency: $currency,
            );
            $totalsAfter = OrderTotalHelper::getBreakdown($orderId);
            $amount = $applied->discountTaxInclMoney($currency);

            OrderModel::logOrderActivityStrict(
                $orderId,
                $replaced === null ? 'coupon.added' : 'coupon.edited',
                $replaced === null
                    ? 'Added coupon ' . $code . ': -' . self::figure($amount)
                    : 'Coupon ' . $code . ' in place of ' . $replaced->code . ': -' . self::figure($amount),
                [
                    'code' => $code,
                    'name' => $applied->name,
                    'discount_class' => $applied->discountClass,
                    'value_tax_incl' => self::figure($amount),
                    'value_tax_excl' => self::figure($applied->discountTaxExcl),
                    'free_shipping' => $applied->freeShipping,
                    'replaces' => $replaced?->code,
                    'totals_before' => ['grand_total_tax_incl' => $totalsBefore->grand_total_tax_incl],
                    'totals_after' => ['grand_total_tax_incl' => $totalsAfter->grand_total_tax_incl],
                ],
                $ruleId,
            );

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return $answer(CouponOutcome::APPLIED, $applied, $ruleId, $replaced?->code);
    }

    /**
     * Remove a code from a placed order.
     *
     * @param int $orderId The order.
     * @param int $orderRuleId The coupon (#__alfa_order_cart_rule id).
     *
     * @return string self::REMOVED, or why it was not: self::GONE, self::SHARES_NOT_KEPT.
     *
     * @throws Throwable What stopped the write; nothing on the order moved.
     *
     * @since  2.3.0
     */
    public static function remove(int $orderId, int $orderRuleId): string
    {
        $db = self::db();
        $db->transactionStart(true);

        try {
            self::lockOrder(db: $db, orderId: $orderId);
            $held = self::heldRules(db: $db, orderId: $orderId);
            $refusal = match (true) {
                !isset($held[$orderRuleId]) || $held[$orderRuleId]->code === null => self::GONE,
                self::sharesNotKept($held) => self::SHARES_NOT_KEPT,
                default => null,
            };

            if ($refusal !== null) {
                TransactionState::rollBackLevel($db);

                return $refusal;
            }

            $rule = $held[$orderRuleId];
            $totalsBefore = OrderTotalHelper::getBreakdown($orderId);
            self::markRemoved(db: $db, orderRuleId: $orderRuleId);
            $totalsAfter = OrderTotalHelper::getBreakdown($orderId);
            $currency = OrderCurrency::of(orderId: $orderId);

            OrderModel::logOrderActivityStrict(
                $orderId,
                'coupon.deleted',
                'Removed coupon ' . $rule->code . ': -' . self::figure(Money::of((float) $rule->value_tax_incl, $currency)),
                [
                    'code' => $rule->code,
                    'name' => $rule->name,
                    'discount_class' => $rule->discount_class,
                    'value_tax_incl' => self::figure(Money::of((float) $rule->value_tax_incl, $currency)),
                    'value_tax_excl' => self::figure(Money::of((float) $rule->value_tax_excl, $currency)),
                    'totals_before' => ['grand_total_tax_incl' => $totalsBefore->grand_total_tax_incl],
                    'totals_after' => ['grand_total_tax_incl' => $totalsAfter->grand_total_tax_incl],
                ],
                $orderRuleId,
            );

            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        return self::REMOVED;
    }

    /**
     * What the order screen shows: the coupons on the order, and whether they may be changed there.
     *
     * @param int $orderId The order.
     *
     * @return object{coupons: list<object>, changeable: bool} Each coupon: id_order_cart_rule, code, name,
     *                                                         value_tax_incl, discount_class, free_shipping.
     *
     * @since  2.3.0
     */
    public static function onOrder(int $orderId): object
    {
        $held = self::heldRules(db: self::db(), orderId: $orderId);

        return (object) [
            'coupons' => array_values(array_filter($held, static fn (object $rule): bool => $rule->code !== null)),
            'changeable' => !self::sharesNotKept($held),
        ];
    }

    /**
     * The rules the order holds (not removed), by their row id, in the order they were frozen.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $orderId The order.
     *
     * @return array<int, object>
     *
     * @since  2.3.0
     */
    private static function heldRules(DatabaseDriver $db, int $orderId): array
    {
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id_order_cart_rule', 'id_cart_rule', 'name', 'code', 'value_tax_incl', 'value_tax_excl',
                    'discount_class', 'free_shipping', 'line_shares']))
                ->from($db->quoteName('#__alfa_order_cart_rule'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('deleted') . ' = 0')
                ->order($db->quoteName('id_order_cart_rule') . ' ASC'),
        )->loadObjectList('id_order_cart_rule') ?: [];

        foreach ($rows as $row) {
            $row->code = $row->code !== null && $row->code !== '' ? (string) $row->code : null;
        }

        return $rows;
    }

    /**
     * Whether the order holds a product or order discount frozen as one order-wide figure, without shares.
     *
     * @param array<int, object> $held The rules the order holds.
     *
     * @since  2.3.0
     */
    private static function sharesNotKept(array $held): bool
    {
        foreach ($held as $rule) {
            if ($rule->discount_class !== 'shipping' && (int) $rule->line_shares !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The order's lines as the rules engine reads them, with what the rules the order holds take off each.
     *
     * The lines in the order they were placed; a line priced by staff carries that fact, and a line the catalogue
     * discounts carries that one (its ordinary price below its undiscounted one), as the cart's lines do.
     *
     * @param DatabaseDriver $db The connection, inside the transaction.
     * @param int $orderId The order.
     * @param int $without A rule whose shares are not counted (the one being replaced), or 0.
     * @param Currency $currency The order's currency.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function lineSnapshot(DatabaseDriver $db, int $orderId, int $without, Currency $currency): array
    {
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_item', 'quantity', 'total_price_tax_incl', 'total_price_tax_excl',
                    'custom_unit_price_tax_incl', 'base_total_tax_incl', 'reference_total_tax_incl', 'discount_amount_tax_incl']))
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->where($db->quoteName('quantity') . ' > 0')
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];
        $taken = $db->setQuery(
            $db->getQuery(true)
                ->select([
                    $db->quoteName('s.id_order_item'),
                    'SUM(' . $db->quoteName('s.value_tax_incl') . ') AS ' . $db->quoteName('incl'),
                    'MAX(' . $db->quoteName('r.discount_class') . ' = ' . $db->quote('product') . ') AS ' . $db->quoteName('claimed'),
                ])
                ->from($db->quoteName(OrderRuleShares::TABLE, 's'))
                ->join('INNER', $db->quoteName('#__alfa_order_cart_rule', 'r'), $db->quoteName('r.id_order_cart_rule') . ' = ' . $db->quoteName('s.id_order_cart_rule'))
                ->where($db->quoteName('s.id_order') . ' = ' . $orderId)
                ->where($db->quoteName('r.deleted') . ' = 0')
                ->where($db->quoteName('r.id_order_cart_rule') . ' <> ' . $without)
                ->group($db->quoteName('s.id_order_item')),
        )->loadObjectList('id_order_item') ?: [];
        $minor = static fn (mixed $amount): int => Money::of((string) $amount, $currency)->getMinorUnits();
        $lines = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $custom = $row->custom_unit_price_tax_incl !== null;
            $catalogue = !$custom && ($row->base_total_tax_incl !== null && $row->reference_total_tax_incl !== null
                ? $minor($row->reference_total_tax_incl) < $minor($row->base_total_tax_incl)
                : $minor($row->discount_amount_tax_incl) > 0);
            $lines[] = (object) [
                'id' => $id,
                'id_item' => (int) $row->id_item,
                'quantity' => (int) $row->quantity,
                'line_tax_incl' => (float) $row->total_price_tax_incl,
                'line_tax_excl' => (float) $row->total_price_tax_excl,
                'has_catalogue_discount' => $catalogue,
                'has_custom_price' => $custom,
                'taken_tax_incl' => (float) ($taken[$id]->incl ?? 0),
                'claimed' => (int) ($taken[$id]->claimed ?? 0) === 1,
            ];
        }

        return $lines;
    }

    /**
     * Whether a rule joins the rules of its class the order keeps: it and every one of them combine with that class
     * (as the rules stand now; a rule since deleted does not combine).
     *
     * @param DatabaseDriver $db The connection.
     * @param AppliedCartRule $rule The rule the code applies.
     * @param array<int, object> $held The rules the order keeps.
     *
     * @since  2.3.0
     */
    private static function combines(DatabaseDriver $db, AppliedCartRule $rule, array $held): bool
    {
        $sameClass = array_map(
            static fn (object $row): int => (int) $row->id_cart_rule,
            array_filter($held, static fn (object $row): bool => $row->discount_class === $rule->discountClass),
        );

        if ($sameClass === []) {
            return true;
        }

        $flag = 'combines_' . $rule->discountClass;
        $ids = array_values(array_unique(array_merge($sameClass, [$rule->id])));
        $flags = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', $flag]))
                ->from($db->quoteName('#__alfa_cart_rules'))
                ->whereIn($db->quoteName('id'), $ids),
        )->loadAssocList('id', $flag) ?: [];

        foreach ($ids as $id) {
            if ((int) ($flags[$id] ?? 0) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark a coupon removed: its row and shares stay as they are.
     *
     * @param DatabaseDriver $db The connection, inside the transaction.
     * @param int $orderRuleId The coupon's row.
     *
     * @since  2.3.0
     */
    private static function markRemoved(DatabaseDriver $db, int $orderRuleId): void
    {
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__alfa_order_cart_rule'))
                ->set($db->quoteName('deleted') . ' = 1')
                ->where($db->quoteName('id_order_cart_rule') . ' = ' . $orderRuleId),
        )->execute();
    }

    /**
     * Lock the order's row.
     *
     * @param DatabaseDriver $db The connection, inside the transaction.
     * @param int $orderId The order.
     *
     * @throws RuntimeException When the order does not exist.
     *
     * @since  2.3.0
     */
    private static function lockOrder(DatabaseDriver $db, int $orderId): object
    {
        return $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__alfa_orders') . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId . ' FOR UPDATE',
        )->loadObject() ?? throw new RuntimeException('Order #' . $orderId . ' does not exist.');
    }

    /**
     * An amount as the history stores it: plain, at the currency's scale, with a point.
     *
     * @param Money $money The amount.
     *
     * @since  2.3.0
     */
    private static function figure(Money $money): string
    {
        return number_format($money->getAmount(), $money->getCurrency()->getDecimalPlaces(), '.', '');
    }

    /**
     * The connection.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseDriver
    {
        return TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));
    }
}
