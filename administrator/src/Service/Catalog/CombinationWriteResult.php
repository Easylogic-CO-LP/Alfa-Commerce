<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Refusal;

/**
 * What a combination's write came to ({@see CombinationService}): whether it was saved, the combination, the
 * refusals that stopped it (nothing written), and the warnings of a save applied in part (its stock not changed,
 * the stock a tracking switch kept uncounted).
 *
 * @since  2.3.0
 */
final class CombinationWriteResult
{
    /**
     * @param bool $saved Whether the write was saved.
     * @param int $id The combination; 0 when none was made.
     * @param list<Refusal> $refusals Why nothing was written.
     * @param list<array{message: string, type: string}> $warnings What the save did not apply, or applied with a remark.
     * @param list<int> $removed The combinations removed (deleted, or archived where order history names them).
     * @param list<int> $archived Of those, the ones archived.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly bool $saved,
        public readonly int $id = 0,
        public readonly array $refusals = [],
        public readonly array $warnings = [],
        public readonly array $removed = [],
        public readonly array $archived = [],
    ) {
    }
}
