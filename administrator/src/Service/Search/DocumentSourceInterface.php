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

/**
 * Where documents come from: the commerce side of a rebuild.
 *
 * INVARIANT PROTECTED: a rebuild can never load the catalogue into memory, and the
 * rebuild runner can never learn the catalogue's schema.
 *
 * The whole surface is one lazy {@see self::documents()} stream. There is
 * deliberately no `all()` and no `loadDocuments()`: the first thing that kills a
 * rebuild on a large catalogue is materialising it, and an interface that CAN return
 * everything eventually will.
 *
 * The stream is typed `iterable`, not `Generator`, because a generator is a
 * MECHANISM and this is a CONTRACT. Core's own source is a generator over a keyset
 * walk; an ERP integration may return a scroll cursor, an API client a pagination
 * iterator. None of that is core's business, and demanding `Generator` would force
 * every one of them to wrap a perfectly good iterator in a pointless one.
 *
 * `iterable` does technically admit an array, so the laziness invariant lives here
 * in prose rather than in the signature: an implementation MUST stream and MUST NOT
 * materialise the dataset. The type is open; the semantic is not.
 *
 * Yielding is RECORD-ATOMIC and keyed by record id: one yield carries every
 * language of one record. That is what makes a checkpoint honest: a consumer may
 * stop between yields and resume from the last key, and can never stop having
 * written a product's English document but not its Greek one.
 *
 * An empty array is a first-class answer meaning "this record must not be indexed"
 * (unpublished, or no searchable content). It is how a record removes itself,
 * without the source needing a separate deletion channel.
 *
 * The stream says nothing about its own storage. Today it is a keyset-paginated
 * MySQL walk; tomorrow it may be an ERP cursor, a marketplace import or an API
 * scroll. The rebuild pipeline does not change.
 *
 * @since  2.0.0
 */
interface DocumentSourceInterface
{
    /**
     * Every content language a complete rebuild must cover.
     *
     * @return LanguageTag[]
     *
     * @since  2.0.0
     */
    public function languages(): array;

    /**
     * How many source records exist, for progress reporting only. A count that
     * drifts mid-rebuild must never break the run.
     *
     *
     * @since  2.0.0
     */
    public function countRecords(): int;

    /**
     * Lazily stream documents, one record at a time, in ascending record order.
     *
     * Implementations MUST paginate by KEYSET (`id > :cursor ORDER BY id LIMIT n`),
     * never by offset. `LIMIT n OFFSET m` makes the database walk and discard m rows
     * on every page, so a rebuild grows quadratically slower the further it gets,
     * and it is not stable under concurrent writes: a record inserted or removed
     * mid-run shifts every later page and silently skips products.
     *
     * Implementations MUST be lazy. Returning a fully built array satisfies the type
     * and violates the contract.
     *
     * @param int $afterId Exclusive lower bound on the record id; 0 to start.
     *
     * @return iterable<int, SearchDocument[]> Record id => that record's documents in
     *                                         every language, or [] when it must not
     *                                         be indexed.
     *
     * @since  2.0.0
     */
    public function documents(int $afterId = 0): iterable;

    /**
     * Documents for a NAMED set of records, in the same shape as the walk.
     *
     * Exists for reconciliation, and reconciliation exists because a rebuild is not
     * instantaneous. The runner builds a batch of documents and writes it afterwards;
     * a record saved inside that window is written correctly by the live path and
     * then overwritten by the batch's older snapshot. Re-reading exactly those
     * records just before activation repairs it, without repeating the walk.
     *
     * Same contract as {@see self::documents()} in every other respect: keyed by
     * record id, every language of a record together, `[]` for a record that must not
     * be indexed so the consumer can delete rather than skip. Records that no longer
     * exist are simply absent.
     *
     * @param int[] $recordIds Records to re-read; an empty array yields nothing.
     *
     * @return iterable<int, SearchDocument[]>
     *
     * @since  2.0.0
     */
    public function documentsFor(array $recordIds): iterable;
}
