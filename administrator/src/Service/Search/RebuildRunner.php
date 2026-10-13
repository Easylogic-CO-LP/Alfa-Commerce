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

use RuntimeException;

/**
 * Rebuilds an index, once, for every engine.
 *
 * INVARIANT PROTECTED: rebuild behaviour can never diverge between engines. This
 * algorithm (stream, chunk, write, checkpoint, await, activate) lives in core
 * exactly once. An engine supplies primitives and nothing more, so there is no place
 * for a plugin to invent its own chunk size, its own retry rule or its own idea of
 * when an index is safe to serve.
 *
 * It is deliberately UNINTELLIGENT. It knows nothing of SQL, mappings, analyzers,
 * facets or any engine's naming. It moves documents from a {@see DocumentSourceInterface}
 * to a {@see SearchIndexerInterface} and understands neither end. Every temptation
 * to teach it something about one side is a step back toward per-engine rebuilds.
 *
 * Resumable by construction: each call takes and returns a {@see RebuildProgress}
 * that a caller can persist between HTTP requests, so a rebuild survives timeouts
 * without restarting and without writing into the live generation.
 *
 * @since  2.0.0
 */
final class RebuildRunner
{
    /**
     * @param DocumentSourceInterface $source Supplies records and documents.
     * @param SearchIndexerInterface $indexer Accepts writes.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly DocumentSourceInterface $source,
        private readonly SearchIndexerInterface $indexer,
    ) {
    }

    /**
     * Open a generation and return the initial checkpoint. Nothing is written yet
     * and the serving index is untouched.
     *
     *
     * @since  2.0.0
     */
    public function start(): RebuildProgress
    {
        // Note which generation is serving BEFORE opening a new one. After the swap
        // the engine cannot tell the generation this rebuild replaced from any older
        // one, so if it is not captured here it can never be swept, and superseded
        // generations then accumulate in storage forever.
        $serving = '';

        foreach ($this->indexer->writeTargets() as $target) {
            if ($target->active) {
                $serving = $target->handle;

                break;
            }
        }

        return new RebuildProgress(
            generation: $this->indexer->createGeneration()->handle,
            total:      $this->source->countRecords(),
            supersedes: $serving,
        );
    }

    /**
     * Consume one chunk of the document stream and return the advanced checkpoint.
     *
     * Reads from the source's generator, so no more than one chunk of documents is
     * ever in memory regardless of catalogue size, and stops on a RECORD boundary:
     * a record's documents in every language are written together or not at all,
     * which is what makes the returned cursor safe to persist and resume from.
     *
     * A record that yields no documents has its documents DELETED rather than
     * skipped: that is how an unpublished product leaves a rebuilt index instead of
     * lingering in it.
     *
     * The chunk size is THE ENGINE'S, never the caller's. The interface docblock has
     * always said the engine knows its own payload limits and core must not guess
     * them, yet a parameter existed for overriding it, and every caller used it to
     * impose a core constant. The parameter is gone; the temptation with it.
     *
     * @param RebuildProgress $progress The checkpoint to advance.
     *
     *
     * @since  2.0.0
     */
    public function step(RebuildProgress $progress): RebuildProgress
    {
        if ($progress->enumerated) {
            return $progress;
        }

        $generation = new IndexGeneration($progress->generation);
        $limit = max(1, $this->indexer->preferredBatchSize());
        $languages = $this->source->languages();

        $documents = [];
        $obsolete = [];
        $cursor = $progress->cursor;
        $records = 0;

        // Lazy: the generator only touches the database for the records consumed here.
        foreach ($this->source->documents($progress->cursor) as $recordId => $recordDocuments) {
            if ($recordDocuments === []) {
                foreach ($languages as $language) {
                    $obsolete[] = DocumentId::forItem(itemId: (int) $recordId, language: $language);
                }
            } else {
                foreach ($recordDocuments as $document) {
                    $documents[] = $document;
                }
            }

            $cursor = (int) $recordId;
            $records++;

            if ($records >= $limit) {
                break;
            }
        }

        $failures = $progress->failures;
        $written = $progress->written;
        $removed = $progress->removed;

        if ($documents !== []) {
            $result = $this->indexer->write($generation, $documents);
            $written += $result->written;
            $failures = array_merge($failures, $result->failures);
        }

        if ($obsolete !== []) {
            $result = $this->indexer->delete($generation, $obsolete);
            $removed += $result->written;
            $failures = array_merge($failures, $result->failures);
        }

        return new RebuildProgress(
            generation: $progress->generation,
            cursor:     $cursor,
            processed:  $progress->processed + $records,
            total:      $progress->total,
            written:    $written,
            removed:    $removed,
            failures:   $failures,
            // The stream ran dry before the chunk filled: every record has been walked.
            enumerated: $records < $limit,
            activated:  $progress->activated,
            // Carried, not recomputed. It was captured at start() precisely because it
            // is unknowable later; dropping it here would silently disable the sweep.
            supersedes: $progress->supersedes,
        );
    }

    /**
     * Wait for durability, then promote the generation.
     *
     * Refuses to activate an incomplete rebuild. Serving a generation with known
     * missing documents is worse than serving the previous one: the old index is
     * merely stale, whereas a half-filled new one silently hides products, and the
     * merchant has no signal that anything happened.
     *
     * @param RebuildProgress $progress The checkpoint to finish.
     * @param float $timeoutSeconds How long to wait for the engine to settle.
     * @param int[] $reconcile Records written by the LIVE path while this
     *                         rebuild was walking. Re-read and rewritten
     *                         before activation, because the walk may have
     *                         overwritten them with an older snapshot.
     *
     *
     * @throws RuntimeException When the rebuild is incomplete, reconciliation failed, or
     *                          the engine never settled.
     *
     * @since  2.0.0
     */
    public function finish(RebuildProgress $progress, float $timeoutSeconds = 30.0, array $reconcile = []): RebuildProgress
    {
        if (!$progress->enumerated) {
            throw new RuntimeException('Refusing to activate: the rebuild has not finished enumerating.');
        }

        if ($progress->failures !== []) {
            throw new RuntimeException(
                sprintf(
                    'Refusing to activate: %d document(s) were rejected by the engine.',
                    \count($progress->failures),
                ),
            );
        }

        $generation = new IndexGeneration($progress->generation);

        // RECONCILE BEFORE ACTIVATING. Documents are built a batch at a time and
        // written afterwards, so a record saved inside that window is written
        // correctly by the live path and then overwritten by the batch's older
        // snapshot. The engine's hash guard does not catch it (the two documents
        // genuinely differ, so the stale one wins) and after activation the record
        // answers to its old wording permanently, with the rebuild reporting success.
        // Re-reading exactly the records that were written live repairs that, and
        // costs nothing on the overwhelmingly common rebuild where nothing was saved.
        $reconciled = 0;

        foreach ($this->source->documentsFor($reconcile) as $documents) {
            if ($documents === []) {
                continue;
            }

            $result = $this->indexer->write($generation, $documents);
            $reconciled += $result->written;

            if ($result->failures !== []) {
                throw new RuntimeException(
                    sprintf(
                        'Refusing to activate: reconciliation could not rewrite %d document(s).',
                        \count($result->failures),
                    ),
                );
            }
        }

        // Waited for AFTER reconciliation, never before: reconciliation writes, and an
        // engine that acknowledges before applying would otherwise be asked to settle
        // for work that had not yet been handed to it.
        $settled = $this->indexer->await($generation, $timeoutSeconds);

        // THE SECOND GATE, and the one that protects asynchronous engines. A batch
        // accepted with 202 and rejected minutes later is invisible to write(); it
        // surfaces only here. Checking failures once, before awaiting, would leave the
        // whole refusal rule inert for exactly the engines it exists to protect.
        if ($settled->failures !== []) {
            throw new RuntimeException(
                sprintf(
                    'Refusing to activate: the engine rejected %d document(s) after acknowledging them.',
                    \count($settled->failures),
                ),
            );
        }

        $this->indexer->activateGeneration($generation);

        // Sweep only AFTER a successful activation, and never let a failed sweep undo
        // one: the new index is already serving, so leftover storage is a cost, while
        // throwing here would report a rebuild that actually succeeded as failed.
        if ($progress->supersedes !== '' && $progress->supersedes !== $progress->generation) {
            try {
                $this->indexer->dropGeneration(new IndexGeneration($progress->supersedes));
            } catch (RuntimeException) {
                // Already gone, or the engine declines to drop it.
            }
        }

        return new RebuildProgress(
            generation: $progress->generation,
            cursor:     $progress->cursor,
            processed:  $progress->processed,
            total:      $progress->total,
            written:    $progress->written + $reconciled,
            removed:    $progress->removed,
            failures:   $progress->failures,
            enumerated: true,
            activated:  true,
            supersedes: $progress->supersedes,
        );
    }

    /**
     * Discard an abandoned generation.
     *
     * Separate from finish() on purpose: a failed rebuild should leave its evidence
     * in place until someone has looked at it, so cleanup is always an explicit act.
     *
     * @param RebuildProgress $progress The checkpoint whose generation is being abandoned.
     *
     *
     * @since  2.0.0
     */
    public function discard(RebuildProgress $progress): void
    {
        if ($progress->activated) {
            throw new RuntimeException('Refusing to discard the generation that is serving reads.');
        }

        $this->indexer->dropGeneration(new IndexGeneration($progress->generation));
    }
}
