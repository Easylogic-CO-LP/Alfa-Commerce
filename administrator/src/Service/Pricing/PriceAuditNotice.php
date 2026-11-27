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
 * The bell notice of the price check: raised when the daily check finds something, brought up to date when the kinds
 * of finding change, withdrawn when nothing is left (ConditionNotice::follow()). Its facts are the kinds of finding,
 * not their counts, so a count that moves within the same kinds does not ring the bell again; a new kind does, unread,
 * even when the last notice was dismissed. Super Users see it and open the price check from it.
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
     * Follow a report: raise, update or withdraw the notice.
     *
     * @param array $report A report of PriceAudit.
     *
     * @return list<string> The kinds of finding it names; empty when it is withdrawn.
     *
     * @since  2.3.0
     */
    public static function follow(array $report): array
    {
        $facts = array_keys(PriceAudit::counts(report: $report));

        ConditionNotice::follow(
            key: self::KEY,
            facts: $facts,
            push: static fn () => NotificationHelper::push(
                dedupKey: self::KEY,
                title: NotificationText::of('COM_ALFA_NOTIFY_PRICE_AUDIT_TITLE'),
                options: [
                    'group' => NotificationHelper::GROUP_GENERAL,
                    'severity' => 'warning',
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_PRICE_AUDIT_MSG', PriceAudit::sentence(report: $report)),
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
