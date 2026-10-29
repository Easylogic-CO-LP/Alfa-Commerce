<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Service\Order\ItemFieldsSnapshot;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * The back-office notification that an item's stock figures need the audited repair.
 *
 * Raised only by the inventory kernel: when it refuses a command on a unit whose
 * figures claim stock the records do not hold, when a return or credit restore
 * proceeds on such a unit, and when a structural change is refused because the
 * figures disagree with the records. Never raised by a read path or a storefront
 * render.
 *
 * Queued items are pushed once per request by a shutdown flush, so the notification
 * is written outside the transaction whose command was refused and survives its
 * rollback. One notification per item (deduplicated by key), shown to Super Users
 * only, cleared by the audited repair and after the item is deleted.
 *
 * @since  2.0.0
 */
final class InventoryIntegrityNotice
{
    public const KIND_LEDGER_MISSING = 'ledger_missing';
    public const KIND_FIGURES_DISAGREE = 'figures_disagree';
    public const KIND_RESTORED_ON_DEFECT = 'restored_on_defect';

    /** @var string Deduplication key prefix; the item id completes it. */
    private const KEY_PREFIX = 'stock-integrity:item:';

    /**
     * Items queued in this request, with the kinds raised for each.
     *
     * @var array<int, array<string, true>>
     */
    private static array $pending = [];

    /** @var bool Whether the shutdown flush is registered for this request. */
    private static bool $registered = false;

    /**
     * Queue the notification for an item; the first call of a request registers the flush.
     *
     * @param int $itemId The item whose figures need repair.
     * @param string $kind ledger_missing | figures_disagree | restored_on_defect.
     *
     *
     * @since  2.0.0
     */
    public static function queue(int $itemId, string $kind): void
    {
        if ($itemId <= 0) {
            return;
        }

        self::$pending[$itemId][$kind] = true;

        if (!self::$registered) {
            register_shutdown_function([self::class, 'flush']);
            self::$registered = true;
        }
    }

    /**
     * Push every queued item's notification, then forget the queue.
     *
     * Advisory: a failure is logged and never reaches the caller.
     *
     *
     * @since  2.0.0
     */
    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];

        if ($pending === []) {
            return;
        }

        foreach (array_keys($pending) as $itemId) {
            try {
                NotificationHelper::push(
                    dedupKey: self::key(itemId: $itemId),
                    title: NotificationText::of('COM_ALFA_NOTIFY_STOCK_INTEGRITY_TITLE', NotificationText::content(ItemFieldsSnapshot::freezeItemName($itemId, null))),
                    options: [
                        'group' => NotificationHelper::GROUP_INVENTORY,
                        'severity' => 'danger',
                        'constant' => true,
                        'message' => NotificationText::of('COM_ALFA_NOTIFY_STOCK_INTEGRITY_DESC'),
                        'url' => 'index.php?option=com_alfa&view=inventoryaudit',
                        'view' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                        'link' => ['action' => 'core.admin', 'asset' => 'com_alfa'],
                    ],
                );
            } catch (Throwable $e) {
                Log::add(
                    entry: sprintf('Inventory integrity notification for item #%d could not be written: %s', $itemId, $e->getMessage()),
                    priority: Log::WARNING,
                    category: 'com_alfa.inventory',
                );
            }
        }
    }

    /**
     * Remove an item's notification (and any queued push for it).
     *
     * @param int $itemId The item whose figures were repaired or which was deleted.
     *
     *
     * @since  2.0.0
     */
    public static function clear(int $itemId): void
    {
        unset(self::$pending[$itemId]);

        try {
            NotificationHelper::clear(dedupKey: self::key(itemId: $itemId), hard: true);
        } catch (Throwable $e) {
            Log::add(
                entry: sprintf('Inventory integrity notification for item #%d could not be cleared: %s', $itemId, $e->getMessage()),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }

    /**
     * The notification's deduplication key for an item.
     *
     * @param int $itemId The item.
     *
     *
     * @since  2.0.0
     */
    public static function key(int $itemId): string
    {
        return self::KEY_PREFIX . $itemId;
    }
}
