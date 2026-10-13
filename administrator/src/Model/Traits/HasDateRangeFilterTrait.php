<?php

/**
 * @package     Alfa Commerce
 * @subpackage  com_alfa
 *
 * @copyright   (C) 2026 Easylogic. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model\Traits;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Factory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The preset-plus-calendars date window shared by the admin lists that have one.
 *
 * A list gets it by declaring `date_range` / `date_start` / `date_end` in its filter
 * form and calling {@see self::resolveDateRangeFilter()} at the END of populateState()
 * (the parent must have filled the registry first). The query side is
 * {@see AlfaHelper::applyDateRange()}, which is a plain static so a PLUGIN panel can
 * reach it too.
 *
 * Preset NAMES are one fixed vocabulary for every list that uses this trait, so a
 * filter reads the same wherever it appears.
 *
 * @since  2.0.0
 */
trait HasDateRangeFilterTrait
{
    /**
     * Resolve the date window and publish it to state.
     *
     * The dropdown and the calendars address the same pair of bounds, so whichever the
     * operator JUST CHANGED wins: picking a date while "Today" is still selected must
     * not be overruled by the preset rebuilding its bounds on the next render, and
     * picking a preset must overwrite whatever the calendars held.
     *
     * "What changed" is read from the RAW REQUEST, and a field that was not submitted at
     * all is `null` rather than ''. That distinction is the whole mechanism. Core replaces
     * the ENTIRE `$context.'.filter'` user-state node with whatever the request carried
     * ({@see \Joomla\CMS\MVC\Model\ListModel::populateState()}), so a link passing only
     * `filter[date_range]` leaves `filter.date_start` ABSENT. Comparing resolved STATE
     * instead would read that absence as "the operator emptied the calendar", clear the
     * preset, and the filter would vanish on the second identical request, while the
     * first request still worked, so it looks correct under casual testing.
     *
     * $dateOnly is for a DATE column whose picker shows no time (sales documents): store
     * what the field posts BACK, or every later submit looks like a hand-edited calendar
     * by the same presence test.
     *
     * Nothing here may call $this->getState(): inside populateState() that re-enters
     * populateState() and recurses until the stack dies.
     *
     * @param bool $dateOnly Store bounds as Y-m-d rather than Y-m-d H:i:s.
     *
     *
     * @since   2.0.0
     */
    protected function resolveDateRangeFilter(bool $dateOnly = false): void
    {
        $app = Factory::getApplication();
        $last = (array) $app->getUserState($this->context . '.dateresolved', []);

        $lastRange = (string) ($last['range'] ?? '');
        $lastStart = (string) ($last['start'] ?? '');
        $lastEnd = (string) ($last['end'] ?? '');

        $req = (array) $app->getInput()->get('filter', [], 'array');
        $reqRange = \array_key_exists('date_range', $req) ? (string) $req['date_range'] : null;
        $reqStart = \array_key_exists('date_start', $req) ? (string) $req['date_start'] : null;
        $reqEnd = \array_key_exists('date_end', $req) ? (string) $req['date_end'] : null;

        $bounds = static function (string $range) use ($dateOnly): array {
            $window = AlfaHelper::buildDateRange($range);

            if (!$window) {
                return ['', ''];
            }

            $start = $window['dStart']->toSql(true);
            $end = $window['dEnd']->toSql(true);

            return $dateOnly ? [substr($start, 0, 10), substr($end, 0, 10)] : [$start, $end];
        };

        if ($reqRange !== null && $reqRange !== $lastRange) {
            // The dropdown drove it: the pair is rebuilt from the preset, and any stale
            // calendar values riding along in the same submit are ignored.
            $range = $reqRange;
            [$start, $end] = $bounds($range);
        } elseif (($reqStart !== null && $reqStart !== $lastStart)
            || ($reqEnd !== null && $reqEnd !== $lastEnd)) {
            // A calendar drove it: the preset no longer describes these bounds.
            $range = '';
            $start = $reqStart ?? $lastStart;
            $end = $reqEnd ?? $lastEnd;
        } else {
            // Carry-over. Replay a preset rather than reusing its stored bounds, so
            // "Today" follows the clock instead of freezing on the day it was picked.
            $range = $lastRange;

            if ($range !== '') {
                [$start, $end] = $bounds($range);
            } else {
                $start = $lastStart;
                $end = $lastEnd;
            }
        }

        $this->setState('filter.date_range', $range);
        $this->setState('filter.date_start', $start);
        $this->setState('filter.date_end', $end);

        // loadFormData() binds the form from USER state, not model state, so the resolved
        // pair has to be written back or the calendars render empty next to a live preset.
        $app->setUserState($this->context . '.filter.date_range', $range);
        $app->setUserState($this->context . '.filter.date_start', $start);
        $app->setUserState($this->context . '.filter.date_end', $end);

        $app->setUserState($this->context . '.dateresolved', [
            'range' => $range,
            'start' => $start,
            'end' => $end,
        ]);
    }
}
