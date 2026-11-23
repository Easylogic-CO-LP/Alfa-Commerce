<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin\Traits;

use Alfa\Component\Alfa\Administrator\Helper\ActionLogHelper;
use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A row in Joomla's User Actions Log, in the plugin's own words.
 *
 * Shared by the payments and shipments families: a refund started, a booking sent, a setting a gateway changed with
 * its provider are acts an owner audits in one place, the User Actions Log, beside Joomla's own. A trait rather than
 * the base Plugin class, as the order's context is: the other families have no use for it.
 *
 * @since  2.2.1
 */
trait HasActionLogTrait
{
    /**
     * Write one row of Joomla's User Actions Log, filed under this plugin, and say whether it is there.
     *
     * The row is filed under plg_<group>_<element>, so the User Actions Log screen renders $languageKey from this
     * plugin's own language file (the administrator's, or the system file) with the facts; the acting user is the
     * request's (user 0 when none acted, as for a provider's notification). Written the way Joomla writes its own rows
     * ({@see ActionLogHelper::write()}); a failure is logged and answered false, never thrown. The row is never taken
     * back: for an act that may still fail after its row is written, use recordActionLogPending().
     *
     * Usage:
     *   $this->recordActionLog('PLG_ALFA_PAYMENTS_<YOU>_LOG_REFUND_STARTED', ['title' => '#' . $orderId, 'amount' => $amount->format()], $orderId);
     *
     * with, in the plugin's language file, a sentence that names the facts:
     *   PLG_ALFA_PAYMENTS_<YOU>_LOG_REFUND_STARTED="User <a href=\"{accountlink}\">{username}</a> started a refund of {amount} on order {title}"
     *
     * @api ANSWERS: true when the row is written under this plugin, false when not; never throws.
     *
     * @param string $languageKey The message's language key, the plugin's own.
     * @param array<string, string|int|float|bool> $facts The sentence's facts, by name; scalar values only (userid,
     *                                                    username and accountlink are the acting user's, applied last
     *                                                    over a fact of the same name).
     * @param int $itemId The id of the thing acted on (an order, a payment), 0 when none.
     *
     * @return bool True when the row is written; false when it is not (nothing written, the reason logged).
     *
     * @since  2.2.1
     */
    protected function recordActionLog(string $languageKey, array $facts = [], int $itemId = 0): bool
    {
        $actor = $this->getApplication()?->getIdentity() ?? new User(0);

        return ActionLogHelper::write(
            extension: 'plg_' . $this->_type . '_' . $this->_name,
            languageKey: $languageKey,
            facts: $facts,
            itemId: $itemId,
            actor: $actor,
        );
    }

    /**
     * Write the User Actions Log row of an act before it is made, and answer the row's id, so the row can be taken
     * back if the act does not stand: a staff close is never made without its trail, and a trail never stays for a
     * close that was not made.
     *
     * The row is written as recordActionLog() writes it. Make the act, then, if it did not stand, withdraw the row with
     * withdrawActionLog($rowId). A row not withdrawn by the end of the request stays, as every other row.
     *
     * Usage:
     *   $rowId = $this->recordActionLogPending('PLG_ALFA_PAYMENTS_<YOU>_LOG_CAPTURE_CLOSED', ['title' => '#' . $orderId], $orderId);
     *   $verdict = $this->settleAttemptVerdict($write, $orderId, $txId);
     *   if ($verdict->kind !== self::VERDICT_WRITTEN && $rowId !== null && !$this->withdrawActionLog($rowId)) {
     *       ...tell staff: the close was not made, and its audit line could not be withdrawn: entry #$rowId stands...
     *   }
     *
     * @api ANSWERS: the row's id when it is written under this plugin, null when not; never throws.
     *
     * @param string $languageKey The message's language key, the plugin's own.
     * @param array<string, string|int|float|bool> $facts The sentence's facts, by name; scalar values only.
     * @param int $itemId The id of the thing acted on (an order, a payment), 0 when none.
     *
     * @return int|null The row's id; null when it is not written (nothing written, the reason logged).
     *
     * @since  2.3.0
     */
    protected function recordActionLogPending(string $languageKey, array $facts = [], int $itemId = 0): ?int
    {
        $actor = $this->getApplication()?->getIdentity() ?? new User(0);

        return ActionLogHelper::writePending(
            extension: 'plg_' . $this->_type . '_' . $this->_name,
            languageKey: $languageKey,
            facts: $facts,
            itemId: $itemId,
            actor: $actor,
        );
    }

    /**
     * Take back the User Actions Log row this plugin wrote with recordActionLogPending() in this request, because the
     * act it records did not stand. Only that row: any other row (another plugin's, one written by recordActionLog(), one
     * of an earlier request, one already withdrawn) is refused and nothing is deleted.
     *
     * Answer false means the row stands: tell staff so ("the close was not made, and its audit line could not be
     * withdrawn: entry #N stands").
     *
     * @api ANSWERS: true when the row is gone; false when it is not this plugin's pending row of this request or the
     *      delete failed (logged); never throws.
     *
     * @param int $rowId The id recordActionLogPending() answered.
     *
     * @return bool True when the row was deleted.
     *
     * @since  2.3.0
     */
    protected function withdrawActionLog(int $rowId): bool
    {
        return ActionLogHelper::withdraw(extension: 'plg_' . $this->_type . '_' . $this->_name, rowId: $rowId);
    }
}
