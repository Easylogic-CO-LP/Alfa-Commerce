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
 * A row in Joomla's User Actions Log, in the plugin's own words (2.2.1).
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
     * ({@see ActionLogHelper::write()}); a failure is logged and answered false, never thrown. There is no delete: the
     * action log is an audit trail.
     *
     * Usage:
     *   $this->recordActionLog('PLG_ALFA_PAYMENTS_<YOU>_LOG_REFUND_STARTED', ['title' => '#' . $orderId, 'amount' => $amount->format()], $orderId);
     *
     * with, in the plugin's language file, a sentence that names the facts:
     *   PLG_ALFA_PAYMENTS_<YOU>_LOG_REFUND_STARTED="User <a href=\"{accountlink}\">{username}</a> started a refund of {amount} on order {title}"
     *
     * @param string $languageKey The message's language key, the plugin's own.
     * @param array<string, string|int|float|bool> $facts The sentence's facts, by name; scalar values only (userid,
     *                                                    username and accountlink are added).
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
}
