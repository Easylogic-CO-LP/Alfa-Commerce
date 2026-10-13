<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

/**
 * What an entrance asks the credit calculator to calculate.
 *
 * Every member is required. The language and the buyer are not inputs: the
 * calculator takes both from the corrected document,
 * so no entrance can state either.
 *
 * @since  2.0.0
 */
final class CreditInput
{
    /**
     * @param int $orderId The order the credit is issued on.
     * @param string $docType A DocumentHelper::creditTypes() alias.
     * @param int $seriesId The numbering series; 0 = the type's default series.
     * @param int $correctsId The corrected (issued sales) document.
     * @param array $itemPicks [id_order_item => ['selected' => 1, 'quantity' => float]], as DocumentDraft::lines() takes them.
     * @param array $postedCharges The form's `charges` array, exactly as OrderChargeLines::manualCreditPicks() reads it.
     * @param int $actorId The draft's created_by; 0 = the system.
     * @param string|null $printNote The printed remark, or null.
     *
     * @since   2.0.0
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $docType,
        public readonly int $seriesId,
        public readonly int $correctsId,
        public readonly array $itemPicks,
        public readonly array $postedCharges,
        public readonly int $actorId,
        public readonly ?string $printNote,
    ) {
    }
}
