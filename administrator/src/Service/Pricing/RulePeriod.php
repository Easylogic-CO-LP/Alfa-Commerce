<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;

/**
 * Where a rule's period stands against the clock: active, expired or not started yet.
 *
 * The one judgement the pricing engine makes of a discount's or a tax's publish_up and publish_down, read back for the
 * screens: every comparison is made in UTC, the stored form of the dates, against the engine's own clock
 * (ActiveDateCondition::now()). A row whose end has passed is expired, whatever its state; a row whose start has not
 * come is upcoming; an empty date, or the empty date, is an open end. The SQL of each status is built from the engine's
 * conditions, so a list filter and the engine never disagree about one rule.
 *
 * @since  2.3.0
 */
final class RulePeriod
{
    /**
     * The rule is in its period now.
     *
     * @since  2.3.0
     */
    public const ACTIVE = 'active';

    /**
     * The rule's end has passed.
     *
     * @since  2.3.0
     */
    public const EXPIRED = 'expired';

    /**
     * The rule's start has not come.
     *
     * @since  2.3.0
     */
    public const UPCOMING = 'upcoming';

    /**
     * The three statuses, in the order the filter offers them.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const STATUSES = [self::ACTIVE, self::EXPIRED, self::UPCOMING];

    /**
     * The empty date a stored period may hold instead of NULL: an open end.
     *
     * @since  2.3.0
     */
    public const EMPTY_DATE = '0000-00-00 00:00:00';

    /**
     * Whether a stored date names a moment: not NULL, not empty and not the empty date.
     *
     * @param string|null $date The stored date (UTC, Y-m-d H:i:s).
     *
     * @since  2.3.0
     */
    public static function isMoment(?string $date): bool
    {
        return $date !== null && $date !== '' && $date !== self::EMPTY_DATE && (int) $date > 0;
    }

    /**
     * The status of a period at a moment, as the engine reads it: expired once the clock is past the end (an end equal
     * to the clock is still active), upcoming while the clock is before the start, active otherwise.
     *
     * @param string|null $publishUp The stored start (UTC), or none.
     * @param string|null $publishDown The stored end (UTC), or none.
     * @param string|null $now The moment (UTC, Y-m-d H:i:s); the engine's clock when omitted.
     *
     * @return string One of STATUSES.
     *
     * @since  2.3.0
     */
    public static function status(?string $publishUp, ?string $publishDown, ?string $now = null): string
    {
        $now ??= ActiveDateCondition::now();

        if (self::isMoment($publishDown) && $now > $publishDown) {
            return self::EXPIRED;
        }

        if (self::isMoment($publishUp) && $now < $publishUp) {
            return self::UPCOMING;
        }

        return self::ACTIVE;
    }

    /**
     * The SQL condition of one status over a table's period columns, from the engine's own conditions.
     *
     * @param string $status One of STATUSES.
     * @param string $startColumn The start column, with its alias (a.publish_up).
     * @param string $endColumn The end column, with its alias (a.publish_down).
     * @param string|null $now The moment (UTC); the engine's clock when omitted.
     *
     * @return string The condition; '1 = 1' for an unknown status, so a filter value nobody offers narrows nothing.
     *
     * @since  2.3.0
     */
    public static function sql(string $status, string $startColumn, string $endColumn, ?string $now = null): string
    {
        $condition = new ActiveDateCondition();

        return match ($status) {
            self::ACTIVE => '(' . $condition->getActiveCondition($startColumn, $endColumn, $now) . ')',
            self::EXPIRED => 'NOT (' . $condition->getNotEndedCondition(endField: $endColumn, referenceDate: $now) . ')',
            self::UPCOMING => 'NOT (' . $condition->getStartedCondition(startField: $startColumn, referenceDate: $now) . ')',
            default => '1 = 1',
        };
    }

    /**
     * The SQL predicate that a stored date names a moment (see isMoment()).
     *
     * @param string $column The date column, with its alias.
     *
     * @since  2.3.0
     */
    public static function momentSql(string $column): string
    {
        return '(' . $column . ' IS NOT NULL AND ' . $column . " <> '" . self::EMPTY_DATE . "')";
    }
}
