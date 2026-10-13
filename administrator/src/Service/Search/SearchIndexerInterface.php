<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

use RuntimeException;

defined('_JEXEC') or die;

/**
 * The write half of a search engine: how documents get INTO it.
 *
 * Deliberately PRIMITIVES ONLY. There is no `rebuild()` here, and that omission is
 * the most important decision in this interface.
 *
 * INVARIANT PROTECTED: rebuild logic can never fork per engine. Publishing
 * `rebuild()` would mean every engine plugin re-implements enumeration, chunking,
 * checkpointing, retry, progress reporting and activation, and within a year
 * there would be four subtly different rebuilds, three of which lose documents on
 * a timeout and none of which can be reasoned about together. Core owns that
 * algorithm; an engine only has to know how to write, swap and forget.
 *
 * Batch-shaped throughout: a per-document interface cannot be widened to batch
 * later without breaking every implementor, and every engine on the roster has a
 * bulk endpoint precisely because per-document round trips do not scale.
 *
 * @since  2.0.0
 */
interface SearchIndexerInterface
{
    /**
     * Open a fresh generation to write into, leaving the serving one untouched.
     *
     * An engine with no notion of side-by-side generations may return the currently
     * active one, which degrades a rebuild to writing in place: correct, merely not
     * atomic.
     *
     *
     * @since  2.0.0
     */
    public function createGeneration(): IndexGeneration;

    /**
     * The generations an INCREMENTAL write must reach: the one serving reads, plus
     * every generation currently being built.
     *
     * This is the dual-write primitive, and without it the incremental path is
     * silently lossy. A product saved while a rebuild is in flight would be written
     * only to the active generation, and the moment that rebuild activates, the edit
     * disappears: no error, no failed row, just a product whose index entry has
     * quietly reverted. The window is exactly as long as a rebuild, which on a large
     * catalogue is exactly when edits are most likely.
     *
     * Core asks rather than infers, because "what is being built" is engine
     * knowledge: a MySQL engine reads its generations table, a hosted engine looks at
     * the indices behind an alias, an engine without generations returns just the active
     * one and dual-write degrades to a single write.
     *
     * @return IndexGeneration[] Never empty on a healthy engine; the active generation at minimum.
     *
     * @since  2.0.0
     */
    public function writeTargets(): array;

    /**
     * Write documents into a generation, replacing any existing document with the
     * same {@see DocumentId}.
     *
     * Whole-document replacement only. Partial update is an optimisation an engine may
     * make internally, never a required verb: the builder always emits complete
     * documents, so replacement is always correct.
     *
     * @param IndexGeneration $generation Target generation.
     * @param SearchDocument[] $documents Documents to write.
     *
     * @return IndexWriteResult Per-document outcome, so the caller retries precisely.
     *
     * @since  2.0.0
     */
    public function write(IndexGeneration $generation, array $documents): IndexWriteResult;

    /**
     * Remove documents by identity.
     *
     * By exact key and never by query: delete-by-query is the weakest and most
     * conditional primitive across the engine roster, while every one of them
     * deletes by id in batches.
     *
     * @param IndexGeneration $generation Target generation.
     * @param DocumentId[] $ids Documents to remove.
     *
     *
     * @since  2.0.0
     */
    public function delete(IndexGeneration $generation, array $ids): IndexWriteResult;

    /**
     * Make a generation the one that serves reads, atomically where the engine can.
     *
     * @param IndexGeneration $generation The generation to promote.
     *
     *
     * @since  2.0.0
     */
    public function activateGeneration(IndexGeneration $generation): void;

    /**
     * Discard a generation and its storage.
     *
     * @param IndexGeneration $generation The generation to remove.
     *
     *
     * @since  2.0.0
     */
    public function dropGeneration(IndexGeneration $generation): void;

    /**
     * Block until writes into a generation are durable and searchable, and report
     * anything that failed AFTER it was acknowledged.
     *
     * THE RETURN TYPE IS THE POINT. Several engines accept a batch and apply it
     * later, answering a bulk write with an HTTP `202` and no per-document outcome
     * at all. Such an engine has no honest choice but to report every document as
     * written, so a batch that is rejected minutes later (one malformed value, or a
     * container that ran out of memory) is invisible to {@see self::write()}. If
     * this method could only answer "settled: yes/no", the rebuild runner's refusal
     * to activate an incomplete generation would be inert for exactly the engines it
     * exists to protect, and the swap would publish an index quietly missing
     * thousands of products.
     *
     * The generation is passed rather than remembered, so an engine need not keep a
     * private ledger of in-flight work to answer a question the caller already knows
     * the answer to.
     *
     * NO ASYNC VOCABULARY CROSSES THIS LINE. Core does not know that tasks, jobs or
     * queues exist; an adapter polls whatever its service uses and returns the
     * outcome. A synchronous engine returns an empty result immediately, and pays
     * nothing for the abstraction.
     *
     * @param IndexGeneration $generation The generation whose writes must settle.
     * @param float $timeoutSeconds Maximum time to wait.
     *
     * @return IndexWriteResult Late failures, keyed as in {@see self::write()}. An
     *                          empty result means everything acknowledged was applied.
     *
     * @throws RuntimeException When the engine does not settle within the timeout:
     *                          unknown is not the same as clean, and must never be
     *                          reported as success.
     *
     * @since  2.0.0
     */
    public function await(IndexGeneration $generation, float $timeoutSeconds = 30.0): IndexWriteResult;

    /**
     * How many documents this engine prefers per {@see self::write()} call.
     *
     * The engine knows its own payload limits; core should not guess them.
     *
     *
     * @since  2.0.0
     */
    public function preferredBatchSize(): int;
}
