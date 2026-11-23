<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractImmutableEvent;

/**
 * The stored price index changed: `onAlfaPricesChanged`, raised by the index writer
 * ({@see \Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService}) once its rows are committed, so a listener
 * never hears of rows a rollback took back.
 *
 * ONE EVENT PER WRITE, NOT PER ROW: the products whose stored rows changed (itemIds()), why (cause() and causeId()), the
 * sets of pricing groups written (groupSetIds()) and one {@see PriceChange} per changed row (changes()): a row whose
 * final price or window end differs, a new row, a removed row. The chunks of one write are merged; the event goes out
 * after the outermost commit that holds them. A WRITE LARGER THAN THE LIMIT is announced by several events (more than
 * PriceIndexSyncService::ANNOUNCE_LIMIT changed rows outside a caller's transaction, each event delivered as its chunks
 * commit): each is exact for its rows, with the same cause and cause id, and no row is in two of them. Inside a
 * caller's transaction the rows wait for its commit. A request may make two writes, and so raise two events: a product
 * save that also archives the product (its rows changed, then removed), a delete of products some of which were sold
 * (those archived, the others removed).
 *
 * A STORED PERIOD THAT BEGINS (cause window): nothing is written when a period stored ahead of time becomes the
 * one read, so the task runner announces it at its first turn at least a minute after the moment
 * (PriceIndexSyncService::announceOpenedWindows(); the minute is longer than a chunk of the writer, which decides its
 * live period at its start and commits after): one PriceChange per slot whose final price moved, the period that
 * ended as the old price (none for a slot with no period before it), the one that began as the new; a slot whose price
 * stayed the same is not named. On a shop with visitors or cron, within about two minutes of the moment; on an idle
 * shop, at its next turn. A moment of more than the limit goes out as several exact events. The index stores every
 * period that the product's own prices and the discounts and taxes reaching it define, with no horizon, so every
 * period that begins is announced. The cursor (the #__alfa_meta row prices.windows_announced_until)
 * decides what is owed: a restored backup announces again the periods that began since its cursor and that its index
 * holds (what the shop reads again); a clock set forward is caught at the next turn; a clock set back announces
 * nothing until the clock passes the cursor again; a period edited after it began is announced by the edit's own
 * write (with its cause), one edited before it begins when its new moment comes.
 *
 * PREPARED PRICES GO LIVE WHOLE: a pricing context's first preparation, and the next generation a change of a rule, a
 * tax or a place prepares beside the live prices, are written chunk by chunk where no reader reads them, and none of
 * those chunks is announced. Their switch is, once it has committed: one PriceChange per row a switched generation holds
 * that differs from the live generation it replaced (each row of a first preparation, which replaced none), with the
 * cause set unless the write that started it switched it in its own request; large switches go out as several exact
 * events, as any write does. So a listener hears a price when shoppers can read it, never before.
 *
 * WHAT IT DOES NOT COVER: the prices the product page and the cart compute live from their rules, and
 * whether a place may buy (decided when prices are read). Nor are two removals no customer reads: the rows of a set of
 * pricing groups nobody holds any more, dropped with the set, and the rows of a deleted place (a listener learns of a
 * deleted place from the administrator's delete event, onAlfaAdminAfterDelete). A product deleted outright is
 * announced: its rows are removed through the writer before the product row goes.
 *
 * Detection costs one read of a chunk's rows before they are rewritten, and runs only while something listens: a shop
 * with no listener pays nothing. A broadcast in an after phase, isolated: every enabled plugin that lists it,
 * a system plugin included, hears it once; a listener's failure is logged and the next one runs. A SIGNAL, not a job:
 * no retry, so a listener does its work quickly or hands it to its own queue. A listener's own price write raises its
 * own event, delivered after the one it is hearing ends, so every listener hears the events in the order their writes
 * committed; a listener that writes prices on every event it hears loops, and that is the listener's to prevent.
 *
 * @since  2.2.0
 */
final class PricesChangedEvent extends AbstractImmutableEvent
{
    /**
     * @var string The event's name.
     * @since  2.2.0
     */
    public const NAME = 'onAlfaPricesChanged';

    /**
     * @var string A product's save, publish, unpublish, trash or delete, a batch change of products' categories or
     *             manufacturer, and an item field's change rebuilding its products; causeId the product when one was named,
     *             else 0.
     * @since  2.2.0
     */
    public const CAUSE_ITEM = 'item';

    /**
     * @var string A discount's save, publish, unpublish or delete; causeId the discount, 0 for several deleted.
     * @since  2.2.0
     */
    public const CAUSE_DISCOUNT = 'discount';

    /**
     * @var string A tax's save, publish, unpublish or delete; causeId the tax, 0 for several deleted.
     * @since  2.2.0
     */
    public const CAUSE_TAX = 'tax';

    /**
     * @var string A category's save, rename, state change or delete (the products assigned to it); causeId the category,
     *             0 for several.
     * @since  2.2.0
     */
    public const CAUSE_CATEGORY = 'category';

    /**
     * @var string A manufacturer's save, inline rename, state change or delete (its products); causeId the manufacturer,
     *             0 for several.
     * @since  2.2.0
     */
    public const CAUSE_MANUFACTURER = 'manufacturer';

    /**
     * @var string Prepared prices went live: a switch of the index's generations (a pricing context's first
     *             preparation, or the next generations a change prepared beside the live prices, switched together);
     *             causeId the generation whose last chunk made the switch, 0 when the background tidy made it. A switch
     *             made in the request of the write that started it (a rule's save) carries that write's cause instead.
     * @since  2.2.0
     */
    public const CAUSE_SET = 'set';

    /**
     * @var string A rebuild of the index (the catalogue job, a full rewrite); causeId 0.
     * @since  2.2.0
     */
    public const CAUSE_REBUILD = 'rebuild';

    /**
     * @var string A stored price period began: a period of a price, a discount or a tax stored ahead of time is now the
     *             one read; announced by the task runner, not by a write; causeId 0.
     * @since  2.2.2
     */
    public const CAUSE_WINDOW = 'window';

    /**
     * The products whose stored rows changed, ascending.
     *
     * @return int[]
     *
     * @since  2.2.0
     */
    public function itemIds(): array
    {
        return array_values(array_map('intval', (array) ($this->arguments['itemIds'] ?? [])));
    }

    /**
     * Why the rows were written: one of the CAUSE_ constants.
     *
     * @since  2.2.0
     */
    public function cause(): string
    {
        return (string) ($this->arguments['cause'] ?? self::CAUSE_REBUILD);
    }

    /**
     * The id of what caused the write (the product, discount, tax, category or manufacturer, or the pricing context a
     * preparation chunk wrote); 0 for a rebuild.
     *
     * @since  2.2.0
     */
    public function causeId(): int
    {
        return (int) ($this->arguments['causeId'] ?? 0);
    }

    /**
     * The sets of pricing groups the write wrote (its committed chunks), ascending.
     *
     * @return int[]
     *
     * @since  2.2.0
     */
    public function groupSetIds(): array
    {
        return array_values(array_map('intval', (array) ($this->arguments['groupSetIds'] ?? [])));
    }

    /**
     * One entry per changed row.
     *
     * @return PriceChange[]
     *
     * @since  2.2.0
     */
    public function changes(): array
    {
        return array_values(array_filter((array) ($this->arguments['changes'] ?? []), static fn ($change): bool => $change instanceof PriceChange));
    }
}
