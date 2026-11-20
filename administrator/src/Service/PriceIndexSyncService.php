<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\PriceChange;
use Alfa\Component\Alfa\Administrator\Event\Catalog\PricesChangedEvent;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\OpeningCombination;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Projection\IndexRebuildLock;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Task\DueRead;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\CatalogueRebuildJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\PriceIndexTidyJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\PriceSetPrepareJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Site\Helper\PriceUnits;
use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\PricingIntent;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * PriceIndexSyncService: builds and maintains the price index, the resolved price of every unit a product sells in
 * every pricing context and period.
 *
 * The index (#__alfa_items_price_index) holds one row per pricing context, product, combination and period: what the
 * engine charges an anonymous customer of that context for one piece of the unit at the product's opening quantity
 * (its minimum, PricingIntent::catalog). The units of a product are the product itself (combination_id 0), always, and
 * each published combination that has price rows of its own; a combination without them is priced from the product's
 * rows, so the product's row is its price as well. Every unit has a row in every prepared context for every period; a
 * unit with no valid price there (no price row applies) has a row whose figures are NULL, so a sort places it last in
 * either direction and every price filter leaves it out by its own comparison. An explicit price of 0 is a price: the
 * product is free and its row holds 0. The listing reads the row whose price the product's card shows, kept on the
 * product as #__alfa_items.price_combination_id: the combination the card opens on when it has price rows of its own,
 * else 0 ({@see OpeningCombination}); a stock change that moves it moves no price row.
 *
 * Column naming. The figure columns use the names of the com_alfa price settings:
 *
 *   base_price                - unit price before discounts and tax
 *   discount_amount           - the saving, on the basis final_price is on; 0 = none
 *   base_price_with_discounts - after discounts, before tax
 *   tax_amount                - tax on the discounted price
 *   base_price_with_tax       - base price with tax, without discounts: the struck price
 *   final_price               - what the customer pays for one piece: the listing's filter and sort figure
 *   discount_percent          - the saving in percent, 0 to 100
 *
 * Every figure is the engine's exact decimal held at the column's eighth place (PriceUnits::exact(), then
 * ExactAmount::roundedAt()); no figure passes through a float.
 *
 * Contexts (#__alfa_price_contexts). A context is what makes two visitors pay the same for every product:
 *   - the currency class: a currency a published price row names, or 0 for every other currency, which exists only
 *     while a published price row of currency 0 exists (a visitor in an unnamed currency with no such row has no
 *     price from the engine and no context here);
 *   - the place class, a pair: rule_place_id, the nearest place of the visitor's chain (PlaceTree::placesFor()) that a
 *     published discount or tax names, 0 for none; price_place_id, the visitor's own place when a published price row
 *     names it, 0 otherwise. A class is priced at one of its member places (priced_at_place_id), never at a ruled place
 *     that has price rows of its own;
 *   - the set of pricing groups (#__alfa_price_group_sets): the visitor's groups that a price row, a discount or a tax
 *     names;
 *   - the customer (user_id): 0 for the context of a set; a customer that a price row, a
 *     discount or a tax names has contexts of their own, holding rows only for the products their rules touch, read
 *     before the set's (a NULL row there where their rules take the price away).
 * The period is not part of the context: each row carries valid_from and valid_until, and every period the price rows
 * (item and combination rows), the discounts and the taxes define is kept, with no horizon; equal
 * neighbouring periods of a unit are one row.
 *
 * Preparation. A context the data defines for the first time is REQUESTED by the write that brought it, with its
 * price.set.prepare job, and PREPARED by that job a chunk of products at a time, each chunk one transaction with the
 * context's checkpoint (prepared_through). A context is read only once prepared; until then its visitors read the
 * context they read before it existed ({@see self::readContext()}): their previous set or the guest's, the next ruled
 * place above, currency class 0. A context requested while the index is being built after the update, or while no
 * context is prepared at all (a new shop's first price, every published product then owed), has no previous answer to
 * keep and is prepared at once.
 *
 * Generations. A context-wide change is written as the next GENERATION of every context it reaches, beside the live
 * one, and switched whole when complete ({@see self::markPrepared()}): a rule or a tax whose scope is more than one chunk
 * and at least half the published products (every live context, prepared in the save's request within a budget, the
 * rest by the jobs), and a place moved, published or unpublished (the contexts priced at or below it, by the jobs);
 * a first generation still being prepared in their reach is started again from its first product. Readers read the
 * live generation (is_live) only; writes meanwhile go to the live one and, for the products it has passed, to the one
 * being prepared, so a product saved while a generation is prepared is read with the change before the switch. The retired generation's rows, and the periods that ended, are removed by the
 * price.index.tidy job (once a day, and after each switch).
 *
 * On an update, the previous index (the table with a place_id column) is renamed #__alfa_items_price_index_prev by the
 * installer and stays readable, with its readers, while the new index is built in the background by the catalogue job
 * (Tools > Rebuild catalogue projections shows its progress); the switch is the flag #__alfa_meta prices.index_build,
 * written before the rename: while that row exists the storefront reads the previous index, and the job removes it once
 * the completeness count passes ({@see self::finishBuild()}). A product whose pricing throws holds no switch: it gets
 * rows with no figure in every context, is recorded as left out (Tools names it), reads as a product with no price until
 * it is fixed, and stays owed. The switch is recorded (#__alfa_meta prices.index_switched), and the installer of the next
 * update after it drops the previous index; an installer run that stopped part way runs again with the next
 * installation and resumes the build.
 *
 * Every write of a product's rows, a preparation's chunk included, holds the product's lock (IndexRebuildLock): the
 * product, its units and the contexts' progress are read after the grant, and the rows commit before the release. The
 * edges a product's periods are cut at are its own price rows' and those of the discounts and taxes that can reach it.
 *
 * Public API
 *   syncItem($itemId)              - rewrite one product's rows (its save, its publish)
 *   syncItems($itemIds)            - rewrite some products' rows (a rule's scope, a category or manufacturer removed)
 *   syncItemsOrFail($itemIds)      - the same, a failure reaching the caller (the projection manager, the catalogue job)
 *   syncByDiscount($discountId)    - the products a discount can reach (its publish)
 *   syncByTax($taxId)              - the products a tax can reach (its publish)
 *   syncGroupSets()                - the contexts brought in line with the data (sets, currencies, places, customers)
 *   syncAll()                      - every published product
 *   deleteForItem(s)($itemIds)     - remove the products' rows (unpublished, trashed, removed)
 *   prepareContextChunk($id, $n)   - one chunk of a waiting context's preparation (the price.set.prepare job)
 *   alignContexts()                - the contexts brought in line (the job, at each claim)
 *   queueWaitingContexts()         - a job for every waiting context
 *   preparationState()             - the waiting contexts, each with its job, and the build (Tools)
 *   readContext($context)          - the prepared context (and customer context) one visitor reads (the read side)
 *   isBuilding()                   - whether the storefront still reads the previous index (the read side)
 *   completeness()                 - the completeness count, the tiling and the pointers (Tools, the switch)
 *   analyze()                      - the index's statistics refreshed after a full rebuild
 *   size()                         - the index's rows and bytes (Tools)
 *   finishBuild()                  - the switch from the previous index, when the new one is complete
 *   pricingGroups(), slotKey()     - the groups that change a price; the set of one visitor
 *   readableSetKey(), slotSetJoin(), slotPlaces(), slotRanking() - the previous index's reader, kept for the build
 *   oweChainRebuild($placeIds)     - the generations owed when places take another parent or another state
 *   removeRetired($n), removeEndedPeriods($n), tidyCount() - the tidy (the price.index.tidy job)
 *   startBuild(), writeBuildFlag(), switchedAt() - the build after the update (the installer)
 *
 * SYNC TRIGGER MAP
 *   ItemModel::save(), publish()  -> ProjectionManager -> syncItem() / deleteForItems() (its units, its pointer)
 *   DiscountModel, TaxModel save, publish, delete -> syncItems() over the old and new scope / syncByDiscount() /
 *                                    syncByTax() (a scope of at least half the catalogue: generations)
 *   CategoryModel::delete(), ManufacturerModel::delete() -> syncItems() (the products that lost the link)
 *   PlaceModel::save() (a new parent), publish() -> oweChainRebuild() (generations); PlaceModel::delete() -> syncGroupSets()
 *   A user group saved or removed, an account's groups changed, the users component's options saved -> syncGroupSets()
 *   An inventory kernel command (after its commit), the shop-wide stock settings saved -> the opening combination only
 *                                    (no price row)
 *
 * A failure of a write is logged and owed to the catalogue job (ProjectionManager::owe()); a save is never refused.
 *
 * THE PRICE-CHANGE EVENT (onAlfaPricesChanged, {@see PricesChangedEvent}). This writer is the only place that writes
 * the index, so it announces it: each public write runs as one announcement ({@see self::announcing()}); while
 * something listens, each chunk reads its rows before rewriting them, and the rows that changed join the announcement
 * once the chunk's level has committed; it goes out once, after the outermost commit (PostCommit). With no listener
 * nothing is read.
 *
 * @since  1.0.0
 */
class PriceIndexSyncService
{
    // Constants

    /** Products per chunk of the writer: one transaction, one load per context and period. */
    private const CHUNK_SIZE = 50;

    /** Items per removal statement. */
    private const REMOVE_CHUNK_SIZE = 100;

    /** Rows per multi-row INSERT. */
    private const INSERT_ROWS = 500;

    /** Seconds a rule's save prepares its generations for before it leaves the rest to their jobs. */
    private const GENERATION_SECONDS = 20.0;

    /**
     * The bound on one onAlfaPricesChanged: once a write outside a caller's transaction has collected
     * this many changed rows, what it holds goes out as its own event and the collection starts again.
     *
     * @since  2.2.0
     */
    public const ANNOUNCE_LIMIT = 5000;

    /** valid_from of the period that is live when the row is written. */
    public const EPOCH = '1000-01-01 00:00:00';

    /** The sets of pricing groups. */
    private const SETS_TABLE = '#__alfa_price_group_sets';

    /**
     * The pricing contexts of the index.
     *
     * @since  2.2.2
     */
    public const CONTEXTS_TABLE = '#__alfa_price_contexts';

    /**
     * The price index (keyed by context, product, combination and valid_from).
     *
     * @since  2.2.2
     */
    public const INDEX_TABLE = '#__alfa_items_price_index';

    /**
     * The previous index under the name the update gives it, read by the storefront while the new index is built
     * so the previous prices stay readable until the new ones are complete, dropped by the installer of the next update
     * after the switch.
     *
     * @since  2.2.2
     */
    public const PREVIOUS_TABLE = '#__alfa_items_price_index_prev';

    /**
     * The #__alfa_meta row written when the storefront switched from the previous index to this one: its value
     * the moment. Only once it exists does an update drop the previous index.
     *
     * @since  2.2.2
     */
    public const META_SWITCHED = 'prices.index_switched';

    /**
     * The #__alfa_meta fact of the sets of pricing groups, JSON: pricing, the pricing groups the sets customers
     * hold were last brought in line with; previous, while a change of those groups waits for the contexts it brought,
     * the pricing groups before it, else null.
     *
     * @since  2.2.2
     */
    public const META_GROUP_SETS = 'prices.group_sets';

    /**
     * The switch after the update: while this #__alfa_meta row exists the storefront reads the previous index;
     * its value is the moment the build began. Written by the update, removed by {@see self::finishBuild()}.
     *
     * @since  2.2.2
     */
    public const META_BUILD = 'prices.index_build';

    /** Whether this process's connection may concatenate a list of any length (the accounts' sets). */
    private static bool $longLists = false;

    /** What the last calculation that failed in writeChunk() raised. */
    private string $lastFailure = '';

    /** What stopped the last chunk of a preparation that was rolled back. */
    private ?Throwable $chunkFailure = null;

    /** The contexts the last alignment requested. */
    private int $lastRequested = 0;

    /**
     * The products each named customer's rules touch, for this write: by user id.
     *
     * @var array<int, array<int, true>>
     * @since  2.2.2
     */
    private array $customerProducts = [];

    /**
     * The write being announced: its cause, whether anything listens, and what its committed chunks changed.
     *
     * @var stdClass|null {cause: string, causeId: int, listening: bool, items: array<int, true>, sets: array<int, true>, changes: PriceChange[]}
     * @since  2.2.0
     */
    private static ?stdClass $announcement = null;

    /** Whether this request has logged the missing or unreadable cursor of the announced price periods. */
    private static bool $cursorWarned = false;

    /** Whether an onAlfaPricesChanged is being delivered now. */
    private static bool $delivering = false;

    /**
     * @var stdClass[] The announcements waiting for the running delivery to end.
     * @since  2.2.0
     */
    private static array $deliveries = [];

    /**
     * What the last chunk written changed, and the sets it wrote: held until the chunk's level commits.
     *
     * @var array{changes: PriceChange[], sets: int[]}
     * @since  2.2.0
     */
    private array $chunkChanges = ['changes' => [], 'sets' => []];

    /**
     * The groups a price row, a discount or a tax names, as read for this process.
     *
     * @var int[]|null
     * @since  2.0.0
     */
    private static ?array $pricingGroups = null;

    /**
     * The line of groups a visitor with no account holds, as read for this process.
     *
     * @var int[]|null
     * @since  2.2.2
     */
    private static ?array $guestLine = null;

    /**
     * The pricing groups before a change of them that waits for the contexts it brought, as read for this process.
     *
     * @var int[]|null
     * @since  2.2.2
     */
    private static ?array $previousPricing = null;

    /** Whether $previousPricing was read for this process. */
    private static bool $previousRead = false;

    /**
     * Whether the storefront reads the previous index, as read for this process; null until read.
     *
     * @since  2.2.2
     */
    private static ?bool $building = null;

    /**
     * The contexts the read side knows, per customer read (0 for the sets' contexts only), as read for this process.
     *
     * @var array<int, list<object>>
     * @since  2.2.2
     */
    private static array $readContexts = [];

    /**
     * The contexts each visitor reads, by the visitor's pricing key, for this process.
     *
     * @var array<string, array{context: int, customer: int}|null>
     * @since  2.2.2
     */
    private static array $resolved = [];

    // Dependencies

    /**
     * @var DatabaseDriver The connection, narrowed to the driver whose transaction levels TransactionState keeps.
     * @since  1.0.0
     */
    private DatabaseDriver $db;

    /** @var PriceCalculator */
    private $calculator;

    // Constructor

    /**
     * @param PriceCalculator|null $calculator Injected for testing; defaults to a new instance.
     * @since  1.0.0
     */
    public function __construct(?PriceCalculator $calculator = null)
    {
        $this->db = TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));
        $this->calculator = $calculator ?? new PriceCalculator();
    }

    // Public API: writes

    /**
     * Rewrite one product's rows in every prepared context (its save, its publish). All associated data must already
     * be committed.
     *
     * @param int $itemId The product.
     * @param string $cause Why, as onAlfaPricesChanged says it (a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @since  1.0.0
     */
    public function syncItem(int $itemId, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): void
    {
        if ($itemId <= 0) {
            return;
        }

        self::announcing(cause: $cause, causeId: $causeId, work: function () use ($itemId): void {
            try {
                $this->writeIndexRows(itemIds: [$itemId], contexts: $this->alignContexts());
            } catch (Throwable $e) {
                $this->logWarning("syncItem({$itemId}) failed: " . $e->getMessage());
                ProjectionManager::owe(itemIds: [$itemId]);
            }
        });
    }

    /**
     * Rewrite some products' rows. The discount and tax delete hooks collect the products before the delete and call
     * this after it, so the products are priced without the removed rule. A rule's scope of more than one chunk and at
     * least half the published products (cause discount or tax, outside a caller's transaction) is rewritten as the
     * next generation of every live context instead, each switched whole when complete.
     *
     * @param int[] $itemIds The products.
     * @param string $cause Why, as onAlfaPricesChanged says it.
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @return int Number of index rows written.
     *
     * @since  1.0.0
     */
    public function syncItems(array $itemIds, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): int
    {
        return self::announcing(cause: $cause, causeId: $causeId, work: function () use ($itemIds, $cause): int {
            // Not strict: a chunk whose calculation failed is rolled back, logged and owed; the others are written.
            try {
                // A rule's context-wide scope is rewritten as generations, each context switched whole.
                if (\in_array($cause, [PricesChangedEvent::CAUSE_DISCOUNT, PricesChangedEvent::CAUSE_TAX], true)
                    && !TransactionState::isOpen(db: $this->db) && $this->contextWide(scope: \count(array_unique(array_map('intval', $itemIds))))) {
                    $this->alignContexts();

                    return $this->rewriteAsGenerations();
                }

                return $this->rebuildItems(itemIds: $itemIds, strict: false);
            } catch (Throwable $e) {
                $this->logWarning('syncItems() failed: ' . $e->getMessage());
                ProjectionManager::owe(itemIds: $itemIds);

                return 0;
            }
        });
    }

    /**
     * Rewrite some products' rows and let a failure reach the caller (the projection manager's rebuilds, holding the
     * products' locks): the manager clears a product's mark only when its rebuild committed.
     *
     * @param int[] $itemIds The products.
     * @param string $cause Why, as onAlfaPricesChanged says it.
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @return int Number of index rows written.
     *
     * @throws Throwable Whatever the rebuild met.
     *
     * @since  2.2.0
     */
    public function syncItemsOrFail(array $itemIds, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): int
    {
        return self::announcing(cause: $cause, causeId: $causeId, work: fn (): int => $this->rebuildItems(itemIds: $itemIds, strict: true));
    }

    /**
     * The rewrite of syncItems() and syncItemsOrFail(): the contexts aligned, then the products written.
     *
     * @param int[] $itemIds The products.
     * @param bool $strict A chunk whose calculation failed throws; false owes it to the catalogue job.
     *
     * @return int Number of index rows written.
     *
     * @since  2.2.0
     */
    private function rebuildItems(array $itemIds, bool $strict): int
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));

        // Even with no product in scope: the rule may have named a group, a place or a customer for the first time.
        $contexts = $this->alignContexts();

        if ($itemIds === []) {
            return 0;
        }

        return $this->writeIndexRows(itemIds: $itemIds, contexts: $contexts, strict: $strict);
    }

    /**
     * Rewrite every product a discount can reach (its publish). A catalogue-wide discount rewrites every product.
     *
     * @param int $discountId The discount.
     *
     * @return int Number of index rows written.
     *
     * @since  1.0.0
     */
    public function syncByDiscount(int $discountId): int
    {
        if ($discountId <= 0) {
            return 0;
        }

        return self::announcing(cause: PricesChangedEvent::CAUSE_DISCOUNT, causeId: $discountId, work: fn (): int => $this->syncScope(itemIds: fn (): array => $this->getItemIdsForDiscount($discountId), label: "syncByDiscount({$discountId})"));
    }

    /**
     * Rewrite every product a tax can reach (its publish). A catalogue-wide tax rewrites every product.
     *
     * @param int $taxId The tax.
     *
     * @return int Number of index rows written.
     *
     * @since  1.0.0
     */
    public function syncByTax(int $taxId): int
    {
        if ($taxId <= 0) {
            return 0;
        }

        return self::announcing(cause: PricesChangedEvent::CAUSE_TAX, causeId: $taxId, work: fn (): int => $this->syncScope(itemIds: fn (): array => $this->getItemIdsForTax($taxId), label: "syncByTax({$taxId})"));
    }

    /**
     * The work of syncByDiscount() and syncByTax(), inside its announcement.
     *
     * @param callable(): int[] $itemIds The scope's products, read after the contexts are aligned.
     * @param string $label The call, for the log.
     *
     * @return int Number of index rows written.
     *
     * @since  2.2.2
     */
    private function syncScope(callable $itemIds, string $label): int
    {
        try {
            $contexts = $this->alignContexts();
            $ids = $itemIds();

            // A context-wide scope as generations, each context switched whole.
            if (!TransactionState::isOpen(db: $this->db) && $this->contextWide(scope: \count($ids))) {
                return $this->rewriteAsGenerations();
            }

            return $ids === [] ? 0 : $this->writeIndexRows(itemIds: $ids, contexts: $contexts);
        } catch (Throwable $e) {
            $this->logWarning("{$label} failed: " . $e->getMessage());

            return 0;
        }
    }

    /**
     * Bring the contexts in line with the data: the sets customers hold, the currencies and places the price rows and
     * the rules name, the customers they name. A context new to the data is requested with its job; a context the data
     * no longer defines is dropped with its rows. Nothing is rewritten for a change that leaves every context as it
     * was. Called by the sync plugin when the customers' groups may have changed outside a pricing row, and by the
     * place delete; every other write runs it first.
     *
     * @return int Number of contexts requested.
     *
     * @since  2.0.0
     */
    public function syncGroupSets(): int
    {
        try {
            $this->alignContexts();

            return $this->lastRequested;
        } catch (Throwable $e) {
            $this->logWarning(message: 'syncGroupSets() failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Rewrite every published product's rows in every prepared context, after the contexts are aligned. Idempotent: a
     * chunk replaces its rows whole from the authoritative data.
     *
     * @param string $cause Why, as onAlfaPricesChanged says it.
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @return int Total number of index rows written.
     *
     * @since  1.0.0
     */
    public function syncAll(string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): int
    {
        return self::announcing(cause: $cause, causeId: $causeId, work: function (): int {
            try {
                $contexts = $this->alignContexts();
                $written = $this->writeIndexRows(itemIds: $this->getAllPublishedItemIds(), contexts: $contexts);
                self::analyze();

                return $written;
            } catch (Throwable $e) {
                $this->logWarning('syncAll() failed: ' . $e->getMessage());

                return 0;
            }
        });
    }

    /**
     * Remove every index row of one product.
     *
     * @param int $itemId The product.
     * @param string $cause Why, as onAlfaPricesChanged says it.
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @since  1.0.0
     */
    public function deleteForItem(int $itemId, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): void
    {
        if ($itemId > 0) {
            $this->deleteForItems(itemIds: [$itemId], cause: $cause, causeId: $causeId);
        }
    }

    /**
     * Remove every index row of some products (unpublished, trashed or removed), a chunk at a time. Outside a
     * transaction a chunk that fails is logged and the others still go; inside one (a delete's) the failure is thrown
     * to the caller, whose transaction then rolls back whole.
     *
     * @param int[] $itemIds The products.
     * @param string $cause Why, as onAlfaPricesChanged says it.
     * @param int $causeId What caused it; 0 for a rebuild.
     *
     * @since  2.2.0
     */
    public function deleteForItems(array $itemIds, string $cause = PricesChangedEvent::CAUSE_REBUILD, int $causeId = 0): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $itemId): bool => $itemId > 0)));

        if ($itemIds === []) {
            return;
        }

        self::announcing(cause: $cause, causeId: $causeId, work: function () use ($itemIds): void {
            foreach (array_chunk($itemIds, self::REMOVE_CHUNK_SIZE) as $chunk) {
                try {
                    $before = self::detecting() ? $this->readIndexRows(itemIds: $chunk, contextIds: null) : null;
                    $this->db->setQuery(
                        $this->db->getQuery(true)
                            ->delete($this->db->quoteName(self::INDEX_TABLE))
                            ->whereIn($this->db->quoteName('item_id'), $chunk),
                    )->execute();

                    if ($before !== null) {
                        $terms = $this->contextTerms(ids: array_values(array_unique(array_map(static fn (array $row): int => $row['context'], $before))));
                        $this->chunkChanges = [
                            'changes' => self::rowChanges(before: $before, after: [], terms: $terms),
                            'sets' => array_values(array_unique(array_map(static fn (array $term): int => $term['set'], $terms))),
                        ];
                        $this->keepChunkChanges();
                    }
                } catch (Throwable $e) {
                    if (TransactionState::isOpen(db: $this->db)) {
                        throw $e;
                    }

                    $this->logWarning('deleteForItems() failed for a chunk of ' . \count($chunk) . ' item(s): ' . $e->getMessage());
                }
            }
        });
    }

    // Preparation of contexts

    /**
     * Prepare one chunk of a waiting context: the work of the price.set.prepare job, one chunk per call.
     *
     * From the context's checkpoint the next published products, at most $limit, are written for it and the
     * checkpoint moves past them in one transaction, the context's row held for it; when no product is left, or fewer
     * than asked were, the context is marked prepared and from then on it is read. A product whose pricing throws is
     * left out of the context and recorded on it.
     *
     * The chunk's item locks are taken first, ascending; a product whose lock another writer kept for the whole wait is
     * passed over and owed to the catalogue job, which writes it once the checkpoint has passed it.
     *
     * The state answered: chunk (more to come), prepared (the context is now read), gone (it no longer waits) or failed
     * (the chunk was rolled back; failure is what stopped it).
     *
     * @param int $contextId The context.
     * @param int $limit At most this many products.
     *
     * @return array{state: string, written: int, products: int, through: int, failure?: Throwable|null}
     *
     * @since  2.2.2
     */
    public function prepareContextChunk(int $contextId, int $limit): array
    {
        return self::announcing(cause: PricesChangedEvent::CAUSE_SET, causeId: $contextId, work: fn (): array => $this->prepareContextChunkAnnounced(contextId: $contextId, limit: $limit));
    }

    /**
     * The work of prepareContextChunk(), inside its announcement.
     *
     * @param int $contextId The context.
     * @param int $limit At most this many products.
     *
     * @return array{state: string, written: int, products: int, through: int, failure?: Throwable|null}
     *
     * @since  2.2.2
     */
    private function prepareContextChunkAnnounced(int $contextId, int $limit): array
    {
        $through = $this->waitingThrough(contextId: $contextId);

        if ($through === null) {
            return ['state' => 'gone', 'written' => 0, 'products' => 0, 'through' => 0];
        }

        $limit = max(1, $limit);
        $chunk = $this->publishedItemIdsAfter(after: $through, limit: $limit);

        if ($chunk === []) {
            return ['state' => $this->markPrepared(contextId: $contextId) === 1 ? $this->afterPrepared() : 'gone', 'written' => 0, 'products' => 0, 'through' => $through];
        }

        $this->chunkFailure = null;
        $lock = new IndexRebuildLock($this->db);
        $status = $lock->acquire(itemIds: $chunk);
        $granted = array_keys($status, IndexRebuildLock::GRANTED, true);
        $busy = array_keys($status, IndexRebuildLock::TIMEOUT, true);

        try {
            $context = $this->contextRows(ids: [$contextId], waitingOnly: true)[$contextId] ?? null;

            if ($context === null) {
                return ['state' => 'gone', 'written' => 0, 'products' => 0, 'through' => $through];
            }

            // The whole chunk is this preparation's: its checkpoint is passed in one go.
            $context['through'] = null;
            $leftOut = [];
            $written = $this->prepareChunk(
                contextId: $contextId,
                chunk: array_values(array_diff($chunk, $busy)),
                through: (int) max($chunk),
                contexts: [$context],
                leftOut: $leftOut,
            );
        } finally {
            $lock->release(itemIds: $granted);
        }

        if ($written !== null) {
            ProjectionManager::owe(itemIds: $busy);
        }

        if ($written === null) {
            return ['state' => $this->waitingThrough(contextId: $contextId) !== null ? 'failed' : 'gone', 'written' => 0, 'products' => 0, 'through' => $through, 'failure' => $this->chunkFailure];
        }

        if (\count($chunk) < $limit) {
            $state = $this->markPrepared(contextId: $contextId) === 1 ? $this->afterPrepared() : 'prepared';

            return ['state' => $state, 'written' => $written, 'products' => \count($chunk), 'through' => (int) max($chunk)];
        }

        return ['state' => 'chunk', 'written' => $written, 'products' => \count($chunk), 'through' => (int) max($chunk)];
    }

    /**
     * A context's checkpoint while it waits.
     *
     * @param int $contextId The context.
     *
     * @return int|null Null when the context is prepared or gone.
     *
     * @since  2.2.2
     */
    private function waitingThrough(int $contextId): ?int
    {
        $through = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('prepared_through') . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE)
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $contextId . ' AND ' . $this->db->quoteName('prepared_at') . ' IS NULL',
        )->loadResult();

        return $through === null ? null : (int) $through;
    }

    /**
     * After a context is marked prepared: while a change of the pricing groups waits for its contexts, the contexts
     * are aligned again, so the sets of the groups before it are dropped once no context waits.
     *
     * @return string The state the chunk answers: prepared.
     *
     * @since  2.2.2
     */
    private function afterPrepared(): string
    {
        try {
            if (self::groupSetFacts(db: $this->db)['previous'] !== null) {
                $this->alignContexts();
            }
        } catch (Throwable $e) {
            $this->logWarning(message: 'the sets left by a change of the pricing groups could not be dropped after a preparation: ' . $e->getMessage());
        }

        return 'prepared';
    }

    /**
     * How many products are published: what one context's preparation walks.
     *
     * @since  2.2.0
     */
    public function publishedItemCount(): int
    {
        return (int) $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_items') . ' WHERE ' . $this->db->quoteName('state') . ' = 1',
        )->loadResult();
    }

    /**
     * Whether a context waits for its preparation.
     *
     * @param int $contextId The context.
     *
     * @since  2.2.2
     */
    public function contextWaits(int $contextId): bool
    {
        return $contextId > 0 && $this->waitingThrough(contextId: $contextId) !== null;
    }

    /**
     * Bring the contexts in line with the data, as every write does: the price.set.prepare job asks it at each claim,
     * so memberships written where no event reaches the shop are followed too.
     *
     * @return array The contexts to write (see alignContexts()).
     *
     * @since  2.2.2
     */
    public function alignContexts(): array
    {
        return $this->alignedContexts();
    }

    /**
     * Give every waiting context its price.set.prepare job.
     *
     * @return int The waiting contexts, each with its job now.
     *
     * @since  2.2.2
     */
    public function queueWaitingContexts(): int
    {
        $ids = array_map('intval', $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE)
            . ' WHERE ' . $this->db->quoteName('prepared_at') . ' IS NULL ORDER BY ' . $this->db->quoteName('requested_at') . ', ' . $this->db->quoteName('id'),
        )->loadColumn() ?: []);

        foreach ($ids as $id) {
            PriceSetPrepareJob::enqueueFor(contextId: $id);
        }

        return \count($ids);
    }

    /**
     * What the staff are shown of the preparation: each waiting context with its groups, currency class, place,
     * customer, when it was requested, how many published products it holds and its job; the products a preparation
     * left out; and whether the storefront still reads the previous index (the build after the update).
     *
     * @return array{waiting: list<array{id: int, groups: int[], currency: int, place: int, user: int, requested_at: string, done: int, products: int, job: array{id: int, state: string, done: int, total: int, error: string}|null}>, leftOut: int[], building: string|null}
     *
     * @since  2.0.0
     */
    public static function preparationState(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $products = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . $db->quoteName('state') . ' = 1',
        )->loadResult();
        $waiting = [];

        foreach ($db->setQuery(
            'SELECT c.id, c.currency_id, c.priced_at_place_id, c.user_id, c.requested_at, c.prepared_through, s.group_list'
            . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE, 'c')
            . ' LEFT JOIN ' . $db->quoteName(self::SETS_TABLE, 's') . ' ON s.id = c.group_set_id'
            . ' WHERE c.prepared_at IS NULL ORDER BY c.requested_at, c.id',
        )->loadObjectList() ?: [] as $context) {
            $through = (int) $context->prepared_through;
            $waiting[] = [
                'id' => (int) $context->id,
                'groups' => self::groupsOfList(list: (string) $context->group_list),
                'currency' => (int) $context->currency_id,
                'place' => (int) $context->priced_at_place_id,
                'user' => (int) $context->user_id,
                'requested_at' => (string) $context->requested_at,
                'done' => $through === 0 ? 0 : (int) $db->setQuery(
                    'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . $db->quoteName('state') . ' = 1 AND '
                    . $db->quoteName('id') . ' <= ' . $through,
                )->loadResult(),
                'products' => $products,
                'job' => Runner::latestJob(scopeKey: PriceSetPrepareJob::scopeFor(contextId: (int) $context->id)),
            ];
        }

        $leftOut = [];

        foreach ($db->setQuery(
            'SELECT ' . $db->quoteName('left_out') . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE)
            . ' WHERE ' . $db->quoteName('left_out') . ' IS NOT NULL AND ' . $db->quoteName('left_out') . ' <> ' . $db->quote(''),
        )->loadColumn() ?: [] as $list) {
            $leftOut = array_merge($leftOut, self::groupsOfList(list: (string) $list));
        }

        $leftOut = array_values(array_unique($leftOut));
        sort($leftOut);

        return ['waiting' => $waiting, 'leftOut' => $leftOut, 'building' => self::buildStartedAt()];
    }

    // The build after the update, and the switch

    /**
     * Whether the storefront reads the previous index: the build after the update has not switched yet. Read
     * once per process.
     *
     * @since  2.2.2
     */
    public static function isBuilding(): bool
    {
        return self::$building ??= self::buildStartedAt() !== null;
    }

    /**
     * When the build after the update began, as stored; null when the storefront reads this index.
     *
     * @since  2.2.2
     */
    public static function buildStartedAt(): ?string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $value = $db->setQuery(
            'SELECT ' . $db->quoteName('value') . ' FROM ' . $db->quoteName('#__alfa_meta')
            . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote(self::META_BUILD),
        )->loadResult();

        return \is_string($value) ? $value : null;
    }

    /**
     * When the storefront switched from the previous index, as stored; null before the switch (or with no previous
     * index ever).
     *
     * @since  2.2.2
     */
    public static function switchedAt(): ?string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $value = $db->setQuery(
            'SELECT ' . $db->quoteName('value') . ' FROM ' . $db->quoteName('#__alfa_meta')
            . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote(self::META_SWITCHED),
        )->loadResult();

        return \is_string($value) ? $value : null;
    }

    /**
     * Write the flag of the build after the update, keeping the moment of a build already begun: the installer writes it
     * before it renames the previous index, so a run that stops after the rename resumes the build.
     *
     * @since  2.2.2
     */
    public static function writeBuildFlag(): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $name = self::META_BUILD;
        $value = gmdate('Y-m-d H:i:s');
        $db->setQuery(
            $db->getQuery(true)->setQuery(
                'INSERT INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ') VALUES (:name, :value)'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('value') . ' = ' . $db->quoteName('value'),
            )->bind(':name', $name)->bind(':value', $value),
        )->execute();
        self::$building = true;
    }

    /**
     * Begin or resume the build after the update (the installer): the flag written (its first moment kept), every
     * item marked owed a rebuild and the catalogue job enqueued, so the new index is built in the background while the
     * storefront reads the previous one. Each step is idempotent: a second run marks and enqueues again.
     *
     * @return int The items marked.
     *
     * @since  2.2.2
     */
    public static function startBuild(): int
    {
        self::writeBuildFlag();
        $marked = ProjectionManager::markAllForRebuild();
        CatalogueRebuildJob::enqueue();

        return $marked;
    }

    /**
     * The switch from the previous index, asked when a pass of the catalogue job ends: the contexts aligned, then the
     * completeness count. A unit no context row covers is written now, each product alone where a chunk fails; a product
     * whose pricing still throws gets rows with no figure in every context and is recorded as left out, so one product
     * never holds the storefront on the previous index. Once the count passes the flag goes, the switch is recorded,
     * and the storefront reads this index from its next request; else the products the count lacks are owed and the
     * next pass asks again. The marks of other projections never hold the switch.
     *
     * @return bool Whether the switch was made now.
     *
     * @since  2.2.2
     */
    public function finishBuild(): bool
    {
        if (self::buildStartedAt() === null) {
            return false;
        }

        // A context the data defines that no write has met yet is requested now (and so written before the switch).
        $this->alignContexts();
        $check = $this->completeness();

        if (!$check['rows']) {
            $this->completeMissing(check: $check);
            $check = $this->completeness();
        }

        if (!$check['rows']) {
            $this->oweIncomplete(check: $check);

            return false;
        }

        $name = self::META_SWITCHED;
        $value = gmdate('Y-m-d H:i:s');
        $this->db->transactionStart(true);

        try {
            $this->db->setQuery(
                'DELETE FROM ' . $this->db->quoteName('#__alfa_meta') . ' WHERE ' . $this->db->quoteName('name') . ' = ' . $this->db->quote(self::META_BUILD),
            )->execute();
            $this->db->setQuery(
                $this->db->getQuery(true)->setQuery(
                    'INSERT INTO ' . $this->db->quoteName('#__alfa_meta') . ' (' . $this->db->quoteName('name') . ', ' . $this->db->quoteName('value') . ') VALUES (:name, :value)'
                    . ' ON DUPLICATE KEY UPDATE ' . $this->db->quoteName('value') . ' = :again',
                )->bind(':name', $name)->bind(':value', $value)->bind(':again', $value),
            )->execute();
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $this->db, failure: $e);

            throw $e;
        }

        self::$building = false;
        AlfaLog::register();
        Log::add(
            '[PriceIndexSyncService] The price index is built: the storefront reads it from now on; the previous index is dropped by the next update.'
            . ($check['leftOut'] === [] ? '' : ' Left out, with no price until they can be priced: item(s) ' . implode(', ', $check['leftOut']) . '.'),
            $check['leftOut'] === [] ? Log::INFO : Log::WARNING,
            'com_alfa',
        );

        return true;
    }

    /**
     * Write the units the completeness count finds missing (the switch's last step): the products that lack a row
     * covering now in a context of a set, and those of a customer's incomplete context, at most a thousand at a time;
     * first together, then each alone where a chunk failed; a product whose pricing still throws is left out
     * ({@see self::leaveOut()}).
     *
     * @param array $check What completeness() answered.
     *
     * @since  2.2.2
     */
    private function completeMissing(array $check): void
    {
        $missing = function () use ($check): array {
            $items = $this->missingItems(limit: 1000);

            foreach ($check['contexts'] as $context) {
                if ($context['user'] > 0 && $context['covering'] < $context['expected']) {
                    $items = array_merge($items, $this->uncoveredOf(contextId: $context['id'], itemIds: array_keys($this->touchedBy(userId: $context['user']))));
                }
            }

            $items = array_values(array_unique(array_map('intval', $items)));
            sort($items);

            return $items;
        };
        $items = $missing();

        if ($items === []) {
            return;
        }

        $contexts = array_values($this->contextRows(ids: null, waitingOnly: false));
        $this->writeIndexRows(itemIds: $items, contexts: $contexts);
        $still = array_values(array_intersect($missing(), $items));

        foreach ($still as $itemId) {
            $this->writeIndexRows(itemIds: [$itemId], contexts: $contexts);
        }

        $failed = array_values(array_intersect($missing(), $still));

        if ($failed !== []) {
            $this->leaveOut(itemIds: $failed);
        }
    }

    /**
     * The products of a list that hold no row covering now in a context, for a published unit of theirs.
     *
     * @param int $contextId The context.
     * @param int[] $itemIds The products.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private function uncoveredOf(int $contextId, array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return [];
        }

        $db = $this->db;
        $now = $db->quote(gmdate('Y-m-d H:i:s'));
        $out = [];

        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $out = array_merge($out, array_map('intval', $db->setQuery(
                'SELECT DISTINCT u.item_id FROM ' . $this->unitsSql(itemIds: $chunk) . ' AS u'
                . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $db->quoteName(self::INDEX_TABLE) . ' AS i'
                . ' WHERE i.context_id = ' . $contextId . ' AND i.item_id = u.item_id AND i.combination_id = u.combination_id'
                . ' AND i.valid_from <= ' . $now . ' AND (i.valid_until IS NULL OR i.valid_until > ' . $now . '))',
            )->loadColumn() ?: []));
        }

        return $out;
    }

    /**
     * Leave products out of the index: in every context they are written for, each published unit gets one row from the
     * epoch to an open end with no figure (listed with no price, last in a price sort, out of the price filters), and
     * the products are recorded on the contexts' left_out, which Tools names. The next write that prices a product
     * replaces its rows and takes it off the list. The products' locks held; one transaction level.
     *
     * @param int[] $itemIds The products whose pricing throws.
     * @param int[]|null $contextIds Only these contexts; null for every one.
     *
     * @since  2.2.2
     */
    private function leaveOut(array $itemIds, ?array $contextIds = null): void
    {
        $itemIds = $this->publishedOf(itemIds: array_values(array_unique(array_map('intval', $itemIds))));

        if ($itemIds === []) {
            return;
        }

        $lock = new IndexRebuildLock($this->db);
        $status = $lock->acquire(itemIds: $itemIds);
        $granted = array_keys($status, IndexRebuildLock::GRANTED, true);

        try {
            if ($granted === []) {
                return;
            }

            $contexts = $this->contextRows(ids: $contextIds, waitingOnly: false);
            $units = $this->unitsOf(itemIds: $granted);
            $none = implode(',', array_fill(0, 7, 'NULL'));
            $this->db->transactionStart(true);

            try {
                $values = [];

                foreach ($contexts as $context) {
                    $items = $context['through'] === null ? $granted : array_values(array_filter($granted, static fn (int $itemId): bool => $itemId <= $context['through']));

                    if ($context['user_id'] > 0) {
                        $touched = $this->touchedBy(userId: $context['user_id']);
                        $items = array_values(array_filter($items, static fn (int $itemId): bool => isset($touched[$itemId])));
                    }

                    if ($items === []) {
                        continue;
                    }

                    $this->db->setQuery(
                        $this->db->getQuery(true)
                            ->delete($this->db->quoteName(self::INDEX_TABLE))
                            ->whereIn($this->db->quoteName('item_id'), $items)
                            ->where($this->db->quoteName('context_id') . ' = ' . (int) $context['id']),
                    )->execute();

                    foreach ($items as $itemId) {
                        foreach ($units[$itemId] === [] ? [0] : $units[$itemId] as $combinationId) {
                            $values[] = '(' . (int) $context['id'] . ',' . $itemId . ',' . (int) $combinationId . ',' . $this->db->quote(self::EPOCH) . ',NULL,' . $none . ',UTC_TIMESTAMP())';
                        }
                    }

                    $this->db->setQuery(
                        'UPDATE ' . $this->db->quoteName(self::CONTEXTS_TABLE) . ' SET ' . $this->db->quoteName('left_out') . ' = '
                        . $this->db->quote(implode(',', array_values(array_unique(array_merge($context['leftOut'], $items)))))
                        . ' WHERE ' . $this->db->quoteName('id') . ' = ' . (int) $context['id'],
                    )->execute();
                }

                foreach (array_chunk($values, self::INSERT_ROWS) as $part) {
                    $this->db->setQuery(
                        'INSERT INTO ' . $this->db->quoteName(self::INDEX_TABLE) . ' (' . implode(', ', $this->db->quoteName([
                            'context_id', 'item_id', 'combination_id', 'valid_from', 'valid_until', 'base_price', 'discount_amount', 'base_price_with_discounts',
                            'tax_amount', 'base_price_with_tax', 'final_price', 'discount_percent', 'updated_at',
                        ])) . ') VALUES ' . implode(',', $part),
                    )->execute();
                }

                TransactionState::commitLevel(db: $this->db);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $this->db, failure: $e);

                throw $e;
            }
        } finally {
            $lock->release(itemIds: $granted);
        }

        $this->logWarning(message: 'Item(s) ' . implode(', ', $granted) . ' could not be priced and are left out of the price index: listed with no price until a write can price them.');
    }

    // The completeness check

    /**
     * The completeness check: every prepared context holds exactly one row covering now for each unit it is
     * written for (a set's context: every published unit; a customer's context: the units of the products their rules
     * touch), no row of a unit that is not published, the rows of each unit tiling time from the epoch to an open end,
     * and every product's stored opening combination equal to the choice in SQL. rows says whether the rows pass, which
     * the switch asks; complete adds the pointers (the opening combination and the price combination), which Tools reports.
     *
     * @return array{complete: bool, rows: bool, units: int, contexts: list<array{id: int, user: int, expected: int, covering: int, stale: int}>, gaps: int, pointers: array{count: int, items: int[]}, leftOut: int[]}
     *
     * @since  2.2.2
     */
    public function completeness(): array
    {
        $db = $this->db;
        $now = $db->quote(gmdate('Y-m-d H:i:s'));
        $covering = 'i.valid_from <= ' . $now . ' AND (i.valid_until IS NULL OR i.valid_until > ' . $now . ')';
        $index = $db->quoteName(self::INDEX_TABLE);
        $combinations = $db->quoteName('#__alfa_item_combinations');
        $items = $db->quoteName('#__alfa_items');
        $units = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->unitsSql() . ' AS u',
        )->loadResult();
        $rows = $db->setQuery(
            'SELECT i.context_id, COUNT(*) AS n FROM ' . $index . ' AS i WHERE ' . $covering . ' GROUP BY i.context_id',
        )->loadAssocList('context_id', 'n') ?: [];
        // A row covering now of a unit that is not one: a product unpublished or gone, a combination unpublished or gone,
        // or a combination with no price rows of its own (the product's row is its price).
        $stale = $db->setQuery(
            'SELECT i.context_id, COUNT(*) AS n FROM ' . $index . ' AS i'
            . ' LEFT JOIN ' . $items . ' AS a ON a.id = i.item_id AND a.state = 1'
            . ' LEFT JOIN ' . $combinations . ' AS oc ON oc.id = i.combination_id AND oc.item_id = i.item_id AND oc.state = 1 AND ' . self::ownPriceSql(combinationAlias: 'oc')
            . ' WHERE ' . $covering . ' AND (a.id IS NULL OR (i.combination_id > 0 AND oc.id IS NULL))'
            . ' GROUP BY i.context_id',
        )->loadAssocList('context_id', 'n') ?: [];
        // The periods of the live generations: no future start without the period before it (an ended period may have
        // been removed by the tidy), no end without the period after it.
        $live = ' INNER JOIN ' . $db->quoteName(self::CONTEXTS_TABLE) . ' AS lc ON lc.id = i.context_id AND lc.is_live = 1';
        $gaps = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $index . ' AS i' . $live . ' WHERE i.valid_from > ' . $now . ' AND NOT EXISTS (SELECT 1 FROM ' . $index . ' AS p'
            . ' WHERE p.context_id = i.context_id AND p.item_id = i.item_id AND p.combination_id = i.combination_id AND p.valid_until = i.valid_from)',
        )->loadResult() + (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $index . ' AS i' . $live . ' WHERE i.valid_until IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $index . ' AS n'
            . ' WHERE n.context_id = i.context_id AND n.item_id = i.item_id AND n.combination_id = i.combination_id AND n.valid_from = i.valid_until)',
        )->loadResult();
        $contexts = [];
        $complete = $gaps === 0;

        foreach ($db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'user_id'])) . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE)
            . ' WHERE ' . $db->quoteName('is_live') . ' = 1 ORDER BY ' . $db->quoteName('id'),
        )->loadObjectList() ?: [] as $context) {
            $id = (int) $context->id;
            $user = (int) $context->user_id;
            $expected = $user > 0 ? $this->unitCount(itemIds: array_keys($this->touchedBy(userId: $user))) : $units;
            $entry = ['id' => $id, 'user' => $user, 'expected' => $expected, 'covering' => (int) ($rows[$id] ?? 0), 'stale' => (int) ($stale[$id] ?? 0)];
            $complete = $complete && $entry['covering'] === $expected && $entry['stale'] === 0;
            $contexts[] = $entry;
        }

        $pointers = OpeningCombination::disagreements();
        $leftOut = [];

        foreach ($db->setQuery(
            'SELECT ' . $db->quoteName('left_out') . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE)
            . ' WHERE ' . $db->quoteName('is_live') . ' = 1 AND ' . $db->quoteName('left_out') . ' IS NOT NULL AND ' . $db->quoteName('left_out') . ' <> ' . $db->quote(''),
        )->loadColumn() ?: [] as $list) {
            $leftOut = array_merge($leftOut, self::groupsOfList(list: (string) $list));
        }

        $leftOut = array_values(array_unique($leftOut));
        sort($leftOut);

        return ['complete' => $complete && $pointers['count'] === 0, 'rows' => $complete, 'units' => $units, 'contexts' => $contexts, 'gaps' => $gaps, 'pointers' => $pointers, 'leftOut' => $leftOut];
    }

    /**
     * Owe the catalogue job the products a failed completeness check names: those some context of a set lacks a row
     * covering now for, every product a customer's incomplete context is written for, and the products with a row of a
     * combination no longer published or no longer with its own price rows, or with a gap in their periods.
     *
     * @param array $check What completeness() answered.
     *
     * @since  2.2.2
     */
    private function oweIncomplete(array $check): void
    {
        $owed = $this->missingItems(limit: 1000);

        foreach ($check['contexts'] as $context) {
            if ($context['user'] > 0 && ($context['covering'] !== $context['expected'] || $context['stale'] > 0)) {
                $owed = array_merge($owed, array_keys($this->touchedBy(userId: $context['user'])));
            }
        }

        $db = $this->db;
        $now = $db->quote(gmdate('Y-m-d H:i:s'));
        $index = $db->quoteName(self::INDEX_TABLE);
        $live = ' INNER JOIN ' . $db->quoteName(self::CONTEXTS_TABLE) . ' AS lc ON lc.id = i.context_id AND lc.is_live = 1';
        $owed = array_merge($owed, array_map('intval', $db->setQuery(
            'SELECT DISTINCT i.item_id FROM ' . $index . ' AS i' . $live
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_item_combinations') . ' AS oc ON oc.id = i.combination_id AND oc.item_id = i.item_id AND oc.state = 1'
            . ' AND ' . self::ownPriceSql(combinationAlias: 'oc')
            . ' WHERE i.valid_from <= ' . $now . ' AND (i.valid_until IS NULL OR i.valid_until > ' . $now . ')'
            . ' AND i.combination_id > 0 AND oc.id IS NULL',
            0,
            1000,
        )->loadColumn() ?: []), array_map('intval', $db->setQuery(
            'SELECT DISTINCT i.item_id FROM ' . $index . ' AS i' . $live . ' WHERE i.valid_until IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $index . ' AS n'
            . ' WHERE n.context_id = i.context_id AND n.item_id = i.item_id AND n.combination_id = i.combination_id AND n.valid_from = i.valid_until)',
            0,
            1000,
        )->loadColumn() ?: []));

        if ($owed !== []) {
            ProjectionManager::owe(itemIds: $owed);
        }
    }

    /**
     * The published units of some products.
     *
     * @param int[] $itemIds The products.
     *
     * @since  2.2.2
     */
    private function unitCount(array $itemIds): int
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return 0;
        }

        $count = 0;

        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $count += (int) $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->unitsSql(itemIds: $chunk) . ' AS u',
            )->loadResult();
        }

        return $count;
    }

    /**
     * The published products some prepared context of a set holds no row covering now for, for a unit of theirs: what
     * the switch owes again when the count does not pass.
     *
     * @param int $limit At most this many.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private function missingItems(int $limit): array
    {
        $db = $this->db;
        $now = $db->quote(gmdate('Y-m-d H:i:s'));

        return array_map('intval', $db->setQuery(
            'SELECT DISTINCT u.item_id FROM ' . $this->unitsSql() . ' AS u'
            . ' CROSS JOIN ' . $db->quoteName(self::CONTEXTS_TABLE) . ' AS c'
            . ' WHERE c.is_live = 1 AND c.user_id = 0 AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName(self::INDEX_TABLE) . ' AS i'
            . ' WHERE i.context_id = c.id AND i.item_id = u.item_id AND i.combination_id = u.combination_id'
            . ' AND i.valid_from <= ' . $now . ' AND (i.valid_until IS NULL OR i.valid_until > ' . $now . '))'
            . ' ORDER BY u.item_id',
            0,
            max(1, $limit),
        )->loadColumn() ?: []);
    }

    // Scope helpers: called by the rule hooks before a delete

    /**
     * The products a discount can reach: every published product for a catalogue-wide discount, else the products of
     * its categories. Call before deleting the discount, while its scope rows exist.
     *
     * @return int[]
     * @since  1.0.0
     */
    public function getItemIdsForDiscount(int $discountId): array
    {
        if ($discountId <= 0) {
            return [];
        }

        if ($this->discountAppliesGlobally($discountId)) {
            return $this->getAllPublishedItemIds();
        }

        return $this->getItemIdsInCategories($this->getDiscountCategoryIds($discountId));
    }

    /**
     * The products a tax can reach: every published product for a catalogue-wide tax, else the products of its
     * categories. Call before deleting the tax, while its scope rows exist.
     *
     * @return int[]
     * @since  1.0.0
     */
    public function getItemIdsForTax(int $taxId): array
    {
        if ($taxId <= 0) {
            return [];
        }

        if ($this->taxAppliesGlobally($taxId)) {
            return $this->getAllPublishedItemIds();
        }

        return $this->getItemIdsInCategories($this->getTaxCategoryIds($taxId));
    }

    // Read side

    /**
     * The groups a price row, a discount or a tax names: the groups that can change a price. Read once per process;
     * every write reads them again.
     *
     * @return int[] Ascending.
     *
     * @since  2.0.0
     */
    public static function pricingGroups(): array
    {
        if (self::$pricingGroups !== null) {
            return self::$pricingGroups;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $named = static fn (string $table, string $published = ''): string => 'SELECT ' . $db->quoteName('usergroup_id')
            . ' FROM ' . $db->quoteName($table) . ' WHERE ' . $db->quoteName('usergroup_id') . ' > 0' . $published;

        $groups = array_map('intval', $db->setQuery(
            $named('#__alfa_discount_usergroups')
            . ' UNION ' . $named('#__alfa_tax_usergroups')
            . ' UNION ' . $named('#__alfa_items_prices', ' AND ' . $db->quoteName('state') . ' = 1'),
        )->loadColumn());
        sort($groups);

        return self::$pricingGroups = $groups;
    }

    /**
     * The set of pricing groups one visitor holds: the groups of the context that a price row, a discount or a tax
     * names.
     *
     * @param PriceContext $context The visitor's pricing context.
     *
     * @return string The key the set is found by (groupSetKey()).
     *
     * @since  2.0.0
     */
    public static function slotKey(PriceContext $context): string
    {
        return self::groupSetKey(groupIds: array_intersect($context->getUserGroups(), self::pricingGroups()));
    }

    /**
     * The sets one visitor may read, in the order they are tried: their own; while a change of the pricing
     * groups waits, the set of the groups they hold among the pricing groups before it; the guest's; the guest's before
     * the change.
     *
     * @param PriceContext $context The visitor's pricing context.
     *
     * @return string[] Set keys (groupSetKey()), without repeats.
     *
     * @since  2.2.2
     */
    public static function readableSetKeys(PriceContext $context): array
    {
        $groups = $context->getUserGroups();
        $guestLine = self::guestLine();
        $pricing = self::pricingGroups();
        $previous = self::previousPricing();
        $keys = [self::groupSetKey(groupIds: array_intersect($groups, $pricing))];

        if ($previous !== null) {
            $keys[] = self::groupSetKey(groupIds: array_intersect($groups, $previous));
        }

        $keys[] = self::groupSetKey(groupIds: array_intersect($guestLine, $pricing));

        if ($previous !== null) {
            $keys[] = self::groupSetKey(groupIds: array_intersect($guestLine, $previous));
        }

        return array_values(array_unique($keys));
    }

    /**
     * The prepared context one visitor reads, and their own customer context when they have one prepared.
     *
     * The visitor's context: the currency class (their currency when a context of it exists, else class 0); the place
     * class (the nearest place of their chain that a context's rule place names, and their own place when a context's
     * price place names it); their set. A context not prepared yet is never read: the visitor reads the context they
     * read before it existed, in this order: their previous set or the guest's in the same class
     * ({@see self::readableSetKeys()}); the class without their own place's price rows; the next ruled place above;
     * no place; then the same in currency class 0. A logged-in customer whose own context of that class and set is
     * prepared reads it first, the set's row where it has none.
     *
     * Two small statements a process for each visitor (the contexts, the sets' ids), kept for the process.
     *
     * @param PriceContext $context The visitor's pricing context.
     *
     * @return array{context: int, customer: int}|null Null when no context is prepared for the visitor: nothing is priced.
     *
     * @since  2.2.2
     */
    public static function readContext(PriceContext $context): ?array
    {
        $userId = (int) ($context->getUserId() ?? 0);
        $key = $context->getCurrencyId() . '|' . (int) ($context->getLocationId() ?? 0) . '|' . implode(',', $context->getUserGroups()) . '|' . $userId;

        if (\array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $rows = self::$readContexts[$userId] ??= $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'currency_id', 'rule_place_id', 'price_place_id', 'group_set_id', 'user_id', 'is_live']))
            . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $db->quoteName('user_id') . ' IN (0, ' . $userId . ')'
            . ' AND ' . $db->quoteName('retired_at') . ' IS NULL',
        )->loadObjectList() ?: [];

        if ($rows === []) {
            return self::$resolved[$key] = null;
        }

        $currencies = [];
        $rulePlaces = [];
        $pricePlaces = [];
        $prepared = [];

        foreach ($rows as $row) {
            $currencies[(int) $row->currency_id] = true;
            $rulePlaces[(int) $row->rule_place_id] = true;
            $pricePlaces[(int) $row->price_place_id] = true;

            // The live generation of each context is read; one being prepared beside it is not.
            if ((int) $row->is_live === 1) {
                $prepared[(int) $row->currency_id . '|' . (int) $row->rule_place_id . '|' . (int) $row->price_place_id . '|' . (int) $row->group_set_id . '|' . (int) $row->user_id] = (int) $row->id;
            }
        }

        // The visitor's chain, nearest first, and the place classes to try, in order.
        $chain = PlaceTree::placesFor(placeId: (int) ($context->getLocationId() ?? 0));
        $ruled = array_values(array_filter($chain, static fn (int $place): bool => $place > 0 && isset($rulePlaces[$place])));
        $own = $chain !== [] && $chain[0] > 0 && isset($pricePlaces[$chain[0]]) ? $chain[0] : 0;
        $classes = [];

        if ($own > 0) {
            $classes[] = [$ruled[0] ?? 0, $own];
        }

        foreach ($ruled as $place) {
            $classes[] = [$place, 0];
        }

        $classes[] = [0, 0];
        $currency = $context->getCurrencyId();
        $currencyClasses = isset($currencies[$currency]) && $currency > 0 ? [$currency] : [];

        if (isset($currencies[0])) {
            $currencyClasses[] = 0;
        }

        $digests = self::readableSetKeys(context: $context);
        $sets = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['digest', 'id']))
                ->from($db->quoteName(self::SETS_TABLE))
                ->whereIn($db->quoteName('digest'), $digests, ParameterType::STRING),
        )->loadAssocList('digest', 'id') ?: [];

        foreach ($currencyClasses as $c) {
            foreach ($classes as [$rule, $price]) {
                foreach ($digests as $digest) {
                    $setId = (int) ($sets[$digest] ?? 0);
                    $found = $setId > 0 ? ($prepared[$c . '|' . $rule . '|' . $price . '|' . $setId . '|0'] ?? null) : null;

                    if ($found !== null) {
                        $customer = $userId > 0 ? ($prepared[$c . '|' . $rule . '|' . $price . '|' . $setId . '|' . $userId] ?? 0) : 0;

                        return self::$resolved[$key] = ['context' => $found, 'customer' => $customer];
                    }
                }
            }
        }

        return self::$resolved[$key] = null;
    }

    /**
     * The line of groups a visitor with no account holds: com_users' guest group and the groups above it.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private static function guestLine(): array
    {
        if (self::$guestLine === null) {
            $guestGroup = (int) ComponentHelper::getParams('com_users')->get('guest_usergroup', 1);
            self::$guestLine = array_map('intval', PriceContext::groupLineages(groupIds: [$guestGroup])[$guestGroup] ?? [1]);
        }

        return self::$guestLine;
    }

    /**
     * The pricing groups before a change of them that waits for the contexts it brought.
     *
     * @return int[]|null Null when no change waits.
     *
     * @since  2.2.2
     */
    private static function previousPricing(): ?array
    {
        if (!self::$previousRead) {
            $previous = self::groupSetFacts(db: Factory::getContainer()->get('DatabaseDriver'))['previous'];
            self::$previousPricing = $previous === null ? null : self::groupsOfList(list: $previous);
            self::$previousRead = true;
        }

        return self::$previousPricing;
    }

    /**
     * The fact of the sets of pricing groups as stored (META_GROUP_SETS).
     *
     * @param DatabaseDriver $db The connection.
     *
     * @return array{stored: string|null, pricing: string|null, previous: string|null}
     *
     * @since  2.2.2
     */
    private static function groupSetFacts(DatabaseDriver $db): array
    {
        $stored = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_GROUP_SETS)),
        )->loadResult();
        $facts = \is_string($stored) ? json_decode($stored, true) : null;
        $part = static fn (string $name): ?string => \is_array($facts) && \is_string($facts[$name] ?? null) ? $facts[$name] : null;

        return ['stored' => \is_string($stored) ? $stored : null, 'pricing' => $part('pricing'), 'previous' => $part('previous')];
    }

    // The previous index's reader, read by the storefront while the new index is built

    /**
     * The prepared set of the previous index one visitor's slots are read from: their own set while the previous index
     * prepared it, else their previous one or the guest's ({@see self::readableSetKeys()}). There is a previous index
     * only while {@see self::isBuilding()}, and its sets keep their prepared mark from before the update until then;
     * after it no set of it is read.
     *
     * @param PriceContext $context The visitor's pricing context.
     *
     * @return string|null The key of the set to read, or null for none.
     *
     * @since  2.2.2
     */
    public static function readableSetKey(PriceContext $context): ?string
    {
        if (!self::isBuilding()) {
            return null;
        }

        $keys = self::readableSetKeys(context: $context);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $prepared = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('digest'))
                ->from($db->quoteName(self::SETS_TABLE))
                ->whereIn($db->quoteName('digest'), $keys, ParameterType::STRING)
                ->where($db->quoteName('prepared_at') . ' IS NOT NULL'),
        )->loadColumn() ?: [];

        foreach ($keys as $key) {
            if (\in_array($key, $prepared, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The join that keeps a reader of the previous index to the slots of one set it prepared.
     *
     * @param string $setKey The key of the set to read.
     * @param string $alias Alias of the previous index in the reader's statement.
     *
     * @return string An INNER JOIN clause.
     *
     * @since  2.0.0
     */
    public static function slotSetJoin(string $setKey, string $alias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $sets = $alias . '_set';

        return 'INNER JOIN ' . $db->quoteName(self::SETS_TABLE, $sets)
            . " ON {$sets}.id = {$alias}.group_set_id AND {$sets}.digest = " . $db->quote($setKey)
            . " AND {$sets}.prepared_at IS NOT NULL";
    }

    /**
     * The places whose slots of the previous index one visitor reads, nearest first: the visitor's own place, then each
     * place above it that a discount or a tax names.
     *
     * @param PriceContext $context The visitor's pricing context.
     *
     * @return int[] Empty for an unknown place.
     *
     * @since  2.2.1
     */
    public static function slotPlaces(PriceContext $context): array
    {
        $chain = PlaceTree::placesFor(placeId: (int) ($context->getLocationId() ?? 0));

        if (\count($chain) < 2) {
            return $chain;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $above = \array_slice($chain, 1);
        $named = static fn (string $table): string => 'SELECT ' . $db->quoteName('place_id') . ' FROM ' . $db->quoteName($table)
            . ' WHERE ' . $db->quoteName('place_id') . ' IN (' . implode(',', $above) . ')';
        $ruled = array_map('intval', $db->setQuery($named('#__alfa_discount_places') . ' UNION ' . $named('#__alfa_tax_places'))->loadColumn() ?: []);

        return array_merge([$chain[0]], array_values(array_intersect($above, $ruled)));
    }

    /**
     * The order in which one visitor's matching slots of the previous index are preferred, as an ORDER BY list: the
     * nearest place, then the visitor's own currency before any, then the lower price.
     *
     * @param string $alias Alias of the previous index in the reader's statement.
     * @param int[] $places The visitor's places, nearest first (slotPlaces()).
     *
     * @return string An ORDER BY list, without the keyword.
     *
     * @since  2.0.0
     */
    public static function slotRanking(string $alias, array $places): string
    {
        $near = '';

        foreach (array_values(array_map('intval', $places)) as $position => $place) {
            $near .= " WHEN {$place} THEN {$position}";
        }

        $nearness = $near === '' ? '' : "CASE {$alias}.place_id{$near} ELSE " . \count($places) . ' END ASC, ';

        return "{$nearness}({$alias}.currency_id > 0) DESC, {$alias}.final_price ASC";
    }

    /**
     * The rebuild owed when places take another parent, or are published or unpublished: the place classes and
     * the chains a context is priced with change at or below them. The contexts are aligned again, and each live context
     * priced at or below the places gets its next generation, prepared by its job and switched whole when complete.
     *
     * @param int[] $placeIds The places changed.
     *
     * @return bool Whether the rebuild was owed.
     *
     * @since  2.2.1
     */
    public function oweChainRebuild(array $placeIds): bool
    {
        $placeIds = array_values(array_filter(array_map('intval', $placeIds), static fn (int $place): bool => $place > 0));

        if ($placeIds === []) {
            return false;
        }

        PlaceTree::forget();
        $this->alignContexts();
        $placed = [];
        $preparing = [];

        // The live contexts and the first generations being prepared, priced at or below the places.
        foreach ($this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('priced_at_place_id') . ', ' . $this->db->quoteName('is_live')
            . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE)
            . ' WHERE ' . $this->db->quoteName('priced_at_place_id') . ' > 0 AND ' . $this->db->quoteName('retired_at') . ' IS NULL'
            . ' AND (' . $this->db->quoteName('is_live') . ' = 1 OR ' . $this->db->quoteName('prepared_at') . ' IS NULL)',
        )->loadObjectList() ?: [] as $row) {
            $place = (int) $row->priced_at_place_id;

            if (array_intersect(PlaceTree::placesFor(placeId: $place), $placeIds) !== [] || \in_array($place, $placeIds, true)) {
                if ((int) $row->is_live === 1) {
                    $placed[] = (int) $row->id;
                } else {
                    $preparing[] = (int) $row->id;
                }
            }
        }

        if ($placed === [] && $preparing === []) {
            return false;
        }

        foreach (array_values(array_unique(array_merge($this->regenerate(liveIds: $placed), $this->restartPreparing(ids: $preparing)))) as $id) {
            PriceSetPrepareJob::enqueueFor(contextId: $id);
        }

        return true;
    }

    /**
     * The set of pricing groups one account holds, as the index keys it.
     *
     * @param int $userId The account.
     *
     * @return string The set's key (groupSetKey()).
     *
     * @since  2.0.0
     */
    public function accountGroupSet(int $userId): string
    {
        $lists = $this->accountGroupLists(userId: $userId);

        return self::groupSetKey(groupIds: self::groupsOfList(list: $lists[0] ?? ''));
    }

    /**
     * Whether the index knows one set of pricing groups.
     *
     * @param string $groupSet The set's key (groupSetKey()).
     *
     * @since  2.0.0
     */
    public function holdsGroupSet(string $groupSet): bool
    {
        return (bool) $this->db->setQuery(
            $this->db->getQuery(true)
                ->select('1')
                ->from($this->db->quoteName(self::SETS_TABLE))
                ->where($this->db->quoteName('digest') . ' = ' . $this->db->quote($groupSet)),
        )->loadResult();
    }

    // The writer

    /**
     * Write the rows of some products in some contexts, a chunk of products at a time, each chunk under its products'
     * locks and in one transaction level. A context still waiting for its preparation is written only for the products
     * its preparation has passed.
     *
     * @param int[] $itemIds The products.
     * @param array $contexts From alignContexts().
     * @param bool $strict A chunk whose calculation failed, or whose locks were not granted, throws once rolled back;
     *                     false owes that chunk to the catalogue job and moves on.
     *
     * @return int Rows written.
     *
     * @throws RuntimeException When strict and a calculation failed or a lock was not granted.
     *
     * @since  1.0.0
     */
    private function writeIndexRows(array $itemIds, array $contexts, bool $strict = false): int
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $itemId): bool => $itemId > 0)));

        if ($itemIds === []) {
            return 0;
        }

        sort($itemIds);
        $written = 0;
        $lock = new IndexRebuildLock($this->db);
        $nested = TransactionState::isOpen(db: $this->db);
        $this->customerProducts = [];

        foreach (array_chunk($itemIds, self::CHUNK_SIZE) as $chunk) {
            $status = $lock->acquire(itemIds: $chunk);
            $granted = array_keys($status, IndexRebuildLock::GRANTED, true);
            $refused = array_values(array_diff($chunk, $granted));

            if ($refused !== [] && $strict) {
                $lock->release(itemIds: $granted);

                throw new RuntimeException('Price index: the rebuild locks of item(s) ' . implode(', ', $refused) . ' were not granted; their rows were kept as they were.');
            }

            ProjectionManager::owe(itemIds: $refused);

            if ($granted === []) {
                continue;
            }

            try {
                $chunkWritten = $this->writeLockedChunk(chunk: $granted, contexts: $contexts);
            } catch (Throwable $e) {
                if ($strict || ($nested && (!TransactionState::isOpen(db: $this->db) || TransactionState::endedBy(db: $this->db, failure: $e)))) {
                    throw $e;
                }

                $this->logWarning('A chunk of the price index failed and is left to the catalogue rebuild: ' . $e->getMessage());
                $chunkWritten = null;
            } finally {
                $lock->release(itemIds: $granted);
            }

            if ($chunkWritten !== null) {
                $written += $chunkWritten;

                continue;
            }

            if ($strict) {
                throw new RuntimeException('Price index: a calculation failed for item(s) ' . implode(', ', $granted) . '; their rows were kept as they were.');
            }

            ProjectionManager::owe(itemIds: $granted);
        }

        return $written;
    }

    /**
     * One chunk of writeIndexRows(), its products' locks held: the contexts' progress read again, then the chunk's rows
     * replaced in one transaction level, so a reader sees each product before or after, never between.
     *
     * @param int[] $chunk The products.
     * @param array $contexts From alignContexts().
     *
     * @return int|null Rows written; null when a calculation failed and the chunk's rows were kept.
     *
     * @throws Throwable A failure of the chunk, its level rolled back.
     *
     * @since  2.2.0
     */
    private function writeLockedChunk(array $chunk, array $contexts): ?int
    {
        $contexts = $this->contextsAsNow(contexts: $contexts);
        $this->db->transactionStart(true);

        try {
            $chunkWritten = $this->writeChunk(chunk: $chunk, contexts: $contexts);

            if ($chunkWritten !== null) {
                TransactionState::commitLevel(db: $this->db);
                $this->keepChunkChanges();

                return $chunkWritten;
            }
        } catch (Throwable $e) {
            $this->dropChunkChanges();
            TransactionState::rollBackLevel(db: $this->db, failure: $e);

            throw $e;
        }

        $this->dropChunkChanges();
        TransactionState::rollBackLevel(db: $this->db);

        return null;
    }

    /**
     * The contexts with their progress as the database holds it now, read under a chunk's locks: a context whose
     * preparation passed the chunk meanwhile is written for it, one prepared meanwhile for every product, one dropped
     * meanwhile for none.
     *
     * @param array $contexts From alignContexts().
     *
     * @return array The contexts still to write.
     *
     * @since  2.2.0
     */
    private function contextsAsNow(array $contexts): array
    {
        if ($contexts === []) {
            return [];
        }

        $now = $this->contextRows(ids: array_map(static fn (array $context): int => $context['id'], $contexts), waitingOnly: false);
        $out = [];

        foreach ($contexts as $context) {
            if (isset($now[$context['id']])) {
                $out[] = $now[$context['id']];
            }
        }

        return $out;
    }

    /**
     * Rewrite one chunk of products, inside the caller's transaction: the products' opening combinations refreshed,
     * their rows of the contexts written here deleted, every unit priced in every context and period, equal neighbouring
     * periods merged, the rows inserted many to a statement.
     *
     * One fresh instant per chunk: the clock the edges are judged at and the moment the live period is priced at.
     *
     * @param int[] $chunk The products.
     * @param array $contexts The contexts, their progress as now.
     *
     * @return int|null Rows written; null when a calculation failed (logged): the caller rolls the chunk back.
     *
     * @since  2.0.0
     */
    private function writeChunk(array $chunk, array $contexts): ?int
    {
        $now = (new Date('now', 'UTC'))->toSql();

        // Priced from what the database holds now.
        PriceDataLoader::forgetLoaded();
        PlaceTree::forget();

        // The card's opening combination of each product of the chunk (its save writes its combinations first).
        OpeningCombination::refresh(itemIds: $chunk);

        if ($contexts === []) {
            return 0;
        }

        // Only a published product has rows: the rows of one that is not are deleted with the chunk's.
        $published = $this->publishedOf(itemIds: $chunk);
        $opening = $this->openingQuantities(itemIds: $published);
        $units = $this->unitsOf(itemIds: $published);
        $edges = $this->edgesOf(itemIds: $published, now: $now);

        // The products each context is written for in this chunk: a waiting context those its preparation passed; a
        // customer's context those their rules touch (the others keep no row of it, so the set's row is read).
        $itemsOf = [];
        $deleteOf = [];

        foreach ($contexts as $context) {
            $id = $context['id'];
            $through = $context['through'];
            $scope = $through === null ? $chunk : array_values(array_filter($chunk, static fn (int $itemId): bool => $itemId <= $through));
            $deleteOf[$id] = $scope;
            $itemsOf[$id] = array_values(array_intersect($published, $scope));

            if ($context['user_id'] > 0) {
                $touched = $this->touchedBy(userId: $context['user_id']);
                $itemsOf[$id] = array_values(array_filter($itemsOf[$id], static fn (int $itemId): bool => isset($touched[$itemId])));
            }
        }

        $detect = self::detecting();
        $before = [];
        $after = [];

        if ($detect) {
            $written = array_keys(array_filter($deleteOf, static fn (array $items): bool => $items !== []));
            $before = $written === [] ? [] : array_filter(
                $this->readIndexRows(itemIds: $chunk, contextIds: $written),
                static fn (array $row): bool => \in_array($row['item'], $deleteOf[$row['context']] ?? [], true),
            );
            // A generation being prepared beside a live one is compared with what readers read now: the live rows.
            $liveOf = [];

            foreach ($contexts as $context) {
                if ($context['liveOf'] !== null && ($deleteOf[$context['id']] ?? []) !== []) {
                    $liveOf[$context['liveOf']] = $context['id'];
                }
            }

            if ($liveOf !== []) {
                $before = array_filter($before, static fn (array $row): bool => !\in_array($row['context'], $liveOf, true));

                foreach ($this->readIndexRows(itemIds: $chunk, contextIds: array_keys($liveOf)) as $row) {
                    $row['context'] = $liveOf[$row['context']];

                    if (\in_array($row['item'], $deleteOf[$row['context']], true)) {
                        $before[self::rowKey(row: $row)] = $row;
                    }
                }
            }
        }

        // One DELETE per distinct list of products: the rows of exactly the contexts rewritten below.
        $byItems = [];

        foreach ($deleteOf as $id => $items) {
            if ($items !== []) {
                $byItems[implode(',', $items)][] = $id;
            }
        }

        foreach ($byItems as $items => $ids) {
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->delete($this->db->quoteName(self::INDEX_TABLE))
                    ->whereIn($this->db->quoteName('item_id'), array_map('intval', explode(',', (string) $items)))
                    ->where($this->db->quoteName('context_id') . ' IN (' . implode(',', $ids) . ')'),
            )->execute();
        }

        // The rows, by context, product, combination, then period start.
        $rows = [];

        foreach ($contexts as $context) {
            $items = $itemsOf[$context['id']];

            if ($items === []) {
                continue;
            }

            // The products by period start, so each (context, start) is one load.
            $byStart = [];

            foreach ($items as $itemId) {
                $starts = array_merge([null], $edges[$itemId] ?? []);

                foreach ($starts as $i => $start) {
                    $at = $start ?? 'live';
                    $byStart[$at]['start'] = $start;
                    $byStart[$at]['items'][] = $itemId;
                    $byStart[$at]['until'][$itemId] = $starts[$i + 1] ?? null;
                }
            }

            foreach ($byStart as $group) {
                // A future period is priced one second inside it, so a window ending on its edge is already left out;
                // the live period at the chunk's instant.
                $reference = $group['start'] !== null ? Factory::getDate($group['start'])->modify('+1 second')->toSql() : $now;
                $priceContext = PriceContext::forIndex(
                    currencyId: $context['currency_id'],
                    placeId: $context['priced_at_place_id'],
                    usergroupId: 0,
                    userId: $context['user_id'] > 0 ? $context['user_id'] : null,
                )
                    ->withUserGroups(groups: array_merge([0], $context['groups']))
                    ->withReferenceDate($reference);

                try {
                    $results = $this->calculator->calculateUnits(
                        array_intersect_key($units, array_flip($group['items'])),
                        array_intersect_key($opening, array_flip($group['items'])),
                        $priceContext,
                    );
                } catch (Throwable $e) {
                    $this->lastFailure = $e->getMessage();
                    // The delete above already took the chunk's rows: the caller rolls the chunk back, keeping them.
                    $this->logWarning(
                        'Calculation failed for context ' . $context['id'] . ' at ' . ($group['start'] ?? 'now') . '; rolling this chunk back: ' . $e->getMessage(),
                    );

                    return null;
                }

                foreach ($group['items'] as $itemId) {
                    foreach ($units[$itemId] === [] ? [0] : $units[$itemId] as $combinationId) {
                        $rows[$context['id']][$itemId][$combinationId][] = [
                            'from' => $group['start'] ?? self::EPOCH,
                            'until' => $group['until'][$itemId] ?? null,
                            'figures' => self::figures(result: $results[$itemId][$combinationId] ?? null),
                        ];
                    }
                }
            }
        }

        // Equal neighbouring periods of a unit are one row.
        $values = [];

        foreach ($rows as $contextId => $perItem) {
            foreach ($perItem as $itemId => $perCombination) {
                foreach ($perCombination as $combinationId => $periods) {
                    usort($periods, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));
                    $merged = [];

                    foreach ($periods as $period) {
                        $last = $merged === [] ? null : $merged[\count($merged) - 1];

                        if ($last !== null && $last['figures'] === $period['figures'] && $last['until'] === $period['from']) {
                            $merged[\count($merged) - 1]['until'] = $period['until'];

                            continue;
                        }

                        $merged[] = $period;
                    }

                    foreach ($merged as $period) {
                        $f = $period['figures'];
                        $values[] = '(' . (int) $contextId . ',' . (int) $itemId . ',' . (int) $combinationId . ',' . $this->db->quote($period['from'])
                            . ',' . ($period['until'] === null ? 'NULL' : $this->db->quote($period['until']))
                            . ',' . implode(',', array_map(static fn (?string $v): string => $v ?? 'NULL', $f)) . ',UTC_TIMESTAMP())';

                        if ($detect) {
                            $row = ['context' => (int) $contextId, 'item' => (int) $itemId, 'combination' => (int) $combinationId, 'from' => $period['from'],
                                'until' => $period['until'], 'final' => $f['final_price'] === null ? null : self::columnText(value: $f['final_price'], places: 8)];
                            $after[self::rowKey(row: $row)] = $row;
                        }
                    }
                }
            }
        }

        foreach (array_chunk($values, self::INSERT_ROWS) as $part) {
            $this->db->setQuery(
                'INSERT INTO ' . $this->db->quoteName(self::INDEX_TABLE) . ' (' . implode(', ', $this->db->quoteName([
                    'context_id', 'item_id', 'combination_id', 'valid_from', 'valid_until', 'base_price', 'discount_amount', 'base_price_with_discounts',
                    'tax_amount', 'base_price_with_tax', 'final_price', 'discount_percent', 'updated_at',
                ])) . ') VALUES ' . implode(',', $part),
            )->execute();
        }

        // A product a preparation left out of a context, written here for it, is left out no more.
        foreach ($contexts as $context) {
            $priced = array_intersect($context['leftOut'], $itemsOf[$context['id']]);

            if ($priced !== []) {
                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName(self::CONTEXTS_TABLE) . ' SET ' . $this->db->quoteName('left_out') . ' = '
                    . $this->db->quote(implode(',', array_diff($context['leftOut'], $priced))) . ' WHERE ' . $this->db->quoteName('id') . ' = ' . (int) $context['id'],
                )->execute();
            }
        }

        if ($detect) {
            // The event's terms of each context from the contexts in hand: detection reads the rows and nothing more.
            $terms = [];

            foreach ($contexts as $context) {
                $terms[$context['id']] = ['currency' => $context['currency_id'], 'place' => $context['priced_at_place_id'], 'set' => $context['group_set_id'], 'user' => $context['user_id']];
            }

            $written = array_keys(array_filter($deleteOf, static fn (array $items): bool => $items !== []));
            $this->chunkChanges = [
                'changes' => self::rowChanges(before: $before, after: $after, terms: $terms),
                'sets' => array_values(array_unique(array_map(static fn (int $id): int => $terms[$id]['set'], $written))),
            ];
        } else {
            $this->chunkChanges = ['changes' => [], 'sets' => []];
        }

        return \count($values);
    }

    /**
     * The seven figures of one unit as the columns hold them: the engine's exact decimals at the eighth place (the
     * percentage at the second), or every one NULL when the unit has no valid price (no price row applies at the opening
     * quantity), so it sorts last and no price filter matches it. An explicit price of 0 is a price: the product is
     * free and its figures are 0.
     *
     * @param PriceResult|null $result The engine's result.
     *
     * @return array{base_price: ?string, discount_amount: ?string, base_price_with_discounts: ?string, tax_amount: ?string, base_price_with_tax: ?string, final_price: ?string, discount_percent: ?string}
     *
     * @since  2.2.2
     */
    private static function figures(?PriceResult $result): array
    {
        $none = ['base_price' => null, 'discount_amount' => null, 'base_price_with_discounts' => null, 'tax_amount' => null,
            'base_price_with_tax' => null, 'final_price' => null, 'discount_percent' => null];

        if ($result === null || !$result->isPriced()) {
            return $none;
        }

        // Each figure is the price of ONE piece at the opening quantity, as the card prints it: the one unit rule.
        $figure = static fn (string $name): string => ExactAmount::of(PriceUnits::exact(price: $result, figure: $name))->roundedAt(8)->text();

        return [
            'base_price' => $figure('base'),
            // The saving beside the price paid: the tax-included saving, on the basis final_price is on.
            'discount_amount' => $figure('discount_with_tax'),
            'base_price_with_discounts' => $figure('subtotal'),
            'tax_amount' => $figure('tax'),
            // The undiscounted gross: the struck price.
            'base_price_with_tax' => $figure('base_with_tax'),
            'final_price' => $figure('final'),
            // Derived from money on minor units (PriceResult::getSavingsPercent()), published at two places.
            'discount_percent' => PublishedDecimal::percent($result->getSavingsPercent()),
        ];
    }

    /**
     * A decimal as a column of the given scale prints it: fixed point, padded to the places.
     *
     * @param string $value An exact decimal.
     * @param int $places The column's scale.
     *
     * @since  2.2.2
     */
    private static function columnText(string $value, int $places): string
    {
        $text = ExactAmount::of($value)->roundedAt($places)->text();
        $dot = strpos($text, '.');
        $have = $dot === false ? 0 : \strlen($text) - $dot - 1;

        return ($dot === false ? $text . '.' : $text) . str_repeat('0', $places - $have);
    }

    // The price-change event

    /**
     * Run one write of the index as one announcement of onAlfaPricesChanged: whether anything listens is asked once, the
     * committed chunks' changes join it, and it goes out once, after the outermost commit that holds them. A write made
     * inside another one joins it and keeps its cause.
     *
     * @param string $cause Why the rows are written (a PricesChangedEvent::CAUSE_ constant).
     * @param int $causeId What caused it; 0 for a rebuild.
     * @param callable $work fn(): mixed, the write.
     *
     * @return mixed What the write answers.
     *
     * @since  2.2.0
     */
    public static function announcing(string $cause, int $causeId, callable $work): mixed
    {
        if (self::$announcement !== null) {
            return $work();
        }

        $announcement = (object) ['cause' => $cause, 'causeId' => max(0, $causeId), 'listening' => self::listening(), 'items' => [], 'sets' => [], 'changes' => []];
        self::$announcement = $announcement;

        try {
            return $work();
        } finally {
            self::$announcement = null;

            if ($announcement->listening) {
                $deliver = static function () use ($announcement): void {
                    self::deliver(announcement: $announcement);
                };

                PostCommit::defer(db: TransactionState::driver(Factory::getContainer()->get('DatabaseDriver')), label: PricesChangedEvent::NAME, work: $deliver);
            }
        }
    }

    /**
     * Announce the stored price periods that began: onAlfaPricesChanged with the cause window, one PriceChange per unit
     * of a context whose final price moved at the moment its period began, the period that ended as the old price.
     * Run by each turn of the task runner; nothing is written to the index.
     *
     * HOW FAR: the cursor row DueRead::META_WINDOWS; a position inside a moment names the row's end and its key
     * (context, product, combination). A cursor whose position names the previous index's key keeps its moment, the
     * position dropped.
     *
     * @since  2.2.2
     */
    public static function announceOpenedWindows(): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        if (!$db instanceof DatabaseDriver) {
            return;
        }

        $until = DueRead::windowsUntil();
        $cursor = self::windowCursor(db: $db, lock: false);

        if ($cursor === null || !$db->setQuery(DueRead::windowsProbe(db: $db, until: $until), 0, 1)->loadResult()) {
            return;
        }

        self::announcing(cause: PricesChangedEvent::CAUSE_WINDOW, causeId: 0, work: static function () use ($db, $until): void {
            $announcement = self::$announcement;
            $changes = [];
            $db->transactionStart(true);

            try {
                $cursor = self::windowCursor(db: $db, lock: true);

                if ($cursor === null) {
                    TransactionState::commitLevel(db: $db);

                    return;
                }

                $rows = $announcement !== null && $announcement->listening
                    ? self::openedPeriods(db: $db, from: $cursor['from'], after: $cursor['after'], until: $until, limit: self::ANNOUNCE_LIMIT + 1)
                    : [];
                $capped = \count($rows) > self::ANNOUNCE_LIMIT;
                $rows = \array_slice($rows, 0, self::ANNOUNCE_LIMIT);
                $last = $rows === [] ? null : $rows[array_key_last($rows)];
                $next = $capped && $last !== null
                    ? $last->valid_from . '#' . implode(',', [$last->valid_until ?? '-', (int) $last->context_id, (int) $last->item_id, (int) $last->combination_id])
                    : gmdate('Y-m-d H:i:s', (int) strtotime($until . ' UTC') + 1);

                // Never back.
                if (strcmp(substr($next, 0, 19), $cursor['from']) >= 0) {
                    $db->setQuery(
                        'UPDATE ' . $db->quoteName('#__alfa_meta') . ' SET ' . $db->quoteName('value') . ' = ' . $db->quote($next)
                        . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote(DueRead::META_WINDOWS),
                    )->execute();
                }

                foreach ($rows as $row) {
                    $old = (int) $row->had_before === 1 && $row->old_final !== null ? (string) $row->old_final : null;
                    $new = $row->final_price === null ? null : (string) $row->final_price;

                    if ($old === $new && (int) $row->had_before === 1) {
                        continue;
                    }

                    $changes[] = new PriceChange(
                        itemId: (int) $row->item_id,
                        combinationId: (int) $row->combination_id,
                        currencyId: (int) $row->currency_id,
                        placeId: (int) $row->priced_at_place_id,
                        groupSetId: (int) $row->group_set_id,
                        validFrom: (string) $row->valid_from,
                        validUntil: $row->valid_until === null ? null : (string) $row->valid_until,
                        oldFinalPrice: $old,
                        newFinalPrice: $new,
                        contextId: (int) $row->context_id,
                        userId: (int) $row->user_id,
                    );
                }

                TransactionState::commitLevel(db: $db);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $db, failure: $e);

                throw $e;
            }

            if ($announcement === null || !$announcement->listening) {
                return;
            }

            foreach ($changes as $change) {
                $announcement->changes[] = $change;
                $announcement->items[$change->itemId] = true;
                $announcement->sets[$change->groupSetId] = true;
            }
        });
    }

    /**
     * The cursor of the announced price periods: its moment and, while that moment is announced in parts, the position
     * of the last row announced in it. Null, with a WARNING once a request, when the row is missing or unreadable.
     *
     * @param DatabaseDriver $db The connection.
     * @param bool $lock Read it under a row lock.
     *
     * @return array{from: string, after: array{until: string|null, slot: list<int>}|null}|null
     *
     * @since  2.2.2
     */
    private static function windowCursor(DatabaseDriver $db, bool $lock): ?array
    {
        $value = $db->setQuery(
            'SELECT ' . $db->quoteName('value') . ' FROM ' . $db->quoteName('#__alfa_meta')
            . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote(DueRead::META_WINDOWS) . ($lock ? ' FOR UPDATE' : ''),
        )->loadResult();
        $moment = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        $parts = $value === null ? [] : explode('#', (string) $value, 2);
        $from = $parts[0] ?? '';
        $position = isset($parts[1]) ? explode(',', $parts[1]) : null;

        // A position written for the previous index's key (six parts) names no row of this index: the moment is kept.
        if ($position !== null && \count($position) === 6) {
            $position = null;
        }

        $readable = preg_match($moment, $from) === 1
            && ($position === null || (\count($position) === 4 && ($position[0] === '-' || preg_match($moment, $position[0]) === 1)));

        if (!$readable) {
            if (self::$cursorWarned) {
                return null;
            }

            self::$cursorWarned = true;
            AlfaLog::register();
            Log::add(
                '[PriceIndexSyncService] The cursor of the announced price periods (#__alfa_meta ' . DueRead::META_WINDOWS . ') is '
                . ($value === null ? 'missing' : 'unreadable') . '; no period is announced until the installation writes it again.',
                Log::WARNING,
                'com_alfa',
            );

            return null;
        }

        return [
            'from' => $from,
            'after' => $position === null ? null : [
                'until' => $position[0] === '-' ? null : $position[0],
                'slot' => array_map('intval', \array_slice($position, 1)),
            ],
        ];
    }

    /**
     * The stored periods that began after the cursor and no later than $until, each with the final price of the period
     * of its unit that ended at that moment (had_before 0: none) and its context's terms, in idx_validity's order.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $from The cursor's moment.
     * @param array{until: string|null, slot: list<int>}|null $after The last row announced at that moment, or null.
     * @param string $until The latest moment.
     * @param int $limit At most this many rows.
     *
     * @return list<object>
     *
     * @since  2.2.2
     */
    private static function openedPeriods(DatabaseDriver $db, string $from, ?array $after, string $until, int $limit): array
    {
        return $db->setQuery(self::openedPeriodsQuery(db: $db, from: $from, after: $after, until: $until), 0, $limit)->loadObjectList() ?: [];
    }

    /**
     * The statement of {@see self::openedPeriods()}, without its limit. THE ORDER IS idx_validity's: valid_from,
     * valid_until, then the primary key's remaining columns (context, product, combination), which InnoDB keeps after
     * the index's own. The position is the whole order key, written as a disjunction of ranges on those columns.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $from The cursor's moment.
     * @param array{until: string|null, slot: list<int>}|null $after The position inside that moment, or null.
     * @param string $until The latest moment.
     *
     * @since  2.2.2
     */
    private static function openedPeriodsQuery(DatabaseDriver $db, string $from, ?array $after, string $until): string
    {
        $slot = ['context_id', 'item_id', 'combination_id'];
        $column = static fn (string $name): string => $db->quoteName('i.' . $name);
        $join = implode(' AND ', array_map(static fn (string $name): string => $db->quoteName('p.' . $name) . ' = ' . $db->quoteName('i.' . $name), $slot));
        $position = '';

        if ($after !== null) {
            $atMoment = $column('valid_from') . ' = ' . $db->quote($from);
            $sameEnd = $after['until'] === null ? $column('valid_until') . ' IS NULL' : $column('valid_until') . ' = ' . $db->quote($after['until']);
            $laterEnd = $after['until'] === null ? $column('valid_until') . ' IS NOT NULL' : $column('valid_until') . ' > ' . $db->quote($after['until']);
            $ranges = [$column('valid_from') . ' > ' . $db->quote($from), '(' . $atMoment . ' AND ' . $laterEnd . ')'];

            foreach ($slot as $i => $name) {
                $equal = array_map(static fn (string $prior, int $value): string => $column($prior) . ' = ' . $value, \array_slice($slot, 0, $i), \array_slice($after['slot'], 0, $i));
                $ranges[] = '(' . implode(' AND ', [$atMoment, $sameEnd, ...$equal, $column($name) . ' > ' . (int) ($after['slot'][$i] ?? 0)]) . ')';
            }

            $position = ' AND (' . implode(' OR ', $ranges) . ')';
        }

        return 'SELECT ' . implode(', ', array_map($column, [...$slot, 'valid_from', 'valid_until', 'final_price']))
            . ', ' . $db->quoteName('p.final_price', 'old_final') . ', (' . $db->quoteName('p.item_id') . ' IS NOT NULL) AS ' . $db->quoteName('had_before')
            . ', COALESCE(' . $db->quoteName('c.currency_id') . ', 0) AS ' . $db->quoteName('currency_id')
            . ', COALESCE(' . $db->quoteName('c.priced_at_place_id') . ', 0) AS ' . $db->quoteName('priced_at_place_id')
            . ', COALESCE(' . $db->quoteName('c.group_set_id') . ', 0) AS ' . $db->quoteName('group_set_id')
            . ', COALESCE(' . $db->quoteName('c.user_id') . ', 0) AS ' . $db->quoteName('user_id')
            . ' FROM ' . $db->quoteName(self::INDEX_TABLE, 'i')
            . ' LEFT JOIN ' . $db->quoteName(self::INDEX_TABLE, 'p') . ' ON ' . $join . ' AND ' . $db->quoteName('p.valid_until') . ' = ' . $column('valid_from')
            // The live generations only: a generation being prepared or retired is read by no visitor.
            . ' INNER JOIN ' . $db->quoteName(self::CONTEXTS_TABLE, 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $column('context_id') . ' AND ' . $db->quoteName('c.is_live') . ' = 1'
            . ' WHERE ' . $column('valid_from') . ' >= ' . $db->quote($from) . ' AND ' . $column('valid_from') . ' <= ' . $db->quote($until) . $position
            . ' ORDER BY ' . implode(', ', array_map($column, ['valid_from', 'valid_until', ...$slot]));
    }

    /**
     * Whether anything listens to onAlfaPricesChanged on the shared dispatcher, the alfa group imported first.
     *
     * @since  2.2.0
     */
    private static function listening(): bool
    {
        try {
            $plugins = AlfaPluginHelper::get();
            $plugins->importGroup('alfa');

            return $plugins->hasListeners(PricesChangedEvent::NAME);
        } catch (Throwable $e) {
            Log::add('[PriceIndexSyncService] Whether anything listens to ' . PricesChangedEvent::NAME . ' could not be asked; nothing is announced: ' . $e->getMessage(), Log::WARNING, 'com_alfa');

            return false;
        }
    }

    /**
     * Whether the write running now detects its changes: an announcement is open and something listens.
     *
     * @since  2.2.0
     */
    private static function detecting(): bool
    {
        return self::$announcement?->listening === true;
    }

    /**
     * The last chunk committed: what it changed joins the announcement once every level around it has committed.
     *
     * @since  2.2.0
     */
    private function keepChunkChanges(): void
    {
        $kept = $this->chunkChanges;
        $this->dropChunkChanges();
        $announcement = self::$announcement;

        if ($announcement === null || !$announcement->listening || ($kept['changes'] === [] && $kept['sets'] === [])) {
            return;
        }

        $db = $this->db;
        $join = static function () use ($announcement, $kept, $db): void {
            foreach ($kept['changes'] as $change) {
                $announcement->changes[] = $change;
                $announcement->items[$change->itemId] = true;
            }

            foreach ($kept['sets'] as $setId) {
                $announcement->sets[$setId] = true;
            }

            // THE BOUND: past the limit, with no transaction open, what is collected goes out as its own event.
            if (\count($announcement->changes) >= self::ANNOUNCE_LIMIT && !TransactionState::isOpen($db)) {
                $part = clone $announcement;
                $announcement->changes = [];
                $announcement->items = [];
                $announcement->sets = [];
                self::deliver(announcement: $part);
            }
        };

        PostCommit::defer(db: $this->db, label: PricesChangedEvent::NAME, work: $join);
    }

    /**
     * Forget what the last chunk changed: it was rolled back, or never written.
     *
     * @since  2.2.0
     */
    private function dropChunkChanges(): void
    {
        $this->chunkChanges = ['changes' => [], 'sets' => []];
    }

    /**
     * Deliver an announcement that holds changes. One raised while another is being delivered waits for it.
     *
     * @param stdClass $announcement The announcement.
     *
     * @since  2.2.0
     */
    private static function deliver(stdClass $announcement): void
    {
        if ($announcement->changes === []) {
            return;
        }

        if (self::$delivering) {
            self::$deliveries[] = $announcement;

            return;
        }

        self::$delivering = true;

        try {
            self::dispatch(announcement: $announcement);

            while (self::$deliveries !== []) {
                self::dispatch(announcement: array_shift(self::$deliveries));
            }
        } finally {
            self::$delivering = false;
            self::$deliveries = [];
        }
    }

    /**
     * Send one announcement: onAlfaPricesChanged on the shared dispatcher, an after phase (isolated).
     *
     * @param stdClass $announcement The announcement, holding changes.
     *
     * @since  2.2.0
     */
    private static function dispatch(stdClass $announcement): void
    {
        $itemIds = array_keys($announcement->items);
        $setIds = array_keys($announcement->sets);
        sort($itemIds);
        sort($setIds);

        try {
            $plugins = AlfaPluginHelper::get();
            $plugins->importGroup('alfa');
            $plugins->dispatchIsolated(new PricesChangedEvent(PricesChangedEvent::NAME, [
                'itemIds' => $itemIds,
                'cause' => $announcement->cause,
                'causeId' => $announcement->causeId,
                'groupSetIds' => $setIds,
                'changes' => $announcement->changes,
            ]));
        } catch (Throwable $e) {
            Log::add('[PriceIndexSyncService] ' . PricesChangedEvent::NAME . ' was not delivered: ' . $e->getMessage(), Log::WARNING, 'com_alfa');
        }
    }

    /**
     * The index rows of some products as stored, of some contexts or of every context, under a locking read.
     *
     * @param int[] $itemIds The products.
     * @param int[]|null $contextIds The contexts; null for every context.
     *
     * @return array<string, array{context: int, item: int, combination: int, from: string, until: string|null, final: string|null}> By row key.
     *
     * @since  2.2.0
     */
    private function readIndexRows(array $itemIds, ?array $contextIds): array
    {
        $db = $this->db;
        $sql = 'SELECT ' . implode(', ', $db->quoteName(['context_id', 'item_id', 'combination_id', 'valid_from', 'valid_until', 'final_price']))
            . ' FROM ' . $db->quoteName(self::INDEX_TABLE) . ' WHERE ' . $db->quoteName('item_id') . ' IN (' . implode(',', array_map('intval', $itemIds)) . ')'
            . ($contextIds !== null ? ' AND ' . $db->quoteName('context_id') . ' IN (' . implode(',', array_map('intval', $contextIds)) . ')' : '');
        $rows = [];

        foreach ($db->setQuery($sql . ' FOR UPDATE')->loadObjectList() ?: [] as $stored) {
            $row = [
                'context' => (int) $stored->context_id, 'item' => (int) $stored->item_id, 'combination' => (int) $stored->combination_id,
                'from' => (string) $stored->valid_from, 'until' => $stored->valid_until === null ? null : (string) $stored->valid_until,
                'final' => $stored->final_price === null ? null : (string) $stored->final_price,
            ];
            $rows[self::rowKey(row: $row)] = $row;
        }

        return $rows;
    }

    /**
     * A row's identity: the index's primary key.
     *
     * @param array{context: int, item: int, combination: int, from: string} $row The row.
     *
     * @since  2.2.0
     */
    private static function rowKey(array $row): string
    {
        return $row['context'] . '|' . $row['item'] . '|' . $row['combination'] . '|' . $row['from'];
    }

    /**
     * The rows that changed between two readings of the same rows, by key: a final price or a window end that differs,
     * a row new, a row gone.
     *
     * @param array<string, array> $before The rows before, by key.
     * @param array<string, array> $after The rows after, by key.
     * @param array<int, array{currency: int, place: int, set: int, user: int}> $terms The terms of the rows' contexts, by id.
     *
     * @return PriceChange[]
     *
     * @since  2.2.0
     */
    private static function rowChanges(array $before, array $after, array $terms): array
    {
        $changes = [];

        foreach ($after + $before as $key => $row) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== null && $new !== null && $old['final'] === $new['final'] && $old['until'] === $new['until']) {
                continue;
            }

            $term = $terms[$row['context']] ?? ['currency' => 0, 'place' => 0, 'set' => 0, 'user' => 0];
            $changes[] = new PriceChange(
                itemId:        $row['item'],
                combinationId: $row['combination'],
                currencyId:    $term['currency'],
                placeId:       $term['place'],
                groupSetId:    $term['set'],
                validFrom:     $row['from'],
                validUntil:    ($new ?? $old)['until'],
                oldFinalPrice: $old['final'] ?? null,
                newFinalPrice: $new['final'] ?? null,
                contextId:     $row['context'],
                userId:        $term['user'],
            );
        }

        return $changes;
    }

    /**
     * The terms of some contexts, for the event.
     *
     * @param int[] $ids The contexts.
     *
     * @return array<int, array{currency: int, place: int, set: int, user: int}>
     *
     * @since  2.2.2
     */
    private function contextTerms(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'currency_id', 'priced_at_place_id', 'group_set_id', 'user_id'])) . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE)
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')',
        )->loadObjectList() ?: [] as $row) {
            $out[(int) $row->id] = ['currency' => (int) $row->currency_id, 'place' => (int) $row->priced_at_place_id, 'set' => (int) $row->group_set_id, 'user' => (int) $row->user_id];
        }

        return $out;
    }

    // Periods

    /**
     * Every FUTURE edge of each product's periods, ascending: the windows of all its published price rows, item and
     * combination rows alike (a combination's own scheduled price is an edge), and the windows of the published
     * discounts and taxes that can reach the product (their category and manufacturer scope, matched as the engine's
     * loader matches it; their place, group and customer scope narrow no product), with no horizon. An edge that
     * changes nothing for a unit adds no row: equal neighbouring periods are merged.
     *
     * @param int[] $itemIds The products.
     * @param string $now The chunk's instant (UTC): later than it is future.
     *
     * @return array<int, string[]> By product id.
     *
     * @since  2.2.2
     */
    private function edgesOf(array $itemIds, string $now): array
    {
        if ($itemIds === []) {
            return [];
        }

        $db = $this->db;
        $list = implode(',', array_map('intval', $itemIds));
        $now = $db->quote($now);
        $prices = $db->quoteName('#__alfa_items_prices');
        $found = [];
        $rules = static fn (string $rules, string $categories, string $manufacturers, string $key): string => 'SELECT DISTINCT ic.item_id, r.publish_up, r.publish_down'
            . ' FROM ' . $db->quoteName($rules) . ' AS r'
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items_categories') . ' AS ic ON ic.item_id IN (' . $list . ')'
            . ' LEFT JOIN ' . $db->quoteName($categories) . ' AS sc ON sc.' . $key . ' = r.id'
            . ' LEFT JOIN ' . $db->quoteName($manufacturers) . ' AS sm ON sm.' . $key . ' = r.id'
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_items_manufacturers') . ' AS im ON im.item_id = ic.item_id'
            . ' WHERE r.state = 1 AND ((r.publish_up IS NOT NULL AND r.publish_up > ' . $now . ') OR (r.publish_down IS NOT NULL AND r.publish_down > ' . $now . '))'
            . ' AND (sc.' . $key . ' IS NULL OR sc.category_id = 0 OR sc.category_id = ic.category_id)'
            . ' AND (sm.' . $key . ' IS NULL OR sm.manufacturer_id = 0 OR sm.manufacturer_id = im.manufacturer_id)';

        foreach ([
            $rules('#__alfa_discounts', '#__alfa_discount_categories', '#__alfa_discount_manufacturers', 'discount_id'),
            $rules('#__alfa_taxes', '#__alfa_tax_categories', '#__alfa_tax_manufacturers', 'tax_id'),
            "SELECT DISTINCT item_id, publish_up, publish_down FROM {$prices} WHERE state = 1 AND item_id IN ({$list})"
                . " AND ((publish_up IS NOT NULL AND publish_up > {$now}) OR (publish_down IS NOT NULL AND publish_down > {$now}))",
        ] as $statement) {
            foreach ($db->setQuery($statement)->loadObjectList() ?: [] as $row) {
                foreach ([$row->publish_up, $row->publish_down] as $edge) {
                    if ($edge !== null && strcmp((string) $edge, trim($now, "'")) > 0) {
                        $found[(int) $row->item_id][(string) $edge] = true;
                    }
                }
            }
        }

        $edges = [];

        foreach ($itemIds as $itemId) {
            $all = array_keys($found[(int) $itemId] ?? []);
            sort($all);
            $edges[(int) $itemId] = $all;
        }

        return $edges;
    }

    // Contexts

    /**
     * Bring the contexts in line with the data and answer the contexts to write.
     *
     * The sets first (alignGroupSets()); then the currency classes (the currencies the published price rows name, 0
     * only while a row of currency 0 exists), the place classes (placeClasses()) and the named customers. Every
     * combination is a context of a set; every named customer has those of their set. A context new to the data is
     * requested: prepared at once while the index is built after the update or while no context is prepared at all
     * (nothing to keep), else waiting with its job. A context the data no longer defines is dropped with its rows.
     *
     * @return list<array{id: int, currency_id: int, rule_place_id: int, price_place_id: int, priced_at_place_id: int, group_set_id: int, user_id: int, groups: int[], through: int|null, leftOut: int[]}>
     *
     * @since  2.2.2
     */
    private function alignedContexts(): array
    {
        self::$readContexts = [];
        self::$resolved = [];
        [, $sets] = $this->alignGroupSets();
        $currencies = array_map('intval', $this->db->setQuery(
            'SELECT DISTINCT ' . $this->db->quoteName('currency_id') . ' FROM ' . $this->db->quoteName('#__alfa_items_prices') . ' WHERE ' . $this->db->quoteName('state') . ' = 1',
        )->loadColumn() ?: []);
        $classes = $currencies === [] || $sets === [] ? [] : $this->placeClasses();
        $customers = $currencies === [] || $sets === [] ? [] : $this->namedCustomers(sets: $sets);
        $defined = [];

        foreach ($currencies as $currency) {
            foreach ($classes as [$rule, $price, $pricedAt]) {
                foreach (array_keys($sets) as $setId) {
                    $defined[$currency . '|' . $rule . '|' . $price . '|' . $setId . '|0'] = [$currency, $rule, $price, $pricedAt, (int) $setId, 0];
                }

                foreach ($customers as $userId => $setId) {
                    $defined[$currency . '|' . $rule . '|' . $price . '|' . $setId . '|' . $userId] = [$currency, $rule, $price, $pricedAt, $setId, $userId];
                }
            }
        }

        // Every generation of each context the table holds, by the context's key.
        $known = [];
        $anyPrepared = false;

        foreach ($this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'currency_id', 'rule_place_id', 'price_place_id', 'group_set_id', 'user_id', 'is_live']))
            . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE),
        )->loadObjectList() ?: [] as $row) {
            $known[(int) $row->currency_id . '|' . (int) $row->rule_place_id . '|' . (int) $row->price_place_id . '|' . (int) $row->group_set_id . '|' . (int) $row->user_id][] = (int) $row->id;
            $anyPrepared = $anyPrepared || (int) $row->is_live === 1;
        }

        $this->dropContexts(ids: array_merge([], ...array_values(array_diff_key($known, $defined))));
        $new = array_diff_key($defined, $known);
        $this->lastRequested = 0;

        if ($new !== []) {
            $building = self::buildStartedAt() !== null;
            $preparedNow = $building || !$anyPrepared;
            $rows = [];

            foreach ($new as [$currency, $rule, $price, $pricedAt, $setId, $userId]) {
                $rows[] = '(' . implode(',', [$currency, $rule, $price, $pricedAt, $setId, $userId]) . ', 1, ' . ($preparedNow ? '1' : 'NULL') . ', UTC_TIMESTAMP(), '
                    . ($preparedNow ? 'UTC_TIMESTAMP()' : 'NULL') . ')';
            }

            foreach (array_chunk($rows, self::INSERT_ROWS) as $part) {
                $this->db->setQuery(
                    'INSERT IGNORE INTO ' . $this->db->quoteName(self::CONTEXTS_TABLE) . ' (' . implode(', ', $this->db->quoteName([
                        'currency_id', 'rule_place_id', 'price_place_id', 'priced_at_place_id', 'group_set_id', 'user_id', 'generation', 'is_live', 'requested_at', 'prepared_at',
                    ])) . ') VALUES ' . implode(', ', $part),
                )->execute();
                $this->lastRequested += (int) $this->db->getAffectedRows();
            }

            if (!$preparedNow) {
                foreach ($this->db->setQuery(
                    'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $this->db->quoteName('prepared_at') . ' IS NULL',
                )->loadColumn() ?: [] as $id) {
                    PriceSetPrepareJob::enqueueFor(contextId: (int) $id);
                }
            } elseif (!$building && $this->lastRequested > 0) {
                // A shop's first contexts, read at once: the products saved before its first price get their rows (with
                // no figure where they have no price) from the catalogue job.
                ProjectionManager::owe(itemIds: $this->getAllPublishedItemIds());
            }
        }

        return array_values($this->contextRows(ids: null, waitingOnly: false));
    }

    /**
     * Contexts as the writer takes them: their terms, their set's groups, their progress and what their preparation
     * left out.
     *
     * @param int[]|null $ids The contexts; null for every one.
     * @param bool $waitingOnly Only contexts that wait for their preparation.
     *
     * @return array<int, array{id: int, currency_id: int, rule_place_id: int, price_place_id: int, priced_at_place_id: int, group_set_id: int, user_id: int, groups: int[], through: int|null, leftOut: int[], liveOf: int|null}>
     *
     * @since  2.2.2
     */
    private function contextRows(?array $ids, bool $waitingOnly): array
    {
        if ($ids === []) {
            return [];
        }

        $where = [];

        if ($ids !== null) {
            $where[] = 'c.id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }

        if ($waitingOnly) {
            $where[] = 'c.prepared_at IS NULL';
        }

        // A retired generation is no longer written: its rows wait for the tidy.
        $where[] = 'c.retired_at IS NULL';
        $out = [];

        foreach ($this->db->setQuery(
            'SELECT c.id, c.currency_id, c.rule_place_id, c.price_place_id, c.priced_at_place_id, c.group_set_id, c.user_id, c.prepared_at, c.prepared_through, c.left_out, s.group_list,'
            . ' l.id AS live_id'
            . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE, 'c')
            . ' INNER JOIN ' . $this->db->quoteName(self::SETS_TABLE, 's') . ' ON s.id = c.group_set_id'
            . ' LEFT JOIN ' . $this->db->quoteName(self::CONTEXTS_TABLE, 'l') . ' ON c.prepared_at IS NULL AND l.is_live = 1 AND l.id <> c.id'
            . ' AND l.currency_id = c.currency_id AND l.rule_place_id = c.rule_place_id AND l.price_place_id = c.price_place_id'
            . ' AND l.group_set_id = c.group_set_id AND l.user_id = c.user_id'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.id',
        )->loadObjectList() ?: [] as $row) {
            $out[(int) $row->id] = [
                'id' => (int) $row->id,
                'currency_id' => (int) $row->currency_id,
                'rule_place_id' => (int) $row->rule_place_id,
                'price_place_id' => (int) $row->price_place_id,
                'priced_at_place_id' => (int) $row->priced_at_place_id,
                'group_set_id' => (int) $row->group_set_id,
                'user_id' => (int) $row->user_id,
                'groups' => self::groupsOfList(list: (string) $row->group_list),
                'through' => $row->prepared_at === null ? (int) $row->prepared_through : null,
                'leftOut' => self::groupsOfList(list: (string) $row->left_out),
                // A generation being prepared beside the live one of its context: the live one's id.
                'liveOf' => $row->live_id === null ? null : (int) $row->live_id,
            ];
        }

        return $out;
    }

    /**
     * Remove some contexts with every row of theirs, the context's row first in the same transaction: a preparer
     * writing a chunk of it holds that row, so the two never cross.
     *
     * @param int[] $ids The contexts; none, no statement.
     *
     * @since  2.2.2
     */
    private function dropContexts(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        $list = implode(',', $ids);
        $this->db->transactionStart(true);

        try {
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $list . ')')->execute();
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::INDEX_TABLE) . ' WHERE ' . $this->db->quoteName('context_id') . ' IN (' . $list . ')')->execute();
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $this->db, failure: $e);

            throw $e;
        }
    }

    /**
     * The place classes the data defines, each as [rule place, price place, the place it is priced at].
     *
     * Rule places: the places a published discount or tax names; price places: the places a published price row names;
     * each kept only while it prices (PlaceTree::placesFor(): it and every place above it published). The classes:
     * (0, 0) at no place; (r, 0) for each rule place r, priced at r when r has no price rows, else at a place whose
     * nearest rule place is r and which has none (no such place, no class); (n, q) for each price place q, n the
     * nearest rule place of its chain, priced at q.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     *
     * @since  2.2.2
     */
    private function placeClasses(): array
    {
        $db = $this->db;
        $places = $db->quoteName('#__alfa_places');
        $ruled = static fn (string $pivot, string $rules, string $key): string => 'SELECT DISTINCT x.place_id FROM ' . $db->quoteName($pivot) . ' AS x'
            . ' INNER JOIN ' . $db->quoteName($rules) . ' AS r ON r.id = x.' . $key . ' AND r.state = 1'
            . ' INNER JOIN ' . $places . ' AS p ON p.id = x.place_id WHERE x.place_id > 0';
        PlaceTree::forget();
        $pricing = static fn (array $ids): array => array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => PlaceTree::placesFor(placeId: $id) !== []));
        $rule = array_flip($pricing($db->setQuery(
            $ruled('#__alfa_discount_places', '#__alfa_discounts', 'discount_id') . ' UNION ' . $ruled('#__alfa_tax_places', '#__alfa_taxes', 'tax_id'),
        )->loadColumn() ?: []));
        $price = array_flip($pricing($db->setQuery(
            'SELECT DISTINCT ip.country_id FROM ' . $db->quoteName('#__alfa_items_prices') . ' AS ip INNER JOIN ' . $places . ' AS p ON p.id = ip.country_id'
            . ' WHERE ip.state = 1 AND ip.country_id > 0',
        )->loadColumn() ?: []));
        $nearest = static function (int $place) use ($rule): int {
            foreach (PlaceTree::placesFor(placeId: $place) as $step) {
                if (isset($rule[$step])) {
                    return $step;
                }
            }

            return 0;
        };
        $classes = ['0|0' => [0, 0, 0]];
        $all = null;

        foreach (array_keys($rule) as $r) {
            $member = isset($price[$r]) ? 0 : $r;

            if ($member === 0) {
                $all ??= array_map('intval', $db->setQuery('SELECT ' . $db->quoteName('id') . ' FROM ' . $places . ' ORDER BY ' . $db->quoteName('id'))->loadColumn() ?: []);

                foreach ($all as $candidate) {
                    if (!isset($price[$candidate]) && $nearest($candidate) === $r) {
                        $member = $candidate;

                        break;
                    }
                }
            }

            if ($member > 0) {
                $classes[$r . '|0'] = [$r, 0, $member];
            }
        }

        foreach (array_keys($price) as $q) {
            $n = $nearest($q);
            $classes[$n . '|' . $q] = [$n, $q, $q];
        }

        return array_values($classes);
    }

    /**
     * The customers a published price row, a discount or a tax names, each with the set of pricing groups they hold,
     * among the sets the index keeps.
     *
     * @param array<int, int[]> $sets The sets kept, by id.
     *
     * @return array<int, int> Set id by user id.
     *
     * @since  2.2.2
     */
    private function namedCustomers(array $sets): array
    {
        $db = $this->db;
        $users = array_map('intval', $db->setQuery(
            'SELECT ' . $db->quoteName('user_id') . ' FROM ' . $db->quoteName('#__alfa_items_prices') . ' WHERE ' . $db->quoteName('state') . ' = 1 AND ' . $db->quoteName('user_id') . ' > 0'
            . ' UNION SELECT du.user_id FROM ' . $db->quoteName('#__alfa_discount_users') . ' AS du INNER JOIN ' . $db->quoteName('#__alfa_discounts') . ' AS d ON d.id = du.discount_id AND d.state = 1 WHERE du.user_id > 0'
            . ' UNION SELECT tu.user_id FROM ' . $db->quoteName('#__alfa_tax_users') . ' AS tu INNER JOIN ' . $db->quoteName('#__alfa_taxes') . ' AS t ON t.id = tu.tax_id AND t.state = 1 WHERE tu.user_id > 0',
        )->loadColumn() ?: []);

        if ($users === []) {
            return [];
        }

        $ids = $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'digest'])) . ' FROM ' . $db->quoteName(self::SETS_TABLE)
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', array_keys($sets))) . ')',
        )->loadAssocList('digest', 'id') ?: [];
        $out = [];

        foreach ($this->accountGroupListsOf(userIds: $users) as $userId => $list) {
            $setId = (int) ($ids[self::groupSetKey(groupIds: self::groupsOfList(list: $list))] ?? 0);

            if ($setId > 0) {
                $out[$userId] = $setId;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * The published products one customer's own rules touch: those with a published price row of theirs, and those a
     * published discount or tax naming them can reach. Kept for the write.
     *
     * @param int $userId The customer.
     *
     * @return array<int, true>
     *
     * @since  2.2.2
     */
    private function touchedBy(int $userId): array
    {
        if (isset($this->customerProducts[$userId])) {
            return $this->customerProducts[$userId];
        }

        $db = $this->db;
        $ids = array_map('intval', $db->setQuery(
            'SELECT DISTINCT ' . $db->quoteName('item_id') . ' FROM ' . $db->quoteName('#__alfa_items_prices')
            . ' WHERE ' . $db->quoteName('state') . ' = 1 AND ' . $db->quoteName('user_id') . ' = ' . $userId,
        )->loadColumn() ?: []);

        foreach ($db->setQuery(
            'SELECT du.discount_id FROM ' . $db->quoteName('#__alfa_discount_users') . ' AS du INNER JOIN ' . $db->quoteName('#__alfa_discounts') . ' AS d ON d.id = du.discount_id AND d.state = 1'
            . ' WHERE du.user_id = ' . $userId,
        )->loadColumn() ?: [] as $discountId) {
            $ids = array_merge($ids, $this->getItemIdsForDiscount((int) $discountId));
        }

        foreach ($db->setQuery(
            'SELECT tu.tax_id FROM ' . $db->quoteName('#__alfa_tax_users') . ' AS tu INNER JOIN ' . $db->quoteName('#__alfa_taxes') . ' AS t ON t.id = tu.tax_id AND t.state = 1'
            . ' WHERE tu.user_id = ' . $userId,
        )->loadColumn() ?: [] as $taxId) {
            $ids = array_merge($ids, $this->getItemIdsForTax((int) $taxId));
        }

        return $this->customerProducts[$userId] = array_fill_keys(array_values(array_unique(array_map('intval', $ids))), true);
    }

    /**
     * Write one chunk of a context's preparation, and move its checkpoint past it, in one transaction. A product whose
     * pricing throws takes its batch with it, so then the chunk is priced again a product at a time: one that throws
     * again is left out of the context (logged and recorded on it), and the chunk commits without it.
     *
     * @param int $contextId The context being prepared.
     * @param int[] $chunk The products to write, ascending.
     * @param int $through The checkpoint the chunk moves the context to.
     * @param array $contexts The context alone, as contextRows() gives it.
     * @param array $leftOut The run's products left out; this chunk's are added.
     *
     * @return int|null Rows written; null when nothing was committed.
     *
     * @since  2.0.0
     */
    private function prepareChunk(int $contextId, array $chunk, int $through, array $contexts, array &$leftOut): ?int
    {
        $table = $this->db->quoteName(self::CONTEXTS_TABLE);
        $hold = fn (): bool => $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $table . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $contextId
            . ' AND ' . $this->db->quoteName('prepared_at') . ' IS NULL FOR UPDATE',
        )->loadResult() !== null;
        $nested = TransactionState::isOpen(db: $this->db);
        $this->customerProducts = [];
        $this->db->transactionStart(true);

        try {
            $this->lastFailure = '';
            $this->dropChunkChanges();
            $written = $hold() ? ($chunk === [] ? 0 : $this->writeChunk(chunk: $chunk, contexts: $contexts)) : null;
            $left = [];
            $kept = $written === null ? ['changes' => [], 'sets' => []] : $this->chunkChanges;

            if ($written === null && $this->lastFailure !== '') {
                $this->lastFailure = '';
                TransactionState::rollBackLevel(db: $this->db);
                $this->db->transactionStart(true);
                $written = $hold() ? 0 : null;

                foreach ($written === null ? [] : $chunk as $itemId) {
                    $this->db->transactionStart(true);

                    try {
                        $one = $this->writeChunk(chunk: [$itemId], contexts: $contexts);
                    } catch (Throwable $e) {
                        TransactionState::rollBackLevel(db: $this->db, failure: $e);

                        throw $e;
                    }

                    if ($one === null) {
                        $this->dropChunkChanges();
                        TransactionState::rollBackLevel(db: $this->db);
                        $left[] = $itemId;
                        $this->logWarning(message: "Product {$itemId} could not be priced for context {$contextId} and is left out of it: " . $this->lastFailure);
                        $this->lastFailure = '';

                        continue;
                    }

                    TransactionState::commitLevel(db: $this->db);
                    $written += $one;
                    $kept = ['changes' => array_merge($kept['changes'], $this->chunkChanges['changes']), 'sets' => array_values(array_unique(array_merge($kept['sets'], $this->chunkChanges['sets'])))];
                }
            }

            if ($written === null) {
                $this->dropChunkChanges();
                TransactionState::rollBackLevel(db: $this->db);

                return null;
            }

            $this->db->setQuery(
                'UPDATE ' . $table . ' SET ' . $this->db->quoteName('prepared_through') . ' = ' . $through
                . ($left === [] ? '' : ', ' . $this->db->quoteName('left_out') . ' = CONCAT_WS(' . $this->db->quote(',') . ', NULLIF('
                    . $this->db->quoteName('left_out') . ', ' . $this->db->quote('') . '), ' . $this->db->quote(implode(',', $left)) . ')')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $contextId,
            )->execute();
            TransactionState::commitLevel(db: $this->db);
            $this->chunkChanges = $kept;
            $this->keepChunkChanges();
            array_push($leftOut, ...$left);

            return $written;
        } catch (Throwable $e) {
            $this->dropChunkChanges();

            if (TransactionState::rollBackLevel(db: $this->db, failure: $e) && $nested) {
                throw $e;
            }

            $this->chunkFailure = $e;
            $this->logWarning(message: "Preparing context {$contextId} stopped at a chunk, which was rolled back: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Mark a generation prepared and switch it live: first every unit it is written for that no row of it covers
     * now is written (a product the preparation passed while another writer held it), a product whose pricing throws
     * left out with no figure; then, in one transaction, the live generation of the same context retired and this one
     * live, so a reader meets the old generation or the new one, never a mix. The retired generation's rows are removed
     * by the tidy job.
     *
     * @param int $contextId The generation, all of whose chunks are written.
     *
     * @return int 1 when it was switched live; 0 when it was gone or already live.
     *
     * @since  2.2.2
     */
    private function markPrepared(int $contextId): int
    {
        $context = $this->contextRows(ids: [$contextId], waitingOnly: true)[$contextId] ?? null;

        if ($context === null) {
            return 0;
        }

        $expected = $context['user_id'] > 0 ? array_keys($this->touchedBy(userId: $context['user_id'])) : $this->getAllPublishedItemIds();
        $context['through'] = null;
        $missing = $this->uncoveredOf(contextId: $contextId, itemIds: $expected);

        foreach ($missing === [] ? [] : array_chunk($missing, self::CHUNK_SIZE) as $chunk) {
            $this->writeIndexRows(itemIds: $chunk, contexts: [$context]);
        }

        $failed = $missing === [] ? [] : $this->uncoveredOf(contextId: $contextId, itemIds: $missing);

        if ($failed !== []) {
            $this->leaveOut(itemIds: $failed, contextIds: [$contextId]);
        }

        $db = $this->db;
        $retired = 0;
        $db->transactionStart(true);

        try {
            $row = $db->setQuery(
                'SELECT ' . implode(', ', $db->quoteName(['currency_id', 'rule_place_id', 'price_place_id', 'group_set_id', 'user_id']))
                . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $db->quoteName('id') . ' = ' . $contextId
                . ' AND ' . $db->quoteName('prepared_at') . ' IS NULL FOR UPDATE',
            )->loadObject();

            if ($row === null) {
                TransactionState::commitLevel(db: $db);

                return 0;
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName(self::CONTEXTS_TABLE) . ' SET ' . $db->quoteName('is_live') . ' = NULL, ' . $db->quoteName('retired_at') . ' = UTC_TIMESTAMP()'
                . ' WHERE ' . $db->quoteName('is_live') . ' = 1 AND ' . $db->quoteName('id') . ' <> ' . $contextId . ' AND ' . self::keyWhere(db: $db, row: $row),
            )->execute();
            $retired = (int) $db->getAffectedRows();
            $db->setQuery(
                'UPDATE ' . $db->quoteName(self::CONTEXTS_TABLE)
                . ' SET ' . $db->quoteName('is_live') . ' = 1, ' . $db->quoteName('prepared_at') . ' = UTC_TIMESTAMP(), ' . $db->quoteName('requested_at') . ' = NULL, '
                . $db->quoteName('prepared_through') . ' = 0'
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $contextId,
            )->execute();
            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        self::$readContexts = [];
        self::$resolved = [];

        if ($retired > 0) {
            PriceIndexTidyJob::enqueue();
        }

        return 1;
    }

    /**
     * The WHERE of one context's key: every generation of it.
     *
     * @param DatabaseDriver $db The connection.
     * @param object $row currency_id, rule_place_id, price_place_id, group_set_id, user_id.
     *
     * @since  2.2.2
     */
    private static function keyWhere(DatabaseDriver $db, object $row): string
    {
        return implode(' AND ', array_map(
            static fn (string $column): string => $db->quoteName($column) . ' = ' . (int) $row->{$column},
            ['currency_id', 'rule_place_id', 'price_place_id', 'group_set_id', 'user_id'],
        ));
    }

    // Generations

    /**
     * Start the next generation of some live contexts: beside each, a generation not yet live from its first product
     * (its checkpoint 0), or the one already being prepared started again from its first product. Readers keep reading
     * the live generation until the new one switches whole ({@see self::markPrepared()}); every write meanwhile goes to
     * the live generation and, for the products it has passed, to the new one.
     *
     * @param int[] $liveIds The live contexts.
     *
     * @return int[] The generations being prepared.
     *
     * @since  2.2.2
     */
    private function regenerate(array $liveIds): array
    {
        $liveIds = array_values(array_unique(array_filter(array_map('intval', $liveIds), static fn (int $id): bool => $id > 0)));

        if ($liveIds === []) {
            return [];
        }

        $db = $this->db;
        $building = [];

        foreach ($db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'currency_id', 'rule_place_id', 'price_place_id', 'priced_at_place_id', 'group_set_id', 'user_id']))
            . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $db->quoteName('is_live') . ' = 1 AND ' . $db->quoteName('id') . ' IN (' . implode(',', $liveIds) . ')',
        )->loadObjectList() ?: [] as $row) {
            $key = self::keyWhere(db: $db, row: $row);
            $existing = (int) $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $key
                . ' AND ' . $db->quoteName('prepared_at') . ' IS NULL AND ' . $db->quoteName('retired_at') . ' IS NULL',
            )->loadResult();

            if ($existing > 0) {
                $db->setQuery(
                    'UPDATE ' . $db->quoteName(self::CONTEXTS_TABLE) . ' SET ' . $db->quoteName('prepared_through') . ' = 0, ' . $db->quoteName('left_out') . ' = NULL, '
                    . $db->quoteName('requested_at') . ' = UTC_TIMESTAMP() WHERE ' . $db->quoteName('id') . ' = ' . $existing,
                )->execute();
                $building[] = $existing;

                continue;
            }

            $next = (int) $db->setQuery(
                'SELECT COALESCE(MAX(' . $db->quoteName('generation') . '), 0) + 1 FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $key,
            )->loadResult();
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName(self::CONTEXTS_TABLE) . ' (' . implode(', ', $db->quoteName([
                    'currency_id', 'rule_place_id', 'price_place_id', 'priced_at_place_id', 'group_set_id', 'user_id', 'generation', 'is_live', 'requested_at', 'prepared_at',
                ])) . ') VALUES (' . implode(',', [(int) $row->currency_id, (int) $row->rule_place_id, (int) $row->price_place_id, (int) $row->priced_at_place_id,
                    (int) $row->group_set_id, (int) $row->user_id, $next]) . ', NULL, UTC_TIMESTAMP(), NULL)',
            )->execute();
            $building[] = (int) $db->insertid();
        }

        return $building;
    }

    /**
     * Prepare generations now, one after another, each switched live when complete, within a budget of seconds; those
     * the budget leaves are continued by their price.set.prepare jobs from their checkpoint. The retired generations'
     * rows are removed within the same budget; the tidy job removes the rest.
     *
     * @param int[] $ids The generations being prepared.
     * @param float $seconds The budget.
     *
     * @return int Rows written.
     *
     * @since  2.2.2
     */
    private function prepareNow(array $ids, float $seconds): int
    {
        $until = microtime(true) + $seconds;
        $written = 0;

        foreach ($ids as $i => $id) {
            for ($round = 0; microtime(true) < $until; $round++) {
                $answer = $this->prepareContextChunkAnnounced(contextId: $id, limit: self::CHUNK_SIZE);
                $written += $answer['written'];

                if ($answer['state'] !== 'chunk') {
                    break;
                }
            }

            if ($this->contextWaits(contextId: $id)) {
                foreach (\array_slice($ids, $i) as $left) {
                    PriceSetPrepareJob::enqueueFor(contextId: (int) $left);
                }

                return $written;
            }
        }

        while (microtime(true) < $until && $this->removeRetired(limit: 5000) === 5000) {
            // The retired rows, in batches, while the budget lasts.
        }

        return $written;
    }

    /**
     * Rewrite a context-wide scope as generations: a rule or a tax whose scope is more than one chunk and at
     * least half the published products, so its rows switch whole in each context instead of a chunk at a time. Every
     * live context gets its next generation, prepared now within the budget and continued by the jobs.
     *
     * @return int Rows written.
     *
     * @since  2.2.2
     */
    private function rewriteAsGenerations(): int
    {
        $live = array_map('intval', $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $this->db->quoteName('is_live') . ' = 1'
            . ' ORDER BY ' . $this->db->quoteName('id'),
        )->loadColumn() ?: []);

        // With them, every first generation still being prepared (a set, a class or a customer new to the data): it is
        // started again, so the products its preparation passed are priced with the change too.
        $ids = array_values(array_unique(array_merge($this->regenerate(liveIds: $live), $this->restartPreparing(ids: null))));

        return $this->prepareNow(ids: $ids, seconds: self::GENERATION_SECONDS);
    }

    /**
     * Start again from their first product the generations being prepared (their checkpoint 0, their left-out list
     * cleared): a change that reaches them while they are prepared is then written into every product they hold.
     *
     * @param int[]|null $ids These generations only; null for every one being prepared.
     *
     * @return int[] The generations started again.
     *
     * @since  2.2.2
     */
    private function restartPreparing(?array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $db = $this->db;
        $where = $db->quoteName('prepared_at') . ' IS NULL AND ' . $db->quoteName('retired_at') . ' IS NULL'
            . ($ids === null ? '' : ' AND ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')');
        $restarted = array_map('intval', $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $where . ' ORDER BY ' . $db->quoteName('id'),
        )->loadColumn() ?: []);

        if ($restarted !== []) {
            $db->setQuery(
                'UPDATE ' . $db->quoteName(self::CONTEXTS_TABLE) . ' SET ' . $db->quoteName('prepared_through') . ' = 0, ' . $db->quoteName('left_out') . ' = NULL'
                . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $restarted) . ') AND ' . $db->quoteName('prepared_at') . ' IS NULL',
            )->execute();
        }

        return $restarted;
    }

    /**
     * Whether a rule's scope is context-wide: more than one chunk, and at least half the published products.
     *
     * @param int $scope The products in scope.
     *
     * @since  2.2.2
     */
    private function contextWide(int $scope): bool
    {
        return $scope > self::CHUNK_SIZE && $scope * 2 >= $this->publishedItemCount() && self::buildStartedAt() === null;
    }

    /**
     * What the tidy job has left: rows of retired generations and ended periods.
     *
     * @since  2.2.2
     */
    public function tidyCount(): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName(self::INDEX_TABLE) . ' AS i INNER JOIN ' . $db->quoteName(self::CONTEXTS_TABLE) . ' AS c ON c.id = i.context_id'
            . ' WHERE c.retired_at IS NOT NULL',
        )->loadResult() + (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName(self::INDEX_TABLE) . ' WHERE ' . $this->endedWhere(),
        )->loadResult();
    }

    /**
     * Remove retired generations: their rows a batch at a time, then the context row of one with none left.
     *
     * @param int $limit At most this many rows.
     *
     * @return int Rows removed.
     *
     * @since  2.2.2
     */
    public function removeRetired(int $limit): int
    {
        $db = $this->db;
        $removed = 0;

        foreach (array_map('intval', $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $db->quoteName('retired_at') . ' IS NOT NULL ORDER BY ' . $db->quoteName('id'),
        )->loadColumn() ?: []) as $id) {
            if ($removed >= $limit) {
                break;
            }

            $db->setQuery('DELETE FROM ' . $db->quoteName(self::INDEX_TABLE) . ' WHERE ' . $db->quoteName('context_id') . ' = ' . $id . ' LIMIT ' . max(1, $limit - $removed))->execute();
            $removed += (int) $db->getAffectedRows();

            if (!(bool) $db->setQuery('SELECT 1 FROM ' . $db->quoteName(self::INDEX_TABLE) . ' WHERE ' . $db->quoteName('context_id') . ' = ' . $id, 0, 1)->loadResult()) {
                $db->setQuery('DELETE FROM ' . $db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $db->quoteName('id') . ' = ' . $id . ' AND ' . $db->quoteName('retired_at') . ' IS NOT NULL')->execute();
            }
        }

        return $removed;
    }

    /**
     * Remove ended periods (the nightly pass): rows whose period ended more than a day ago and before the moment the
     * announcement of the periods that began has reached, which no reader and no announcement reads again.
     *
     * @param int $limit At most this many rows.
     *
     * @return int Rows removed.
     *
     * @since  2.2.2
     */
    public function removeEndedPeriods(int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName(self::INDEX_TABLE) . ' WHERE ' . $this->endedWhere() . ' LIMIT ' . $limit)->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * The WHERE of an ended period the tidy removes.
     *
     * @since  2.2.2
     */
    private function endedWhere(): string
    {
        $db = $this->db;
        $cursor = self::windowCursor(db: $db, lock: false);
        $before = $db->quote(gmdate('Y-m-d H:i:s', time() - 86400));

        if ($cursor !== null && strcmp($cursor['from'], gmdate('Y-m-d H:i:s', time() - 86400)) < 0) {
            $before = $db->quote($cursor['from']);
        }

        return $db->quoteName('valid_until') . ' IS NOT NULL AND ' . $db->quoteName('valid_until') . ' < ' . $before;
    }

    // Sets of pricing groups

    /**
     * A set of groups as it is kept: the ids ascending, comma-separated.
     *
     * @param int[] $groupIds The groups; the "any group" sentinel is no group.
     *
     * @return string '' for no group.
     *
     * @since  2.0.0
     */
    private static function groupSetList(array $groupIds): string
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $groupId): bool => $groupId > 0)));
        sort($groupIds);

        return implode(',', $groupIds);
    }

    /**
     * The key a set of groups is found by: the SHA-1 of its list.
     *
     * @param int[] $groupIds The groups.
     *
     * @return string 40 hex digits.
     *
     * @since  2.0.0
     */
    private static function groupSetKey(array $groupIds): string
    {
        return sha1(self::groupSetList(groupIds: $groupIds));
    }

    /**
     * The ids of a comma-separated list.
     *
     * @param string $list Ids, comma-separated; '' for none.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private static function groupsOfList(string $list): array
    {
        return $list === '' ? [] : array_map('intval', explode(',', $list));
    }

    /**
     * The sets of pricing groups customers really hold: every account's, the guest's and a newly registered customer's.
     *
     * @param int[]|null $pricing The groups the sets are made of; null for the pricing groups now.
     *
     * @return array<string, int[]> Key => its groups.
     *
     * @since  2.0.0
     */
    private function groupSetsInUse(?array $pricing = null): array
    {
        $pricing ??= self::pricingGroups();

        if ($pricing === []) {
            return [self::groupSetKey(groupIds: []) => []];
        }

        $groupSets = [];

        foreach ($this->accountGroupLists(pricing: $pricing) as $list) {
            $groups = self::groupsOfList(list: $list);
            $groupSets[self::groupSetKey(groupIds: $groups)] = $groups;
        }

        $users = ComponentHelper::getParams('com_users');
        $starting = [(int) $users->get('guest_usergroup', 1), (int) $users->get('new_usertype', 2)];
        $lineages = PriceContext::groupLineages(groupIds: $starting);

        foreach ($starting as $groupId) {
            $groups = array_values(array_intersect($lineages[$groupId] ?? [1], $pricing));
            sort($groups);
            $groupSets[self::groupSetKey(groupIds: $groups)] = $groups;
        }

        ksort($groupSets, SORT_STRING);

        return $groupSets;
    }

    /**
     * The sets of pricing groups accounts hold: every account's, or one's, read as Joomla reads an account's groups
     * (each group with every group above it; an account in no group holds the root), reduced to the pricing groups.
     *
     * @param int|null $userId One account; null for every account.
     * @param int[]|null $pricing The groups to reduce to; null for the pricing groups now.
     *
     * @return string[] Distinct sets, each as its list of ids.
     *
     * @since  2.0.0
     */
    private function accountGroupLists(?int $userId = null, ?array $pricing = null): array
    {
        $pricing ??= self::pricingGroups();

        if ($pricing === []) {
            return [''];
        }

        $db = $this->db;

        if (!self::$longLists) {
            $db->setQuery('SET SESSION group_concat_max_len = 1048576')->execute();
            self::$longLists = true;
        }

        // The pricing groups are picked inside the aggregate, not in the join's condition (MySQL 8.0 reads a join the
        // condition narrows to one listed id as a constant table).
        $pricingLine = 'CASE WHEN ' . $db->quoteName('line.id') . ' IN (' . implode(',', $pricing) . ')'
            . ' THEN ' . $db->quoteName('line.id') . ' END';
        $sql = 'SELECT DISTINCT COALESCE(' . $db->quoteName('held.group_set') . ', ' . $db->quote('') . ') FROM ('
            . 'SELECT GROUP_CONCAT(DISTINCT ' . $pricingLine . ' ORDER BY ' . $pricingLine
            . ' SEPARATOR ' . $db->quote(',') . ') AS ' . $db->quoteName('group_set')
            . ' FROM ' . $db->quoteName('#__users', 'u')
            . ' LEFT JOIN ' . $db->quoteName('#__user_usergroup_map', 'm')
            . ' ON ' . $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id')
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'g')
            . ' ON ' . $db->quoteName('g.id') . ' = COALESCE(' . $db->quoteName('m.group_id') . ', 1)'
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'line')
            . ' ON ' . $db->quoteName('line.lft') . ' <= ' . $db->quoteName('g.lft')
            . ' AND ' . $db->quoteName('line.rgt') . ' >= ' . $db->quoteName('g.rgt')
            . ($userId !== null ? ' WHERE ' . $db->quoteName('u.id') . ' = ' . (int) $userId : '')
            . ' GROUP BY ' . $db->quoteName('u.id') . ') AS ' . $db->quoteName('held');

        return array_map('strval', $db->setQuery($sql)->loadColumn());
    }

    /**
     * The set of pricing groups each of some accounts holds, as accountGroupLists() reads them, in one statement.
     *
     * @param int[] $userIds The accounts; those that do not exist are absent.
     *
     * @return array<int, string> The set's list of ids by user id.
     *
     * @since  2.2.2
     */
    private function accountGroupListsOf(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        $pricing = self::pricingGroups();

        if ($userIds === []) {
            return [];
        }

        $db = $this->db;

        if ($pricing === []) {
            return array_fill_keys(array_map('intval', $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__users') . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $userIds) . ')',
            )->loadColumn() ?: []), '');
        }

        if (!self::$longLists) {
            $db->setQuery('SET SESSION group_concat_max_len = 1048576')->execute();
            self::$longLists = true;
        }

        $pricingLine = 'CASE WHEN ' . $db->quoteName('line.id') . ' IN (' . implode(',', $pricing) . ') THEN ' . $db->quoteName('line.id') . ' END';
        $out = [];

        foreach ($db->setQuery(
            'SELECT ' . $db->quoteName('u.id') . ', GROUP_CONCAT(DISTINCT ' . $pricingLine . ' ORDER BY ' . $pricingLine . ' SEPARATOR ' . $db->quote(',') . ') AS ' . $db->quoteName('group_set')
            . ' FROM ' . $db->quoteName('#__users', 'u')
            . ' LEFT JOIN ' . $db->quoteName('#__user_usergroup_map', 'm') . ' ON ' . $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id')
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'g') . ' ON ' . $db->quoteName('g.id') . ' = COALESCE(' . $db->quoteName('m.group_id') . ', 1)'
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'line') . ' ON ' . $db->quoteName('line.lft') . ' <= ' . $db->quoteName('g.lft')
            . ' AND ' . $db->quoteName('line.rgt') . ' >= ' . $db->quoteName('g.rgt')
            . ' WHERE ' . $db->quoteName('u.id') . ' IN (' . implode(',', $userIds) . ')'
            . ' GROUP BY ' . $db->quoteName('u.id'),
        )->loadObjectList() ?: [] as $row) {
            $out[(int) $row->id] = (string) ($row->group_set ?? '');
        }

        return $out;
    }

    /**
     * Bring the sets the index knows in line with the sets customers hold: record each set nobody held before, drop each
     * set nobody holds any more with its contexts and rows. The groups that can change a price are read again first.
     *
     * A change of those groups while contexts wait keeps the sets of the groups before it: a set nobody holds any more
     * that customers read as their previous one stays, with its contexts, written as the held sets are, until no
     * context waits (META_GROUP_SETS).
     *
     * @return array{0: int, 1: array<int, int[]>} Sets recorded; every set kept now, by id, with its groups.
     *
     * @since  2.0.0
     */
    private function alignGroupSets(): array
    {
        self::$pricingGroups = null;
        self::$guestLine = null;
        self::$previousRead = false;
        $pricing = self::groupSetList(groupIds: self::pricingGroups());
        $held = $this->groupSetsInUse();
        $known = $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'group_list', 'digest'])) . ' FROM ' . $this->db->quoteName(self::SETS_TABLE),
        )->loadObjectList('id') ?: [];
        $facts = self::groupSetFacts(db: $this->db);
        $waits = (bool) $this->db->setQuery(
            'SELECT 1 FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE) . ' WHERE ' . $this->db->quoteName('prepared_at') . ' IS NULL',
            0,
            1,
        )->loadResult();
        $unheld = [];
        $knownKeys = [];

        foreach ($known as $setId => $set) {
            $knownKeys[(string) $set->digest] = true;

            if (!isset($held[(string) $set->digest])) {
                $unheld[] = (int) $setId;
            }
        }

        $newSets = array_diff_key($held, $knownKeys);
        $waits = $waits || $newSets !== [];
        $previous = $facts['previous'] ?? ($facts['pricing'] !== null && $facts['pricing'] !== $pricing ? $facts['pricing'] : null);
        $previous = $waits ? $previous : null;
        $kept = [];

        if ($previous !== null && $unheld !== []) {
            $readBefore = $this->groupSetsInUse(pricing: self::groupsOfList(list: $previous));

            foreach ($unheld as $setId) {
                if (isset($readBefore[(string) $known[$setId]->digest])) {
                    $kept[$setId] = true;
                }
            }
        }

        $this->dropGroupSets(setIds: array_values(array_diff($unheld, array_keys($kept))));
        $requested = $this->requestGroupSets(groupSets: $newSets);
        $this->recordGroupSetFacts(stored: $facts['stored'], pricing: $pricing, previous: $previous);

        if ($requested > 0 || $unheld !== []) {
            $known = $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['id', 'group_list', 'digest'])) . ' FROM ' . $this->db->quoteName(self::SETS_TABLE),
            )->loadObjectList('id') ?: [];
        }

        $sets = [];

        foreach ($known as $setId => $set) {
            if (isset($held[(string) $set->digest]) || isset($kept[(int) $setId])) {
                $sets[(int) $setId] = self::groupsOfList(list: (string) $set->group_list);
            }
        }

        return [$requested, $sets];
    }

    /**
     * Store the fact of the sets of pricing groups when it changes; the row is added the first time.
     *
     * @param string|null $stored The value stored now; null when the row is missing.
     * @param string $pricing The pricing groups the sets are now in line with.
     * @param string|null $previous The pricing groups before a change that waits; null for none.
     *
     * @since  2.2.2
     */
    private function recordGroupSetFacts(?string $stored, string $pricing, ?string $previous): void
    {
        $value = json_encode(['pricing' => $pricing, 'previous' => $previous], JSON_THROW_ON_ERROR);

        if ($value === $stored) {
            return;
        }

        $again = $value;
        $name = self::META_GROUP_SETS;
        $query = $this->db->getQuery(true)->setQuery(
            'INSERT INTO ' . $this->db->quoteName('#__alfa_meta') . ' (' . $this->db->quoteName('name') . ', ' . $this->db->quoteName('value') . ') VALUES (:name, :value)'
            . ' ON DUPLICATE KEY UPDATE ' . $this->db->quoteName('value') . ' = :again',
        )
            ->bind(':name', $name)
            ->bind(':value', $value)
            ->bind(':again', $again);
        $this->db->setQuery($query)->execute();
    }

    /**
     * Record sets of pricing groups customers hold for the first time: their contexts are requested by the alignment
     * that follows.
     *
     * @param array<string, int[]> $groupSets Key => groups; none, no statement.
     *
     * @return int The sets recorded.
     *
     * @since  2.0.0
     */
    private function requestGroupSets(array $groupSets): int
    {
        if ($groupSets === []) {
            return 0;
        }

        $rows = [];

        foreach ($groupSets as $key => $groups) {
            $rows[] = '(' . $this->db->quote(self::groupSetList(groupIds: $groups)) . ', ' . $this->db->quote((string) $key) . ')';
        }

        $this->db->setQuery(
            'INSERT IGNORE INTO ' . $this->db->quoteName(self::SETS_TABLE)
            . ' (' . implode(', ', $this->db->quoteName(['group_list', 'digest'])) . ') VALUES ' . implode(', ', $rows),
        )->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Remove some sets of pricing groups, with their contexts and every row of theirs.
     *
     * @param int[] $setIds The sets; none, no statement.
     *
     * @since  2.0.0
     */
    private function dropGroupSets(array $setIds): void
    {
        $setIds = array_values(array_unique(array_map('intval', $setIds)));

        if ($setIds === []) {
            return;
        }

        $list = implode(',', $setIds);
        $this->db->transactionStart(true);

        try {
            $contexts = array_map('intval', $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName(self::CONTEXTS_TABLE)
                . ' WHERE ' . $this->db->quoteName('group_set_id') . ' IN (' . $list . ')',
            )->loadColumn() ?: []);
            $this->dropContexts(ids: $contexts);
            $this->db->setQuery(
                'DELETE FROM ' . $this->db->quoteName(self::SETS_TABLE) . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $list . ')',
            )->execute();
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $this->db, failure: $e);

            throw $e;
        }
    }

    // Products

    /**
     * The next chunk of published products after a checkpoint.
     *
     * @param int $after The last product already written.
     * @param int $limit At most this many.
     *
     * @return int[] Ascending.
     *
     * @since  2.0.0
     */
    private function publishedItemIdsAfter(int $after, int $limit = self::CHUNK_SIZE): array
    {
        return array_map('intval', $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__alfa_items'))
                ->where($this->db->quoteName('state') . ' = 1')
                ->where($this->db->quoteName('id') . ' > ' . $after)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(max(1, $limit)),
        )->loadColumn());
    }

    /**
     * The published products among some, read now: the writer prices these only.
     *
     * @param int[] $itemIds The products.
     *
     * @return int[] In the order given.
     *
     * @since  2.2.1
     */
    private function publishedOf(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $published = array_map('intval', $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__alfa_items'))
                ->whereIn($this->db->quoteName('id'), array_map('intval', $itemIds))
                ->where($this->db->quoteName('state') . ' = 1'),
        )->loadColumn() ?: []);

        return array_values(array_intersect(array_map('intval', $itemIds), $published));
    }

    /**
     * The units of each product: 0, the product itself, first; then its published combinations that have price rows of
     * their own, in the order the storefront lists them. A combination without its own price rows has no unit: the
     * product's row is its price.
     *
     * @param int[] $itemIds The products.
     *
     * @return array<int, int[]> Combination ids by product id, every id given.
     *
     * @since  2.2.2
     */
    private function unitsOf(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $units = array_fill_keys(array_map('intval', $itemIds), [0]);

        foreach ($this->db->setQuery(
            'SELECT oc.id, oc.item_id FROM ' . $this->db->quoteName('#__alfa_item_combinations') . ' AS oc'
            . ' WHERE oc.item_id IN (' . implode(',', array_map('intval', $itemIds)) . ') AND oc.state = 1 AND ' . self::ownPriceSql(combinationAlias: 'oc')
            . ' ORDER BY oc.item_id ASC, oc.ordering ASC, oc.id ASC',
        )->loadObjectList() ?: [] as $row) {
            $units[(int) $row->item_id][] = (int) $row->id;
        }

        return $units;
    }

    /**
     * Whether a combination has price rows of its own (a published price or adjust row): the condition of its own row in
     * the index and of its being the product's price combination.
     *
     * @param string $combinationAlias The alias of #__alfa_item_combinations in the caller's statement.
     *
     * @return string An SQL condition.
     *
     * @since  2.2.2
     */
    public static function ownPriceSql(string $combinationAlias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_items_prices') . ' AS own WHERE own.combination_id = ' . $combinationAlias . '.id'
            . ' AND own.item_id = ' . $combinationAlias . '.item_id AND own.state = 1)';
    }

    /**
     * The published units as a derived table (item_id, combination_id): every published product with 0, and each
     * published combination of one that has price rows of its own.
     *
     * @param int[]|null $itemIds Only these products; null for every one.
     *
     * @return string A parenthesised SELECT.
     *
     * @since  2.2.2
     */
    private function unitsSql(?array $itemIds = null): string
    {
        $in = $itemIds === null ? '' : ' AND a.id IN (' . implode(',', array_map('intval', $itemIds)) . ')';
        $items = $this->db->quoteName('#__alfa_items');

        return '(SELECT a.id AS item_id, 0 AS combination_id FROM ' . $items . ' AS a WHERE a.state = 1' . $in
            . ' UNION ALL SELECT oc.item_id, oc.id FROM ' . $this->db->quoteName('#__alfa_item_combinations') . ' AS oc'
            . ' INNER JOIN ' . $items . ' AS a ON a.id = oc.item_id AND a.state = 1'
            . ' WHERE oc.state = 1 AND ' . self::ownPriceSql(combinationAlias: 'oc') . $in . ')';
    }

    /**
     * The index's size: its rows, and its data and keys in bytes as the database reports them (Tools' check).
     *
     * @return array{rows: int, bytes: int}
     *
     * @since  2.2.2
     */
    public static function size(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return [
            'rows' => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::INDEX_TABLE))->loadResult(),
            'bytes' => (int) $db->setQuery(
                'SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
                . $db->quote($db->replacePrefix(self::INDEX_TABLE)),
            )->loadResult(),
        ];
    }

    /**
     * Refresh the statistics of the index and its contexts after a full rebuild: a table rewritten whole is read by its
     * primary key only once the engine's statistics have caught up with its rows. Never inside a transaction (the
     * statement commits one); a failure is logged and changes nothing else.
     *
     * @since  2.2.2
     */
    public static function analyze(): void
    {
        $db = TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));

        if (TransactionState::isOpen(db: $db)) {
            return;
        }

        try {
            $db->setQuery('ANALYZE TABLE ' . $db->quoteName(self::INDEX_TABLE) . ', ' . $db->quoteName(self::CONTEXTS_TABLE))->loadAssocList();
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add('[PriceIndexSyncService] The price index statistics could not be refreshed: ' . $e->getMessage(), Log::WARNING, 'com_alfa');
        }
    }

    /**
     * The quantity each product opens with: its minimum, as a listing card prices it.
     *
     * @param int[] $itemIds The products.
     *
     * @return array<int, int> By product id, every id given.
     *
     * @since  2.2.2
     */
    private function openingQuantities(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName(['id', 'quantity_min']))
                ->from($this->db->quoteName('#__alfa_items'))
                ->whereIn($this->db->quoteName('id'), array_map('intval', $itemIds)),
        )->loadObjectList('id') ?: [];
        $intent = PricingIntent::catalog();
        $opening = [];

        foreach ($itemIds as $itemId) {
            $opening[(int) $itemId] = $intent->getQuantityForItem($rows[(int) $itemId] ?? (object) ['id' => (int) $itemId]);
        }

        return $opening;
    }

    /**
     * All published product ids.
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getAllPublishedItemIds(): array
    {
        return array_map('intval', $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__alfa_items'))
                ->where($this->db->quoteName('state') . ' = 1'),
        )->loadColumn());
    }

    /**
     * The products of any of some categories.
     *
     * @param int[] $categoryIds The categories.
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getItemIdsInCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return array_map('intval', $this->db->setQuery(
            $this->db->getQuery(true)
                ->select('DISTINCT ' . $this->db->quoteName('item_id'))
                ->from($this->db->quoteName('#__alfa_items_categories'))
                ->whereIn($this->db->quoteName('category_id'), $categoryIds),
        )->loadColumn());
    }

    // Rule scopes

    /**
     * Whether a discount applies to every product: its category_id 0 sentinel, or no category rows at all, as the loader
     * reads it.
     *
     * @since  1.0.0
     */
    private function discountAppliesGlobally(int $discountId): bool
    {
        return $this->appliesGlobally(table: '#__alfa_discount_categories', key: 'discount_id', id: $discountId);
    }

    /**
     * The categories of a discount, without the 0 sentinel.
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getDiscountCategoryIds(int $discountId): array
    {
        return $this->scopeCategories(table: '#__alfa_discount_categories', key: 'discount_id', id: $discountId);
    }

    /**
     * Whether a tax applies to every product, as the loader reads it.
     *
     * @since  1.0.0
     */
    private function taxAppliesGlobally(int $taxId): bool
    {
        return $this->appliesGlobally(table: '#__alfa_tax_categories', key: 'tax_id', id: $taxId);
    }

    /**
     * The categories of a tax, without the 0 sentinel.
     *
     * @return int[]
     * @since  1.0.0
     */
    private function getTaxCategoryIds(int $taxId): array
    {
        return $this->scopeCategories(table: '#__alfa_tax_categories', key: 'tax_id', id: $taxId);
    }

    /**
     * Whether a rule's category scope is every product: the 0 sentinel, or no row at all.
     *
     * @param string $table The rule's category table.
     * @param string $key Its rule column.
     * @param int $id The rule.
     *
     * @since  2.2.2
     */
    private function appliesGlobally(string $table, string $key, int $id): bool
    {
        $row = $this->db->setQuery(
            $this->db->getQuery(true)
                ->select([
                    'SUM(CASE WHEN ' . $this->db->quoteName('category_id') . ' = 0 THEN 1 ELSE 0 END) AS sentinel_count',
                    'COUNT(*) AS total_count',
                ])
                ->from($this->db->quoteName($table))
                ->where($this->db->quoteName($key) . ' = ' . $id),
        )->loadObject();

        return $row !== null && ((int) $row->sentinel_count > 0 || (int) $row->total_count === 0);
    }

    /**
     * A rule's categories, without the 0 sentinel.
     *
     * @param string $table The rule's category table.
     * @param string $key Its rule column.
     * @param int $id The rule.
     *
     * @return int[]
     *
     * @since  2.2.2
     */
    private function scopeCategories(string $table, string $key, int $id): array
    {
        return array_map('intval', $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('category_id'))
                ->from($this->db->quoteName($table))
                ->where($this->db->quoteName($key) . ' = ' . $id)
                ->where($this->db->quoteName('category_id') . ' > 0'),
        )->loadColumn());
    }

    // Logging

    /**
     * Log a non-fatal warning to the com_alfa log.
     *
     * @since  1.0.0
     */
    private function logWarning(string $message): void
    {
        Log::add('[PriceIndexSyncService] ' . $message, Log::WARNING, 'com_alfa');
    }
}
