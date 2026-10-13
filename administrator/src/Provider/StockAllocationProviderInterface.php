<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Provider;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;

/**
 * Answers the core's only allocation question: "where does this
 * quantity come from?", asked at RESERVE time and only there
 * (fulfilment consumes allocation, it never recomputes it).
 *
 * Providers form a priority chain (collected via
 * onCollectStockAllocationProviders); each may answer, pass, or refuse:
 *   answer  = [locationId => positive quantity] summing to the ask
 *   pass    = null (the next provider in the chain is asked)
 *   refuse  = throw AllocationRefusedException: a business rule says NO
 *             allocation is permitted; the reservation fails loudly and
 *             the default location is never a silent substitute
 *
 * Part of the Alfa provider family: capabilities are declared in code (
 * implemented interfaces and subscribed events), never manifest
 * metadata. Nearest-store, priority-warehouse, dropship and
 * vendor-preference strategies are packages built on this contract.
 *
 * @since  2.0.0
 */
interface StockAllocationProviderInterface
{
    /**
     * Allocate a reservation ask across locations, or pass.
     *
     * @param array $unit The stock unit: ['item' => int, 'combination' => int (0 = item-level)].
     * @param float $quantity The asked quantity.
     * @param InventoryContext $context The command's context.
     *
     * @return array<int, float>|null locationId => quantity, or null to pass.
     *
     * @since  2.0.0
     */
    public function allocate(array $unit, float $quantity, InventoryContext $context): ?array;
}
