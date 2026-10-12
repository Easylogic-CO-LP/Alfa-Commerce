<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * What "active", "abandoned" and "empty" mean, said once.
 *
 * Three surfaces answer the same question about the same rows: the tiles count
 * them, the status filter selects them, and each row wears a badge. They agreed
 * only because three separate pieces of code happened to spell the same
 * predicate, and they measured it against three separately-read clocks: one
 * UTC_TIMESTAMP() inside the list query, another inside the summary query, and a
 * Factory::getDate() in the layout. A cart updated in the seconds between them
 * could be counted active, filtered as abandoned and badged as neither.
 *
 * THE THREE BUCKETS PARTITION THE TABLE. Every cart is in exactly one:
 *
 *   EMPTY      holds no line. Nothing else is asked about it.
 *   ABANDONED  holds a line and has been silent since the cutoff.
 *   ACTIVE     holds a line and has not.
 *
 * `updated` is NOT NULL, so there is no fourth case and the counts sum to the
 * row count. That is a property worth stating because the tiles are read as a
 * breakdown, and a breakdown that does not add up is worse than no breakdown.
 *
 * ABANDONMENT IS A QUERY, NEVER A COLUMN. A cart the shopper opens again stops
 * being abandoned by itself, which no stored status could do.
 *
 * @since  1.10.0
 */
final class CartActivity
{
    /**
     * Hours of silence after which a cart with lines reads as abandoned.
     *
     * Measured from the LAST ACTIVITY, not from creation, so a cart someone has
     * been editing for three days is not reported as abandoned on day two.
     *
     * @since  1.10.0
     */
    public const ABANDONED_AFTER_HOURS = 24;

    /**
     * The moment before which silence counts as abandonment.
     *
     * Read ONCE per operation and passed to everything that needs it. The whole
     * point of this class is that the same instant reaches the count, the filter
     * and the badge.
     *
     * @return string The cutoff, UTC, in the format the column stores.
     *
     * @since  1.10.0
     */
    public static function cutoff(): string
    {
        return Factory::getDate('now', 'UTC')
            ->modify('-' . self::ABANDONED_AFTER_HOURS . ' hours')
            ->toSql(false);
    }

    /**
     * "This cart holds something."
     *
     * EXISTS rather than a join, so a cart with twelve lines is still one row,
     * and correlated so it can be reused inside a SUM(CASE ...) as easily as in a
     * WHERE.
     *
     * @param DatabaseInterface $db The connection, for quoting.
     * @param string $alias The cart table's alias in the outer query.
     *
     *
     * @since  1.10.0
     */
    public static function hasLines(DatabaseInterface $db, string $alias = 'a'): string
    {
        return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_cart_items', 'cx')
            . ' WHERE ' . $db->quoteName('cx.id_cart') . ' = ' . $db->quoteName($alias . '.id') . ')';
    }

    /**
     * The SQL for one bucket, as a boolean expression.
     *
     * Written as a whole self-contained term. The caller used to negate the
     * has-lines predicate by string concatenation (`'NOT ' . $predicate`),
     * which is safe only for as long as the predicate is a single term, and
     * silently changes meaning the moment it is not.
     *
     * @param DatabaseInterface $db The connection, for quoting.
     * @param string $bucket 'active', 'abandoned' or 'empty'.
     * @param string $cutoff The instant this operation measures from.
     * @param string $alias The cart table's alias.
     *
     *
     * @since  1.10.0
     */
    public static function predicate(DatabaseInterface $db, string $bucket, string $cutoff, string $alias = 'a'): string
    {
        $hasLines = self::hasLines($db, $alias);
        $updated = $db->quoteName($alias . '.updated');
        $quoted = $db->quote($cutoff);

        return match ($bucket) {
            'active' => '(' . $hasLines . ' AND ' . $updated . ' >= ' . $quoted . ')',
            'abandoned' => '(' . $hasLines . ' AND ' . $updated . ' < ' . $quoted . ')',
            'empty' => '(NOT ' . $hasLines . ')',
            default => '1 = 1',
        };
    }

    /**
     * The same question, asked of a row already in hand.
     *
     * The badge on a rendered row must not re-derive this from a clock of its
     * own; that was the third instant.
     *
     * @param object $cart The row, carrying `updated` and a line count.
     * @param string $cutoff The instant this operation measures from.
     *
     * @return string 'active', 'abandoned' or 'empty'.
     *
     * @since  1.10.0
     */
    public static function bucketOf(object $cart, string $cutoff): string
    {
        if ((int) ($cart->line_count ?? 0) <= 0) {
            return 'empty';
        }

        return (string) ($cart->updated ?? '') < $cutoff ? 'abandoned' : 'active';
    }
}
