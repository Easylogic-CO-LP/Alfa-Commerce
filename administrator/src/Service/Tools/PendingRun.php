<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Tools;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * The counts of one Run pending on the Background screen, kept in the member of staff's session from the plan to the
 * last step (each step is a request of its own), and the one message that says what the run did: the jobs run, the
 * stock movements applied, the products with updated prices, what is still pending and the errors, only the lines
 * that apply. A run stopped by a limit (a step that could not advance, the most steps) never says it finished.
 *
 * @since  2.3.0
 */
final class PendingRun
{
    /**
     * The session key the run's counts are kept under.
     *
     * @since  2.3.0
     */
    public const STATE = 'com_alfa.tools.pending';

    /**
     * The most steps one run takes before it stops and says what is still pending.
     *
     * @since  2.3.0
     */
    public const STEPS_MAX = 300;

    /**
     * The counts a run keeps.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const COUNTS = ['jobs', 'stock', 'prices', 'errors', 'steps'];

    /**
     * Begin a run: every count at zero.
     *
     * @since  2.3.0
     */
    public static function start(): void
    {
        Factory::getApplication()->setUserState(self::STATE, array_fill_keys(self::COUNTS, 0));
    }

    /**
     * The run's counts so far.
     *
     * @return array<string, int>
     *
     * @since  2.3.0
     */
    public static function counts(): array
    {
        $kept = (array) Factory::getApplication()->getUserState(self::STATE, []);
        $counts = [];

        foreach (self::COUNTS as $count) {
            $counts[$count] = (int) ($kept[$count] ?? 0);
        }

        return $counts;
    }

    /**
     * Add to one of the run's counts.
     *
     * @param string $count The count ({@see self::COUNTS}).
     * @param int $more How many more.
     *
     * @since  2.3.0
     */
    public static function add(string $count, int $more): void
    {
        $counts = self::counts();
        $counts[$count] = ($counts[$count] ?? 0) + $more;
        Factory::getApplication()->setUserState(self::STATE, $counts);
    }

    /**
     * One more step taken; the steps so far.
     *
     * @since  2.3.0
     */
    public static function step(): int
    {
        self::add('steps', 1);

        return self::counts()['steps'];
    }

    /**
     * How many of the run's records were done so far, for its progress.
     *
     * @since  2.3.0
     */
    public static function done(): int
    {
        $counts = self::counts();

        return $counts['jobs'] + $counts['stock'] + $counts['prices'];
    }

    /**
     * End the run: its message, the counts forgotten.
     *
     * @param int $pending What is still pending.
     * @param bool $stopped Whether a limit stopped the run before everything pending was done.
     *
     * @since  2.3.0
     */
    public static function finish(int $pending, bool $stopped): string
    {
        $counts = self::counts();
        Factory::getApplication()->setUserState(self::STATE, null);

        return self::message(counts: $counts, pending: $pending, stopped: $stopped);
    }

    /**
     * What a run says, in the lines that apply: that it finished, or that a limit stopped it with records pending; the
     * jobs run, the stock movements applied and the products with updated prices when there were any; what is still
     * pending when something is; the errors when there were any. A run that found nothing pending and did nothing says
     * only that.
     *
     * @param array<string, int> $counts The run's counts (jobs, stock, prices, errors).
     * @param int $pending What is still pending.
     * @param bool $stopped Whether a limit stopped the run.
     *
     * @since  2.3.0
     */
    public static function message(array $counts, int $pending, bool $stopped): string
    {
        $jobs = (int) ($counts['jobs'] ?? 0);
        $stock = (int) ($counts['stock'] ?? 0);
        $prices = (int) ($counts['prices'] ?? 0);
        $errors = (int) ($counts['errors'] ?? 0);

        if ($jobs + $stock + $prices + $errors === 0 && $pending === 0 && !$stopped) {
            return Text::_('COM_ALFA_TASKS_PENDING_NOTHING');
        }

        $lines = [Text::_($stopped ? 'COM_ALFA_TASKS_PENDING_STOPPED' : 'COM_ALFA_TASKS_PENDING_FINISHED')];

        if ($jobs > 0) {
            $lines[] = Text::sprintf('COM_ALFA_TASKS_PENDING_JOBS', $jobs);
        }

        if ($stock > 0) {
            $lines[] = Text::sprintf('COM_ALFA_TASKS_PENDING_STOCK', $stock);
        }

        if ($prices > 0) {
            $lines[] = Text::sprintf('COM_ALFA_TASKS_PENDING_PRICES', $prices);
        }

        if ($pending > 0) {
            $lines[] = Text::sprintf('COM_ALFA_TASKS_PENDING_LEFT', $pending);
        }

        if ($errors > 0) {
            $lines[] = Text::sprintf('COM_ALFA_TASKS_PENDING_ERRORS', $errors);
        }

        return implode(' ', $lines);
    }
}
