<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\CMS\Factory;

/**
 * How long a cart is kept, and when it stops being kept.
 *
 * The cart's whole life is: CREATED -> ACTIVE -> ABANDONED -> RETAINED -> PURGED.
 * The first three are questions asked of the row and answered by CartActivity;
 * this class owns the last two, which are the only ones that DELETE anything.
 *
 * TWO WINDOWS, because two different things are being kept:
 *
 *   AN EMPTY CART is a row and nothing else. It is created the moment anyone
 *   touches a method that needs one (choosing a shipping option, a bot walking
 *   the checkout), and it records no intent at all. Keeping it for a week is
 *   generous; keeping it forever is how a table of them grows without limit.
 *
 *   A CART WITH LINES records that someone chose something. That is worth
 *   keeping long enough to be useful (for the shopper who comes back, and for
 *   the merchant who wants to know what goes unbought), but not indefinitely,
 *   because it also holds what the shopper was doing, and data nobody has a
 *   reason to keep is data with a lifetime.
 *
 * NEVER BY OWNERSHIP. A guest cart is not expired for being a guest cart: 25 of
 * 26 carts on a typical install are guests, and treating "guest" as "disposable"
 * would purge the shop's live shopping every night.
 *
 * A FLOOR, NOT JUST A DEFAULT. Windows arrive as numbers from callers, and a
 * zero (a fat-fingered config, an uninitialised param) would mean "delete
 * everything that exists". Anything under the floor is refused outright rather
 * than clamped, because a caller asking for something that dangerous is a caller
 * whose intent cannot be guessed.
 *
 * @since  2.0.0
 */
final class CartRetention
{
    /**
     * Days of silence after which an EMPTY cart is disposable.
     *
     * @since  2.0.0
     */
    public const EMPTY_RETENTION_DAYS = 7;

    /**
     * Days of silence after which a cart WITH LINES is disposable.
     *
     * @since  2.0.0
     */
    public const LINES_RETENTION_DAYS = 90;

    /**
     * The shortest window that will ever be honoured.
     *
     * A cart untouched for a day is an ordinary overnight cart, so anything
     * below this is refused. It is the difference between a retention policy
     * and an accident.
     *
     * @since  2.0.0
     */
    public const MINIMUM_RETENTION_DAYS = 1;

    /**
     * @param int $emptyAfterDays Silence after which an empty cart goes.
     * @param int $linesAfterDays Silence after which a cart with lines goes.
     *
     * @throws InvalidArgumentException When either window is below the floor.
     *
     * @since  2.0.0
     */
    public function __construct(
        private int $emptyAfterDays = self::EMPTY_RETENTION_DAYS,
        private int $linesAfterDays = self::LINES_RETENTION_DAYS,
    ) {
        foreach (['empty' => $emptyAfterDays, 'with lines' => $linesAfterDays] as $what => $days) {
            if ($days < self::MINIMUM_RETENTION_DAYS) {
                throw new InvalidArgumentException(\sprintf(
                    'Refusing a retention window of %d day(s) for %s carts: the floor is %d.',
                    $days,
                    $what,
                    self::MINIMUM_RETENTION_DAYS,
                ));
            }
        }
    }

    /**
     * The instant before which an EMPTY cart's silence makes it disposable.
     *
     * @param string|null $now The moment to measure from; the clock when omitted.
     *
     *
     * @since  2.0.0
     */
    public function emptyExpiredBefore(?string $now = null): string
    {
        return $this->minus($this->emptyAfterDays, $now);
    }

    /**
     * The instant before which a cart WITH LINES becomes disposable.
     *
     * @param string|null $now The moment to measure from; the clock when omitted.
     *
     *
     * @since  2.0.0
     */
    public function linesExpiredBefore(?string $now = null): string
    {
        return $this->minus($this->linesAfterDays, $now);
    }

    /**
     * Is this cart disposable, measured from the given instants?
     *
     * The same decision the SQL makes, so a caller can explain a row without
     * asking the database again, and so a test can assert the two agree.
     *
     * @param object $cart The row, carrying `updated` and a line count.
     * @param string $emptyBefore From emptyExpiredBefore().
     * @param string $linesBefore From linesExpiredBefore().
     *
     *
     * @since  2.0.0
     */
    public static function isExpired(object $cart, string $emptyBefore, string $linesBefore): bool
    {
        $updated = (string) ($cart->updated ?? '');

        if ($updated === '') {
            return false;
        }

        return (int) ($cart->line_count ?? 0) <= 0
            ? $updated < $emptyBefore
            : $updated < $linesBefore;
    }

    /**
     * @return array{empty: int, lines: int, floor: int} The windows in force.
     *
     * @since  2.0.0
     */
    public function windows(): array
    {
        return [
            'empty' => $this->emptyAfterDays,
            'lines' => $this->linesAfterDays,
            'floor' => self::MINIMUM_RETENTION_DAYS,
        ];
    }

    /**
     * @param int $days How far back.
     * @param string|null $now The moment to measure from.
     *
     *
     * @since  2.0.0
     */
    private function minus(int $days, ?string $now): string
    {
        return Factory::getDate($now ?? 'now', 'UTC')->modify('-' . $days . ' days')->toSql(false);
    }
}
