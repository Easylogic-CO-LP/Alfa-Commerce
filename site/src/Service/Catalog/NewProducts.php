<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * What makes a product new: the shop's setting of how many days a product counts as new (Options > General > Products,
 * 0 = never) and from which date they are counted, the day it was made or the day it was published (its publish-up
 * date, the day it was made for a product published at once). The one answer for every use: the storefront's "New"
 * label and the discount rules that leave new products out (a rule may use the shop's days or days of its own).
 *
 * A product made before the day it was made began to be kept has no date and is never new. Every date is UTC, as it is
 * stored, and the clock is the pricing engine's (ActiveDateCondition::now()).
 *
 * @since  2.3.0
 */
final class NewProducts
{
    /**
     * Counted from the day the product was made.
     *
     * @since  2.3.0
     */
    public const FROM_CREATED = 'created';

    /**
     * Counted from the day the product was published (its publish-up date, else the day it was made).
     *
     * @since  2.3.0
     */
    public const FROM_PUBLISHED = 'published';

    /**
     * The shop's days setting.
     *
     * @since  2.3.0
     */
    public const PARAM_DAYS = 'new_product_days';

    /**
     * The shop's counted-from setting.
     *
     * @since  2.3.0
     */
    public const PARAM_FROM = 'new_product_from';

    /**
     * The days a product counts as new for the shop; 0 when the shop does not count products as new.
     *
     * @since  2.3.0
     */
    public static function days(): int
    {
        return max(0, (int) ComponentHelper::getParams('com_alfa')->get(self::PARAM_DAYS, 0));
    }

    /**
     * From which date the shop counts: FROM_CREATED or FROM_PUBLISHED.
     *
     * @since  2.3.0
     */
    public static function countedFrom(): string
    {
        return (string) ComponentHelper::getParams('com_alfa')->get(self::PARAM_FROM, self::FROM_CREATED) === self::FROM_PUBLISHED ? self::FROM_PUBLISHED : self::FROM_CREATED;
    }

    /**
     * The date a product is counted from, as stored (UTC): its publish-up date else the day it was made, or the day it
     * was made alone, by the shop's setting; null when it has none.
     *
     * @param string|null $created When the product was made.
     * @param string|null $publishUp Its publish-up date.
     * @param string|null $from FROM_CREATED or FROM_PUBLISHED; the shop's setting when omitted.
     *
     * @since  2.3.0
     */
    public static function date(?string $created, ?string $publishUp, ?string $from = null): ?string
    {
        $moment = static fn (?string $date): ?string => $date === null || $date === '' || (int) $date <= 0 ? null : $date;
        $from ??= self::countedFrom();

        return $from === self::FROM_PUBLISHED ? ($moment($publishUp) ?? $moment($created)) : $moment($created);
    }

    /**
     * The moment a product stops being new (UTC, Y-m-d H:i:s): its date plus the days; null when it has no date or
     * the days are none.
     *
     * @param string|null $created When the product was made.
     * @param string|null $publishUp Its publish-up date.
     * @param int|null $days The days; the shop's when omitted.
     * @param string|null $from FROM_CREATED or FROM_PUBLISHED; the shop's setting when omitted.
     *
     * @since  2.3.0
     */
    public static function until(?string $created, ?string $publishUp, ?int $days = null, ?string $from = null): ?string
    {
        $days ??= self::days();
        $date = self::date(created: $created, publishUp: $publishUp, from: $from);

        if ($date === null || $days <= 0) {
            return null;
        }

        return Factory::getDate($date)->modify('+' . $days . ' days')->toSql();
    }

    /**
     * Whether a product is new at a moment: its date has come and the days have not passed.
     *
     * @param string|null $created When the product was made.
     * @param string|null $publishUp Its publish-up date.
     * @param int|null $days The days; the shop's when omitted.
     * @param string|null $now The moment (UTC); the engine's clock when omitted.
     * @param string|null $from FROM_CREATED or FROM_PUBLISHED; the shop's setting when omitted.
     *
     * @since  2.3.0
     */
    public static function isNew(?string $created, ?string $publishUp, ?int $days = null, ?string $now = null, ?string $from = null): bool
    {
        $until = self::until(created: $created, publishUp: $publishUp, days: $days, from: $from);

        if ($until === null) {
            return false;
        }

        $now ??= ActiveDateCondition::now();
        $date = self::date(created: $created, publishUp: $publishUp, from: $from);

        return $now >= $date && $now < $until;
    }

    /**
     * The date a product is counted from, as SQL over the items table.
     *
     * @param string $itemAlias The items table's alias.
     * @param string|null $from FROM_CREATED or FROM_PUBLISHED; the shop's setting when omitted.
     *
     * @since  2.3.0
     */
    public static function dateSql(string $itemAlias, ?string $from = null): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $created = $db->quoteName($itemAlias . '.created');
        $publishUp = $db->quoteName($itemAlias . '.publish_up');

        return ($from ?? self::countedFrom()) === self::FROM_PUBLISHED
            ? 'COALESCE(NULLIF(' . $publishUp . ", '0000-00-00 00:00:00'), " . $created . ')'
            : $created;
    }

    /**
     * The SQL condition that a product is new at a moment, for days given as SQL (a literal, or a rule's own column).
     *
     * @param string $itemAlias The items table's alias.
     * @param string $daysSql The days, as SQL.
     * @param string $momentSql The moment, as quoted SQL (UTC).
     * @param string|null $from FROM_CREATED or FROM_PUBLISHED; the shop's setting when omitted.
     *
     * @since  2.3.0
     */
    public static function withinSql(string $itemAlias, string $daysSql, string $momentSql, ?string $from = null): string
    {
        $date = self::dateSql(itemAlias: $itemAlias, from: $from);

        return '(' . $date . ' IS NOT NULL AND ' . $date . ' <= ' . $momentSql . ' AND DATE_ADD(' . $date . ', INTERVAL ' . $daysSql . ' DAY) > ' . $momentSql . ')';
    }
}
