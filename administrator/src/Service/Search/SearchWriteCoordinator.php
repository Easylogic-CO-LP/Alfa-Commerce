<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Throwable;

/**
 * The incremental write path: everything a product save must tell the search engine.
 *
 * INVARIANT PROTECTED: an edit made while a rebuild is running can never be lost.
 * A rebuild fills a NEW generation while the old one still serves, so a save that
 * writes only to the live generation vanishes the moment the rebuild activates:
 * no error, no failed row, just a product whose index entry has quietly reverted.
 * The window is exactly as long as a rebuild, which on a large catalogue is exactly
 * when edits are most likely.
 *
 * The fix is to write to every generation currently accepting writes, and the point
 * of this class is that NOTHING ABOVE IT HAS TO KNOW THAT. The projection says
 * "index these documents"; which generations exist, whether a rebuild is running,
 * and how an engine models generations at all stay behind the contract:
 *
 *     ProjectionManager -> ItemSearchProjection -> SearchWriteCoordinator
 *                                                      v
 *                                          engine->indexer()->writeTargets()
 *                                                      v
 *                                          indexer->write(generation, documents)
 *
 * There is deliberately no `if ($engine->key() === ...)` anywhere in this path. The
 * day one appears, engines have stopped being interchangeable.
 *
 * @since  2.0.0
 */
final class SearchWriteCoordinator
{
    /**
     * @since  2.0.0
     */
    private readonly SearchEngineRegistry $registry;

    /**
     * Resolved indexer for this request, or false before first resolution.
     *
     * @since  2.0.0
     */
    private SearchIndexerInterface|null|false $indexer = false;

    /**
     * @param SearchEngineRegistry|null $registry Injected for testing.
     *
     * @since  2.0.0
     */
    public function __construct(?SearchEngineRegistry $registry = null)
    {
        $this->registry = $registry ?? new SearchEngineRegistry();
    }

    /**
     * Index documents into every generation that currently accepts writes.
     *
     * @param SearchDocument[] $documents Documents to write.
     *
     * @return IndexWriteResult Aggregate across all targets.
     *
     * @since  2.0.0
     */
    public function write(array $documents): IndexWriteResult
    {
        if ($documents === []) {
            return new IndexWriteResult();
        }

        $result = $this->fanOut(
            static fn (SearchIndexerInterface $indexer, IndexGeneration $generation): IndexWriteResult
                => $indexer->write($generation, $documents),
        );

        $this->noteForReconciliation($documents);

        return $result;
    }

    /**
     * Tell any rebuild in flight that these records were just written live.
     *
     * Dual-writing into the generation being built is necessary but NOT sufficient:
     * the rebuild reads a batch of records, builds their documents, and writes the
     * batch afterwards. A save landing inside that window writes the correct document
     * and is then overwritten by the batch's older snapshot, and the engine's hash
     * guard cannot help, because the two documents genuinely differ, so the stale one
     * wins. Recording the id here lets activation re-read exactly these records.
     *
     * The write path does not know or care WHY the id is being kept. Today it feeds
     * reconciliation; the same record would serve an incremental catch-up or a repair
     * pass without this method changing.
     *
     * Never fatal and silent when nothing is rebuilding, which is almost always.
     *
     * @param SearchDocument[] $documents Documents just written.
     *
     *
     * @since  2.0.0
     */
    private function noteForReconciliation(array $documents): void
    {
        $this->noteIds(array_map(static fn ($document) => $document->itemId(), $documents));
    }

    /**
     * Record touched item ids for pre-activation reconciliation.
     *
     * Keyed by the CONFIGURED engine (the same identity the rebuild checkpoint uses),
     * so edits made while the engine's very FIRST build is walking are recorded even
     * though nothing is active yet. Keying by active() silently dropped exactly those.
     *
     * @param int[] $itemIds Items written or deleted.
     *
     *
     * @since  2.0.0
     */
    private function noteIds(array $itemIds): void
    {
        try {
            $engine = $this->registry->configured();

            if ($engine === null || $itemIds === []) {
                return;
            }

            (new RebuildStore())->noteWritten($engine->key(), $itemIds);
        } catch (Throwable $e) {
            // Bookkeeping must never fail a catalogue save.
        }
    }

    /**
     * Remove documents from every generation that currently accepts writes.
     *
     * Deletion fans out for the same reason a write does: an item deleted mid-rebuild
     * that is removed only from the live generation reappears when the rebuild that
     * still contains it is activated.
     *
     * @param DocumentId[] $ids Documents to remove.
     *
     *
     * @since  2.0.0
     */
    public function delete(array $ids): IndexWriteResult
    {
        if ($ids === []) {
            return new IndexWriteResult();
        }

        $result = $this->fanOut(
            static fn (SearchIndexerInterface $indexer, IndexGeneration $generation): IndexWriteResult
                => $indexer->delete($generation, $ids),
        );

        // A deletion during a rebuild is a live edit like any other: the walk may hold
        // this record in memory and write its OLD documents after this delete,
        // resurrecting an unpublished product in the new generation. Recording the ids
        // makes pre-activation reconciliation re-project them, and the builder
        // answering null then removes them from the generation for good.
        $this->noteIds(array_map(static fn ($id) => $id->itemId(), $ids));

        return $result;
    }

    /**
     * Apply an operation to every write target and aggregate the outcomes.
     *
     * A failure against ONE generation is recorded, not thrown: the pending
     * generation of a half-finished rebuild must never be able to block a merchant
     * from saving a product.
     *
     * @param callable(SearchIndexerInterface, IndexGeneration): IndexWriteResult $operation What to do per generation.
     *
     *
     * @since  2.0.0
     */
    private function fanOut(callable $operation): IndexWriteResult
    {
        $indexer = $this->indexer();

        // No engine is a FAILURE, not a quiet success. Returning an empty result
        // would make every save report "indexed" while indexing nothing, and there is
        // no error, no failed row and no admin signal to contradict it: the search
        // index would simply stop tracking the catalogue. The caller logs failures,
        // so saying so here is what makes the condition visible at all.
        if ($indexer === null) {
            return new IndexWriteResult(
                failures: ['engine' => 'No search engine is available; nothing was indexed.'],
            );
        }

        $targets = $indexer->writeTargets();

        // Likewise an engine with nowhere to write: healthy engines always report at
        // least their active generation.
        if ($targets === []) {
            return new IndexWriteResult(
                failures: ['engine' => 'The search engine reported no writable generation; nothing was indexed.'],
            );
        }

        $written = 0;
        $failures = [];

        foreach ($targets as $generation) {
            try {
                $result = $operation($indexer, $generation);
                $written += $result->written;

                foreach ($result->failures as $key => $reason) {
                    $failures[$key . '@' . $generation->handle] = $reason;
                }
            } catch (Throwable $e) {
                $failures['generation:' . $generation->handle] = $e->getMessage();
            }
        }

        return new IndexWriteResult(written: $written, failures: $failures);
    }

    /**
     * The active engine's indexer, resolved once per request.
     *
     * @return SearchIndexerInterface|null Null when no engine is installed or ready.
     *
     * @since  2.0.0
     */
    private function indexer(): ?SearchIndexerInterface
    {
        if ($this->indexer !== false) {
            return $this->indexer;
        }

        // CONFIGURED, not active. Readiness gates READS (a not-ready engine must not
        // answer customers), but it must never gate WRITES, or every product saved
        // during an engine's first build vanishes from the index that build produces.
        // An engine with nothing to write into simply returns no writeTargets.
        $engine = $this->registry->configured();

        return $this->indexer = $engine?->indexer();
    }
}
