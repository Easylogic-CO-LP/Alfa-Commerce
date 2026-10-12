<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Joomla\CMS\Language\Text;

/**
 * The Available cell: physical minus reserved, derived and never editable.
 *
 * A layout rather than inline markup because TWO callers render it: the items
 * list paints it per row, and an inline stock save re-renders it so the figure
 * the merchant just changed does not sit next to a stale "available". Sharing
 * the file is what keeps the badge rules from drifting apart between the two.
 *
 * The badge is the stock verdict of {@see InventoryAvailabilityService::statusFrom()}.
 * A product whose figures have no stock records behind them, or that is held until its
 * figures are repaired, is badged as such and never as in stock, whatever its backorder
 * mode and quantity rules. A product whose quantity rules accept no quantity, or whose
 * stock cannot cover its smallest sale under deny, is badged out of stock, tracked or not.
 *
 * @var array    $displayData
 * @var int      $id            The item id: the refresh target's address.
 * @var bool     $manageStock   Whether stock is tracked (for a product with combinations:
 *                              whether any combination tracks stock).
 * @var bool     $hasVariants   Whether variants own the stock.
 * @var float    $available     The product's salable projection (items.available_quantity),
 *                              the figure shown in the cell. Every caller passes this same
 *                              column, so a refreshed cell reads as the listed one does.
 * @var bool     $ledgerMissing Required. Whether the product (or one of its combinations)
 *                              manages stock and shows stock figures with no stock records,
 *                              or has an open integrity hold.
 * @var int|null $saleMinimum   Required. The product's smallest sale under its quantity
 *                              rules, null when they accept no quantity; 1 for a product
 *                              with combinations, whose rules apply per combination.
 * @var bool     $integrityHold Whether the product has an open integrity hold that
 *                              $ledgerMissing does not already carry; false when absent.
 * @var float    $stockLow      Low-stock threshold, null when unset.
 * @var float    $minQty        Safety stock.
 * @var string   $backorder     The item's backorder policy ('' = inherit global).
 */
foreach (['ledgerMissing', 'saleMinimum'] as $required) {
    if (!\array_key_exists($required, $displayData)) {
        throw new \InvalidArgumentException('The inventory.availability layout requires the ' . $required . ' key.');
    }
}

extract($displayData);

$id            = (int) ($id ?? 0);
$tracked       = !empty($manageStock);
$available     = (float) ($available ?? 0);
$lowAt         = ($stockLow ?? null) === null ? null : (float) $stockLow;
$minQty        = (float) ($minQty ?? 0);
$policy        = (string) ($backorder ?? '');
$ledgerMissing = (bool) $ledgerMissing;

// The figure is the product's salable projection; the missing-ledger flag comes
// from the stock records and the integrity holds, so a projection with nothing
// behind it is badged as the defect it is rather than as stock. The flag ranks
// first, as a hold does in the verdict, so neither quantity rules that accept
// nothing nor an untracked product hide the defect from the merchant.
$status = InventoryAvailabilityService::statusFrom(
    salable:           $available,
    managed:           $tracked,
    ledgerPresent:     !$ledgerMissing,
    projectionNonZero: $ledgerMissing,
    integrityHold:     $ledgerMissing || !empty($integrityHold),
    backorder:         $policy,
    minQty:            $minQty,
    saleMinimum:       $saleMinimum === null ? null : (int) $saleMinimum,
);

// Low is a display refinement of "in stock", not a kernel state; it never
// changes what the shop will sell.
$isLow = $tracked && $status === InventoryAvailabilityService::STATUS_IN_STOCK
    && $lowAt !== null && $lowAt > 0 && $available <= $lowAt;

// A badge only earns its place when it says something the number cannot: a
// plain positive figure already reads as "in stock", so labelling it adds
// noise to every row and buries the rows that genuinely need attention.
$badge = match (true) {
    $status === InventoryAvailabilityService::STATUS_LEDGER_MISSING => ['danger', 'COM_ALFA_INVENTORY_STATUS_LEDGER_MISSING'],
    $isLow => ['warning', 'COM_ALFA_INVENTORY_STATUS_LOW'],
    $status === InventoryAvailabilityService::STATUS_BACKORDER => ['warning', 'COM_ALFA_INVENTORY_STATUS_BACKORDER'],
    $status === InventoryAvailabilityService::STATUS_OUT_OF_STOCK => ['danger', 'COM_ALFA_INVENTORY_STATUS_OUT_OF_STOCK'],
    default => null,
};
?>
<span class="alfa-availability-cell" data-alfa-cell="availability" data-id="<?php echo $id; ?>">
<?php if (!$tracked) : ?>
    <span class="text-muted"><?php echo Text::_('COM_ALFA_ITEMS_STOCK_NOT_TRACKED'); ?></span>
    <?php if ($badge !== null) : ?>
        <span class="badge bg-<?php echo $badge[0]; ?>"><?php echo Text::_($badge[1]); ?></span>
    <?php endif; ?>
<?php else :
    // Safety stock is invisible in `available` (physical - reserved), so say so,
    // but only where it actually withholds units, i.e. under Deny.
    $heldBack = InventoryAvailabilityService::safetyStockBinds(
        itemBackorder: $policy,
        minQty:        $minQty,
    );
    ?>
    <?php // Derived, never editable: muted number, no pencil ever. ?>
    <span class="alfa-cell alfa-availability" title="<?php echo htmlspecialchars(Text::_(!empty($hasVariants)
        ? 'COM_ALFA_ITEMS_AVAILABLE_VARIANTS'
        : 'COM_ALFA_ITEMS_AVAILABLE_HINT'), ENT_QUOTES, 'UTF-8'); ?>">
        <span class="alfa-availability-line">
            <span class="alfa-availability-value"><?php echo htmlspecialchars(InventoryAvailabilityService::formatQuantity($available), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($badge !== null) : ?>
                <span class="badge bg-<?php echo $badge[0]; ?>"><?php echo Text::_($badge[1]); ?></span>
            <?php endif; ?>
        </span>
        <?php if ($heldBack) : ?>
            <span class="alfa-cell-meta" title="<?php echo htmlspecialchars(Text::_('COM_ALFA_ITEMS_AVAILABLE_SAFETY_HINT'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo Text::sprintf(
                    'COM_ALFA_ITEMS_AVAILABLE_SAFETY',
                    InventoryAvailabilityService::formatQuantity($minQty),
                ); ?>
            </span>
        <?php endif; ?>
    </span>
<?php endif; ?>
</span>
