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
 * The checkpoint of a rebuild in flight: everything needed to resume it.
 *
 * INVARIANT PROTECTED: a rebuild can never resume into the wrong generation or
 * silently forget what failed. A rebuild spans many HTTP requests (or a killed CLI
 * run), so its state must survive the process. Holding it in a serialisable value
 * means "resume" is reconstructing this object, not re-deriving where we were;
 * and re-deriving is how a rebuild ends up writing into the live generation.
 *
 * Failures are carried, not logged and dropped: a generation must not be activated
 * while documents are known to be missing from it. That decision belongs to
 * whoever reads this, which is why the data survives to be read.
 *
 * @since  1.5.0
 */
final class RebuildProgress
{
    /**
     * @param string $generation Opaque engine generation handle being written into.
     * @param int $cursor Keyset position: the highest record id already
     *                    processed. Resumption means "everything after this",
     *                    which stays true across requests and concurrent writes
     *                    (unlike a row offset).
     * @param int $processed Records walked so far, for reporting only.
     * @param int $total Records expected, for reporting only.
     * @param int $written Documents accepted by the engine so far.
     * @param int $removed Documents deleted (records that declined to be indexed).
     * @param array<string, string> $failures Document key => reason, accumulated across batches.
     * @param bool $enumerated Whether every record has been walked.
     * @param bool $activated Whether the generation now serves reads.
     * @param string $supersedes Handle of the generation that was serving when this
     *                           rebuild started, so it can be swept after the swap.
     *                           Captured at start() because after activation the engine
     *                           no longer distinguishes it from any older generation.
     *
     * @since  1.5.0
     */
    public function __construct(
        public readonly string $generation,
        public readonly int $cursor = 0,
        public readonly int $processed = 0,
        public readonly int $total = 0,
        public readonly int $written = 0,
        public readonly int $removed = 0,
        public readonly array $failures = [],
        public readonly bool $enumerated = false,
        public readonly bool $activated = false,
        public readonly string $supersedes = '',
    ) {
    }

    /**
     * Serialise for storage between requests.
     *
     * @return array<string, mixed>
     *
     * @since  1.5.0
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation,
            'cursor' => $this->cursor,
            'processed' => $this->processed,
            'total' => $this->total,
            'written' => $this->written,
            'removed' => $this->removed,
            'failures' => $this->failures,
            'enumerated' => $this->enumerated,
            'activated' => $this->activated,
            'supersedes' => $this->supersedes,
        ];
    }

    /**
     * Restore a checkpoint.
     *
     * @param array<string, mixed> $state A value previously produced by toArray().
     *
     *
     * @since  1.5.0
     */
    public static function fromArray(array $state): self
    {
        return new self(
            generation: (string) ($state['generation'] ?? ''),
            cursor:     (int) ($state['cursor'] ?? 0),
            processed:  (int) ($state['processed'] ?? 0),
            total:      (int) ($state['total'] ?? 0),
            written:    (int) ($state['written'] ?? 0),
            removed:    (int) ($state['removed'] ?? 0),
            failures:   (array) ($state['failures'] ?? []),
            enumerated: (bool) ($state['enumerated'] ?? false),
            activated:  (bool) ($state['activated'] ?? false),
            supersedes: (string) ($state['supersedes'] ?? ''),
        );
    }
}
