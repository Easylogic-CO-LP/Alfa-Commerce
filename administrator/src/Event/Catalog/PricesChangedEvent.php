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
 * The stored price index changed (2.2.0): `onAlfaPricesChanged`, raised by the index writer
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
 * WHAT IT DOES NOT COVER: a stored window that opens by itself (nothing is written; a listener reads validFrom and
 * validUntil to know of future windows), the prices the product page and the cart compute live from their rules, and
 * whether a place may buy (decided when prices are read). Nor are two removals no customer reads: the rows of a set of
 * pricing groups nobody holds any more, dropped with the set, and the rows of a deleted place (a listener learns of a
 * deleted place from the administrator's delete event, onAlfaAdminAfterDelete). A product deleted outright is
 * announced: its rows are removed through the writer before the product row goes.
 *
 * Detection costs one read of a chunk's rows before they are rewritten, and runs only while something listens: a shop
 * with no listener pays nothing. A broadcast in an after phase (mode A, isolated): every enabled plugin that lists it,
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
     * @var string A chunk of a set of pricing groups' preparation; causeId the set.
     * @since  2.2.0
     */
    public const CAUSE_SET = 'set';

    /**
     * @var string A rebuild of the index (the catalogue job, a full rewrite); causeId 0.
     * @since  2.2.0
     */
    public const CAUSE_REBUILD = 'rebuild';

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
     * The id of what caused the write (the product, discount, tax, category, manufacturer or set); 0 for a rebuild.
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
