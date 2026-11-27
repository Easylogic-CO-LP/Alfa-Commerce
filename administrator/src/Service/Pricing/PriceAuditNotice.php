<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Service\Setup\ConditionNotice;

/**
 * The bell notice of the price check, by the rule of the dashboard's Prices tile (PriceAuditStore::attention()): raised
 * when the stored result has findings or the last run failed, brought up to date when that changes, withdrawn when
 * neither is left (ConditionNotice::follow()); never for a check that never ran. Written by the check's job from the
 * result it just stored, and when a run fails for good or staff resolve a failed one, never from a page. Its facts are
 * the kinds of finding and the failure, not their counts, so a count that moves within the same kinds does not ring
 * the bell again; a new kind or a failure does, unread, even when the last notice was dismissed. Super Users see it and
 * open the price check from it.
 *
 * @since  2.3.0
 */
final class PriceAuditNotice
{
    /**
     * The notice's dedup key.
     *
     * @since  2.3.0
     */
    public const KEY = 'price-audit';

    /**
     * The fact of a last run that failed, beside the kinds of finding.
     *
     * @since  2.3.0
     */
    public const FACT_FAILED = 'failed';

    /**
     * Follow where the check stands: raise, update or withdraw the notice.
     *
     * @param array $status A status, as PriceAuditStore::status() answers it.
     *
     * @return list<string> The facts it names: the kinds of finding, and FACT_FAILED for a failed last run; empty when
     *                      it is withdrawn.
     *
     * @since  2.3.0
     */
    public static function follow(array $status): array
    {
        $attention = PriceAuditStore::attention(status: $status);
        $facts = array_keys($attention['counts']);
        $sentences = [];

        if ($attention['counts'] !== []) {
            $sentences[] = NotificationText::key('COM_ALFA_NOTIFY_PRICE_AUDIT_MSG', PriceAudit::sentence(summary: (array) $status['summary']));
        }

        if ($attention['failure'] !== '') {
            $facts[] = self::FACT_FAILED;
            $sentences[] = NotificationText::key('COM_ALFA_NOTIFY_PRICE_AUDIT_FAILED_MSG', $attention['failure']);
        }

        ConditionNotice::follow(
            key: self::KEY,
            facts: $facts,
            push: static fn () => NotificationHelper::push(
                dedupKey: self::KEY,
                title: NotificationText::of('COM_ALFA_NOTIFY_PRICE_AUDIT_TITLE'),
                options: [
                    'group' => NotificationHelper::GROUP_GENERAL,
                    'severity' => 'warning',
                    'message' => NotificationText::sentences(...$sentences),
                    'url' => 'index.php?option=com_alfa&view=priceaudit',
                    'dismissible' => true,
                    'view' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                ],
            ),
        );

        return $facts;
    }
}
