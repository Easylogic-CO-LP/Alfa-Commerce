<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\MoneyInputFormat;
use InvalidArgumentException;
use Joomla\CMS\Factory;

/**
 * A payment method's instalment tiers: the `instalments` subform every gateway
 * that lets the shop set tiers shares, each row {count, from_total}.
 *
 * A tier applies FROM its order total upward. The row with the highest
 * threshold the amount reaches wins, so the order the admin typed the rows in
 * does not matter. The count never changes the amount charged: the bank splits
 * the payment, and the order total stays as it is.
 *
 * Gateways read the tiers only through their MethodProfile, which applies the
 * gateway's own gating (an instant-payment scheme, manual capture) before asking here.
 *
 * @since  2.0.0
 */
final class InstalmentTiers
{
    /** The params key of the subform. @since 2.0.0 */
    public const FIELD = 'instalments';

    /** cartform[instalments]: the customer's choice, where the shop picks the count. @since 2.0.0 */
    public const CART_INPUT = 'instalments';

    /** User state holding the choice between placement and the gateway visit. @since 2.0.0 */
    public const STATE_KEY = 'com_alfa.payment.instalments';

    /**
     * The highest count the method's tiers allow for an amount.
     *
     * The stored rows are read by value: the subform keys them instalmentsN
     * with gaps, and an emptied subform saves []. A row whose count is not a
     * whole number of at least 1, or whose from_total cannot be read as an
     * amount, grants nothing. The threshold is read at the amount's currency
     * scale and compared as Money, never as a float. On equal thresholds the
     * larger count wins. Nothing configured or nothing reached is 1.
     *
     * @param array $params The method's params.
     * @param Money $amount The amount to be collected.
     * @param int $ceiling The gateway's own highest count.
     *
     * @return int From 1 to $ceiling.
     *
     * @throws InvalidArgumentException When $ceiling is below 1.
     *
     * @since  2.0.0
     */
    public static function maxCount(array $params, Money $amount, int $ceiling): int
    {
        if ($ceiling < 1) {
            throw new InvalidArgumentException('An instalment ceiling is at least 1, not ' . $ceiling . '.');
        }

        $rows = $params[self::FIELD] ?? [];

        if (!\is_array($rows) && !\is_object($rows)) {
            return 1;
        }

        $format = MoneyInputFormat::of(decimalSeparator: '.');
        $allowed = 1;
        $matched = null;

        foreach ((array) $rows as $row) {
            if (!\is_array($row) && !\is_object($row)) {
                continue;
            }

            $row = (array) $row;
            $count = self::count(value: $row['count'] ?? null);
            $from = self::threshold(value: $row['from_total'] ?? null, amount: $amount, format: $format);

            if ($count < 1 || $from === null || $amount->lessThan($from)) {
                continue;
            }

            $higher = $matched === null || $from->greaterThan($matched);

            if ($higher || ($from->equals($matched) && $count > $allowed)) {
                $matched = $from;
                $allowed = $count;
            }
        }

        return max(1, min($ceiling, $allowed));
    }

    /**
     * What one instalment costs, per count, as a disclosure: the first part of
     * the total split with Money::allocate(), which is the largest part.
     *
     * @param Money $total The amount the customer pays.
     * @param int $max The highest count on offer.
     *
     * @return array<int, string> count => formatted amount, for 2 to $max; [] when $max is below 2.
     *
     * @since  2.0.0
     */
    public static function amounts(Money $total, int $max): array
    {
        $amounts = [];

        for ($count = 2; $count <= $max; $count++) {
            $amounts[$count] = $total->allocate($count)[0]->format();
        }

        return $amounts;
    }

    /**
     * The count to show as selected, so a cart re-render keeps the customer's
     * choice: this request's cartform[instalments], else the remembered one.
     *
     * @param int $max The highest count on offer.
     *
     * @return int From 1 to $max.
     *
     * @since  2.0.0
     */
    public static function selected(int $max): int
    {
        $app = Factory::getApplication();
        $cartform = $app->getInput()->post->get('cartform', [], 'array');

        $chosen = \array_key_exists(self::CART_INPUT, $cartform)
            ? (int) $cartform[self::CART_INPUT]
            : (int) $app->getUserState(self::STATE_KEY, 1);

        return max(1, min($chosen, max(1, $max)));
    }

    /**
     * Remember the customer's choice for the gateway visit. Called on every
     * placement, 1 included, so an earlier order's choice cannot carry over.
     *
     * @param int $count The chosen count.
     *
     *
     * @since  2.0.0
     */
    public static function remember(int $count): void
    {
        Factory::getApplication()->setUserState(self::STATE_KEY, max(1, $count));
    }

    /**
     * Read the remembered choice and clear it.
     *
     * @return int The choice, or 1 when there is none.
     *
     * @since  2.0.0
     */
    public static function take(): int
    {
        $app = Factory::getApplication();
        $chosen = (int) $app->getUserState(self::STATE_KEY, 1);

        $app->setUserState(self::STATE_KEY, null);

        return max(1, $chosen);
    }

    /**
     * A row's count: a whole number, as the form's integer filter stores it.
     *
     * @param mixed $value The stored value.
     *
     * @return int 0 when it is not a whole number.
     *
     * @since  2.0.0
     */
    private static function count(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : 0;
    }

    /**
     * A row's threshold, at the amount's currency and scale.
     *
     * @param mixed $value The stored from_total: the posted number, as text.
     * @param Money $amount The amount it is compared with.
     * @param MoneyInputFormat $format The number field's syntax: '.' for decimals, no grouping.
     *
     * @return Money|null Null when it is not an amount.
     *
     * @since  2.0.0
     */
    private static function threshold(mixed $value, Money $amount, MoneyInputFormat $format): ?Money
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return Money::parse($value, $amount->getCurrency(), $format);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
