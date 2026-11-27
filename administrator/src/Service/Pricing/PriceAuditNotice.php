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
 * The bell notice of the price check: raised when a check finds something, brought up to date when the kinds of
 * finding change, withdrawn when nothing is left (ConditionNotice::follow()). Written by the check's job from the
 * result it just stored, never from a page. Its facts are the kinds of finding, not their counts, so a count that
 * moves within the same kinds does not ring the bell again; a new kind does, unread, even when the last notice was
 * dismissed. Super Users see it and open the price check from it.
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
     * Follow a stored result: raise, update or withdraw the notice.
     *
     * @param array<string, int> $counts The sections with findings and their counts (PriceAudit::counts()).
     * @param string $sentence The findings in one sentence (PriceAudit::sentence()).
     *
     * @return list<string> The kinds of finding it names; empty when it is withdrawn.
     *
     * @since  2.3.0
     */
    public static function follow(array $counts, string $sentence): array
    {
        $facts = array_keys($counts);

        ConditionNotice::follow(
            key: self::KEY,
            facts: $facts,
            push: static fn () => NotificationHelper::push(
                dedupKey: self::KEY,
                title: NotificationText::of('COM_ALFA_NOTIFY_PRICE_AUDIT_TITLE'),
                options: [
                    'group' => NotificationHelper::GROUP_GENERAL,
                    'severity' => 'warning',
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_PRICE_AUDIT_MSG', $sentence),
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
