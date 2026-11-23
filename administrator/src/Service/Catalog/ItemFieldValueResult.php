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
 * What a save of an item field's values did ({@see ItemFieldValueService::save()}): written, or refused with nothing
 * written.
 *
 * @since  2.3.0
 */
final class ItemFieldValueResult
{
    /**
     * @param bool $saved Whether the steps were written.
     * @param list<Refusal> $refusals Why nothing was written.
     * @param list<int> $created The values created, in the order of the steps.
     * @param list<int> $changed The values changed.
     * @param list<int> $removed The values removed.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly bool $saved,
        public readonly array $refusals = [],
        public readonly array $created = [],
        public readonly array $changed = [],
        public readonly array $removed = [],
    ) {
    }
}
