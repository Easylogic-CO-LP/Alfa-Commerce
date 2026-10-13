<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Stats;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Throwable;

/**
 * How far back the dashboard is looking: one answer, for every card on the screen.
 *
 * A merchant asks two different questions of the same shop: "what happened this week"
 * on a Monday morning, and "how is the quarter going" when they sit down to plan. One
 * fixed window can only answer one of them, so the period is a control, and because
 * it is a REMEMBERED one, they set it once rather than every time they open the screen.
 *
 * It is a user state, not a component parameter, deliberately: two people looking at
 * the same shop should not fight over each other's window, and a preference is not
 * configuration. Being per user also means the card cache (which is already per
 * viewer) needs only the period appended to stay correct.
 *
 * Only three windows exist, and nothing else is accepted. A free-text day count would
 * be a cache key with unbounded cardinality and a chart with no sensible label; three
 * named periods cover the week, the month and the quarter a shop is actually run by.
 *
 * @since  2.0.0
 */
final class DashboardPeriod
{
    /**
     * @var string Where the choice is remembered.
     * @since  2.0.0
     */
    public const STATE_KEY = 'com_alfa.dashboard.days';

    /**
     * @var string The request variable the switcher's links carry.
     * @since  2.0.0
     */
    public const REQUEST_KEY = 'days';

    /**
     * @var int What a merchant sees before they have ever chosen.
     * @since  2.0.0
     */
    public const DEFAULT_DAYS = 30;

    /**
     * @var array<int, string> The windows offered, and the key that names each.
     * @since  2.0.0
     */
    public const OPTIONS = [
        7 => 'COM_ALFA_DASHBOARD_PERIOD_7',
        30 => 'COM_ALFA_DASHBOARD_PERIOD_30',
        90 => 'COM_ALFA_DASHBOARD_PERIOD_90',
    ];

    /**
     * The period in force, taking a fresh choice from the request when one is there.
     *
     * Read through `getUserStateFromRequest` so that arriving with `&days=90` both
     * answers this request and is remembered for the next one: the same contract every
     * list filter in the admin already has.
     *
     * @return int One of {@see self::OPTIONS}' keys.
     *
     * @since  2.0.0
     */
    public static function days(): int
    {
        try {
            $days = (int) Factory::getApplication()->getUserStateFromRequest(
                self::STATE_KEY,
                self::REQUEST_KEY,
                self::DEFAULT_DAYS,
                'int',
            );
        } catch (Throwable) {
            return self::DEFAULT_DAYS;
        }

        // Anything not offered is not a period: a hand-typed &days=100000 must not
        // become a cache entry, let alone a query.
        return isset(self::OPTIONS[$days]) ? $days : self::DEFAULT_DAYS;
    }

    /**
     * The period as a cache scope, so a card resolved for one window is never served
     * for another.
     *
     *
     * @since  2.0.0
     */
    public static function scope(): string
    {
        return 'd' . self::days();
    }
}
