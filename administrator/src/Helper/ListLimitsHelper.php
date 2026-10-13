<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * THE parser for the "page sizes" setting (`items_limits`: the sizes a shopper
 * may pick, e.g. "12,24,48,0").
 *
 * One canonical implementation, used by every consumer: the admin field renders
 * its inherit hint through it, the site model resolves the storefront dropdown
 * through it. The stored string is user input guarded by ListLimitsRule at save
 * time, but the DB can be written directly, so consumers go through parse()
 * and never trust the raw string.
 *
 * @since  2.0.0
 */
abstract class ListLimitsHelper
{
    /**
     * Comma-separated sizes -> clean int list: trimmed, digits-only entries,
     * de-duplicated, ascending. 0 is a legitimate entry meaning "All".
     * Anything else is dropped silently: a half-broken stored value degrades
     * to the valid part rather than breaking the storefront.
     *
     * @param string $raw The stored setting value
     *
     * @return int[] Clean sizes; empty when nothing valid is configured
     * @since  2.0.0
     */
    public static function parse(string $raw): array
    {
        $sizes = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if ($entry !== '' && preg_match('/^\d+$/', $entry)) {
                $sizes[] = (int) $entry;
            }
        }

        $sizes = array_values(array_unique($sizes));
        sort($sizes);

        return $sizes;
    }

    /**
     * Human form of a size list for admin hints: "12, 24, 48, All";
     * 0 renders as the translated "All" and moves to the end, where the
     * storefront dropdown also puts it.
     *
     * @param int[] $sizes Parsed sizes
     *
     * @return string Display label, '' for an empty list
     * @since  2.0.0
     */
    public static function label(array $sizes): string
    {
        $hasAll = \in_array(0, $sizes, true);
        $parts = array_filter($sizes, static fn (int $v): bool => $v > 0);

        if ($hasAll) {
            $parts[] = Text::_('JALL');
        }

        return implode(', ', $parts);
    }
}
