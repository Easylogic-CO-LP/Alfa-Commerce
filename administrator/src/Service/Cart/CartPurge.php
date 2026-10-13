<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

/**
 * The end of the cart lifecycle, run as an operation.
 *
 * CartRetention says WHAT is expired. CartRepository knows HOW to remove one.
 * This is the operation that puts them together, and everything about it is
 * shaped by the fact that it deletes things nobody asked it to delete:
 *
 *   ONE INSTANT PER RUN. Both windows are measured from the same moment, so a
 *   run cannot be internally inconsistent, and a run can be explained
 *   afterwards from the instant it recorded.
 *
 *   BOUNDED. A limit per run, so a table with a million dead carts is worked
 *   through in bites instead of one statement holding locks over all of them.
 *   The result says whether there was more, so a caller can decide to go again.
 *
 *   IDEMPOTENT. Running it twice removes nothing the second time, because what
 *   it removed is gone and what it skipped was skipped for a reason that a
 *   second pass will reach the same way.
 *
 *   IT LOSES RACES ON PURPOSE. A cart chosen a moment ago may be in use right
 *   now; the delete carries the same silence test that selected it, so the
 *   shopper wins and the run reports a skip.
 *
 *   IT CAN BE ASKED WITHOUT BEING OBEYED. A dry run answers exactly what a real
 *   run would take, which is the only honest way to introduce a thing like this
 *   to a live shop.
 *
 * @since  2.0.0
 */
final class CartPurge
{
    /**
     * @param CartRepository $repository The only thing that deletes.
     * @param CartRetention $retention The policy.
     *
     * @since  2.0.0
     */
    public function __construct(
        private CartRepository $repository,
        private CartRetention $retention,
    ) {
    }

    /**
     * Take one bounded pass over the expired carts.
     *
     * @param int $limit The most carts to consider in this pass.
     * @param bool $dryRun Report what would go, and take nothing.
     * @param string|null $now The moment to measure from; the clock when omitted.
     *
     * @return array{
     *     examined: int, purged: int, skipped: int, more: bool, dryRun: bool,
     *     ids: int[], emptyBefore: string, linesBefore: string, windows: array
     * }
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  2.0.0
     */
    public function run(int $limit = 200, bool $dryRun = false, ?string $now = null): array
    {
        // ONE reading of the clock, used by the search and by every delete it
        // leads to. Two readings would let a cart be selected under one instant
        // and refused under another, which reads as a mysterious skip.
        $now ??= Factory::getDate('now', 'UTC')->toSql(false);

        $emptyBefore = $this->retention->emptyExpiredBefore($now);
        $linesBefore = $this->retention->linesExpiredBefore($now);

        $candidates = $this->repository->findExpiredCartIds($emptyBefore, $linesBefore, $limit);

        $purged = 0;
        $skipped = 0;
        $taken = [];

        foreach ($candidates as $cartId) {
            if ($dryRun) {
                $taken[] = $cartId;
                continue;
            }

            // The LATER of the two instants is the safe one to test against: a
            // cart with lines is expired only if it is older than the longer
            // window, and an empty one older than the shorter, so requiring
            // silence since the later instant can never take a cart that the
            // search would not have offered.
            if ($this->repository->purgeCart($cartId, max($emptyBefore, $linesBefore))) {
                $purged++;
                $taken[] = $cartId;

                continue;
            }

            $skipped++;
        }

        $result = [
            'examined' => \count($candidates),
            'purged' => $purged,
            'skipped' => $skipped,
            'more' => \count($candidates) === $limit,
            'dryRun' => $dryRun,
            'ids' => $taken,
            'emptyBefore' => $emptyBefore,
            'linesBefore' => $linesBefore,
            'windows' => $this->retention->windows(),
        ];

        $this->report($result);

        return $result;
    }

    /**
     * Say what happened, every time, including nothing.
     *
     * A retention job that only speaks when it deletes is a job nobody can tell
     * apart from a job that has stopped running.
     *
     * @param array $result The pass's outcome.
     *
     *
     * @since  2.0.0
     */
    private function report(array $result): void
    {
        AlfaLog::register();
        Log::add(
            \sprintf(
                'Cart purge%s: examined %d, purged %d, skipped %d%s. Windows: empty %dd (before %s), with lines %dd (before %s).',
                $result['dryRun'] ? ' (dry run)' : '',
                $result['examined'],
                $result['purged'],
                $result['skipped'],
                $result['more'] ? ', more remain' : '',
                $result['windows']['empty'],
                $result['emptyBefore'],
                $result['windows']['lines'],
                $result['linesBefore'],
            ),
            Log::INFO,
            'com_alfa.cart',
        );
    }
}
