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
 * The outcome of one write batch, per document.
 *
 * INVARIANT PROTECTED: a partially failed batch can never be mistaken for a
 * successful one. An engine that answers with a bare boolean forces the caller to
 * choose between retrying 5,000 documents because one was rejected, or losing that
 * one silently. Both are wrong, and the second is the kind of wrong nobody notices
 * until a merchant reports a product that "never comes up".
 *
 * Durability is NOT reported here. An engine that acknowledges writes before applying
 * them (as hosted engines typically do) reports late failures through
 * {@see SearchIndexerInterface::await()}, which the rebuild runner calls
 * unconditionally before activating a generation. One gate, taken by every engine,
 * rather than a flag the runner has to remember to read.
 *
 * @since  2.0.0
 */
final class IndexWriteResult
{
    /**
     * @param int $written Documents the engine accepted.
     * @param array<string, string> $failures Document key => engine-reported reason. The key is
     *                                        {@see DocumentId::key()}, so a caller can retry exactly
     *                                        the documents that failed and nothing else.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $written = 0,
        public readonly array $failures = [],
    ) {
    }
}
