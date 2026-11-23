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
 * What a product's save came to ({@see ItemWriteService}): whether it was saved, the product's id, the refusals that
 * stopped it, the warnings of a save applied in part (stock or variants refused after the product was written, an
 * address shared with another product), and the background tasks it left.
 *
 * @since  2.3.0
 */
final class ItemWriteResult
{
    /**
     * @param bool $saved Whether the product was saved.
     * @param int $id The product's id; 0 when a new product was not saved.
     * @param list<string> $refusals Why the save was refused, nothing written; empty when it was saved.
     * @param list<array{message: string, type: string}> $warnings What the save did not apply, or applied with a
     *                                                             remark, each with its kind (warning or error).
     * @param list<int> $tasks The ids of the background tasks the save left.
     * @param array $written What a save with the product's graph wrote, part by part ({@see ItemWriteService}):
     *                       field_values, options and combinations (each with its pointer, its id and whether it was
     *                       created), informational (the value ids, when sent) and stock (each line applied); empty for
     *                       a save without a graph.
     * @param list<Refusal> $refused The refusals of a save with the product's graph as values, each with its JSON pointer
     *                               and status (the same refusals as $refusals, which are their sentences).
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly bool $saved,
        public readonly int $id,
        public readonly array $refusals = [],
        public readonly array $warnings = [],
        public readonly array $tasks = [],
        public readonly array $written = [],
        public readonly array $refused = [],
    ) {
    }
}
