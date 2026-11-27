<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Throwable;

/**
 * A publish period: a start, an end, or both, each a UTC moment or no bound.
 *
 * Price rows, discounts and taxes carry one. A period whose end is at or before its start would never apply, so a
 * save that states one is refused; the comparison is made here once, on the stored UTC text.
 *
 * @since  2.3.0
 */
final class PublishPeriod
{
    /**
     * Whether the end, when both ends are set, comes after the start. An empty date, the null date and a date that
     * is not a date are no bound: they are judged by the writer that stores them, not here.
     *
     * @param mixed $start The start as stored (UTC text), or empty.
     * @param mixed $end The end as stored (UTC text), or empty.
     *
     * @since  2.3.0
     */
    public static function endsAfterStart(mixed $start, mixed $end): bool
    {
        $from = self::moment(text: $start);
        $until = self::moment(text: $end);

        return $from === null || $until === null || $until > $from;
    }

    /**
     * The UTC moment of a stored date as a timestamp; null for no date.
     *
     * @since  2.3.0
     */
    private static function moment(mixed $text): ?int
    {
        if (!\is_string($text) || trim($text) === '' || (int) $text <= 0) {
            return null;
        }

        try {
            return Factory::getDate($text)->toUnix();
        } catch (Throwable) {
            return null;
        }
    }
}
