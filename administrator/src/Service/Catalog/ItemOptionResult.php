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
 * What a save of a product's options and specifications did ({@see ItemOptionService::save()}): written, or refused
 * with nothing written.
 *
 * @since  2.3.0
 */
final class ItemOptionResult
{
    /**
     * @param bool $saved Whether the steps were written.
     * @param list<Refusal> $refusals Why nothing was written.
     * @param list<int> $added The values made options, in the order of the steps.
     * @param list<int> $changed The options whose price change was set.
     * @param list<int> $removed The options removed.
     * @param int $cartSelectionsRemoved The cart selections of the product's lines that chose a removed option.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly bool $saved,
        public readonly array $refusals = [],
        public readonly array $added = [],
        public readonly array $changed = [],
        public readonly array $removed = [],
        public readonly int $cartSelectionsRemoved = 0,
    ) {
    }
}
