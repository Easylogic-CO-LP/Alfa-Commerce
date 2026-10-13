<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

/**
 * One fulfilment ask, whatever triggered it. Order-status semantics and
 * document stock effects BOTH build a CommitmentRequest and hand it to
 * InventoryService::commitReservation(); neither trigger owns matching
 * or deduction logic, and new triggers (point-of-sale, courier webhooks,
 * subscription renewals) are new builders of this object, nothing more.
 *
 * Lines: each entry is an array with
 *   item_id        int      the product
 *   combination_id int|null the line's combination (null = none)
 *   quantity       float    the ask (see mode)
 *   ordered        float    total ordered quantity of the line (over-shipment bound)
 *   line_id        int|null source line, the cumulative-ledger idempotency anchor
 *   mode           string   'target' (quantity = absolute line coverage; status
 *                           semantics and reconciliation) or 'increment'
 *                           (quantity = this trigger's ask; documents and
 *                           connectors - default)
 *   location_id    int|null pinned location; null = consume the line's allocation
 *
 * @since  2.0.0
 */
final class CommitmentRequest
{
    /**
     * @param string $sourceType Trigger domain ('order', 'document', ...).
     * @param int $sourceId Trigger row id within its domain.
     * @param array $lines Fulfilment lines (see class docblock).
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly array $lines,
    ) {
    }
}
