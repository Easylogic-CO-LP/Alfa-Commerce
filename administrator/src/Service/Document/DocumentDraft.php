<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;

/**
 * The one place a document's lines are assembled from an operator's picks.
 *
 * WHY THIS EXISTS. The document reconciliation contract, section 7:
 * "There must never be a `proposal calculator` and a `manual calculator` as
 * separate financial models", and section 19: "All of these paths must converge on the
 * same domain services... UI origin must never change financial meaning."
 *
 * This assembly used to live inside OrderController::issueDocument(), the
 * manual path. The moment a PROPOSED correction needed the same draft, that
 * private copy became the risk the contract names: two assemblers that agree
 * today and drift apart on the first edit to either. So it moved here, and both
 * entrances call it.
 *
 * IT CALCULATES NOTHING ITSELF. Every figure comes from OrderItemLines and
 * OrderChargeLines, which are the same builders the issuer's guards measure
 * against. What this class owns is the ORDER of the assembly and one derived
 * fact (whether the draft finishes the sales pool), which decides how the
 * charge builder allocates the remainder.
 *
 * @since  1.3.0
 */
final class DocumentDraft
{
    /**
     * Assemble a document's complete line set: items first, then the non-item money.
     *
     * The item lines come from the corrected DOCUMENT for a credit and from the
     * ORDER for everything else, which is the frozen-money rule, not a
     * convenience: an order line stays editable after issuance, so a credit
     * that read it would credit a price the invoice never charged.
     *
     * @param int $orderId The order.
     * @param string $docType The type being drafted.
     * @param array $picks [lineId => ['selected' => 1, 'quantity' => n]].
     * @param array $chargePicks ['shipping' => int[], 'fees' => int[], 'rules' => int[]], and optionally
     *                           'amounts' => ['shipping' => [id => incl], 'fees' => [id => incl],
     *                           'rules' => [id => incl]]: what a credit claims of a picked source and
     *                           of the shipping discount riding it, capped by the builder at what the
     *                           corrected document has left. Without it a pick is whole.
     * @param int $correctsId The corrected document, for a credit; 0 otherwise.
     * @param string $language The language the frozen names are taken in.
     *
     * @return array The merged line rows, ready for DocumentIssuer.
     *
     * @since   1.3.0
     */
    public static function lines(
        int $orderId,
        string $docType,
        array $picks,
        array $chargePicks,
        int $correctsId,
        string $language,
    ): array {
        $isCredit = \in_array($docType, DocumentHelper::creditTypes(), true);

        $lines = $isCredit && $correctsId > 0
            ? OrderItemLines::buildCreditLines(correctsId: $correctsId, picks: $picks)
            : OrderItemLines::buildSalesLines(
                orderId:  $orderId,
                docType:  $docType,
                picks:    $picks,
                language: $language,
            );

        return array_merge($lines, OrderChargeLines::buildLines(
            orderId:       $orderId,
            docType:       $docType,
            itemLines:     $lines,
            picks:         [
                'shipping' => (array) ($chargePicks['shipping'] ?? []),
                'fees' => (array) ($chargePicks['fees'] ?? []),
                'rules' => (array) ($chargePicks['rules'] ?? []),
                // A credit's per-source claim, when the picker sized one.
                'amounts' => (array) ($chargePicks['amounts'] ?? []),
            ],
            // The SALES pool's finality. A credit's is measured against the
            // invoice it corrects and the builder computes its own.
            finalForItems: self::finishesItemPool($orderId, $docType, $picks),
            correctsId:    $correctsId,
        ));
    }

    /**
     * Would this draft leave the type's item pool empty?
     *
     * The charge builder allocates a remainder differently on the FINAL draft
     * of a pool, so this is a financial input and not a cosmetic flag.
     *
     * @param int $orderId The order.
     * @param string $docType The type being drafted.
     * @param array $picks The item picks this draft carries.
     *
     *
     * @since   1.3.0
     */
    private static function finishesItemPool(int $orderId, string $docType, array $picks): bool
    {
        $remainingAfter = DocumentHelper::remainingQuantities(orderId: $orderId, docType: $docType);

        foreach ($picks as $lineId => $pick) {
            if (!empty($pick['selected']) && isset($remainingAfter[(int) $lineId])) {
                // Clamped: a negative quantity builds no line above but would
                // INFLATE the remainder here and flip this flag on the true
                // final draft.
                $remainingAfter[(int) $lineId]['remaining'] -= max(0.0, (float) ($pick['quantity'] ?? 0));
            }
        }

        return array_filter(
            $remainingAfter,
            static fn (array $r): bool => $r['remaining'] > 0.000001,
        ) === [];
    }
}
