<?php
/**
 * Order Edit — Totals Summary Card
 *
 * Displays the order's financial summary: products, shipping, discounts,
 * grand total, paid amount, and balance due.
 *
 * All values come from Money objects computed by OrderModel::getItem().
 * No additional DB queries — everything is pre-calculated.
 *
 * Formula: Grand Total = Products + Shipping - Discounts
 *
 * Visual indicators:
 *   - Discounts: red with minus prefix (hidden when zero)
 *   - Grand total: bold in highlighted row
 *   - Paid: green when fully paid, orange when partial
 *   - Balance due: red warning row (hidden when fully paid)
 *   - Excl. VAT line shown below grand total
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_totals.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  3.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;

$order = $this->order;

// Check if Money objects are available (they should always be,
// but defensive in case of currency loading failure)
$hasMoney = isset($order->total_products_tax_incl)
	&& is_object($order->total_products_tax_incl);

// Pre-compute amounts for conditional rendering
if ($hasMoney) {
	$productsIncl  = $order->total_products_tax_incl->getAmount();
	$productsExcl  = $order->total_products_tax_excl->getAmount();
	$shippingIncl  = $order->total_shipping_tax_incl->getAmount();
	$shippingExcl  = $order->total_shipping_tax_excl->getAmount();
	$discountsIncl = $order->total_discounts_tax_incl->getAmount();
	$grandIncl     = $order->total_paid_tax_incl->getAmount();
	$grandExcl     = $order->total_paid_tax_excl->getAmount();
	$paidAmount    = $order->total_paid_real->getAmount();
	$balance       = $grandIncl - $paidAmount;
	$isFullyPaid   = $balance <= 0.001;
	$isPartialPaid = $paidAmount > 0 && !$isFullyPaid;
}
?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!--  ORDER TOTALS SUMMARY CARD                                     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="row mt-4 mb-3">
	<div class="col-lg-5 col-md-7 ms-auto">
		<div class="card shadow-sm">

			<!-- Card header -->
			<div class="card-header bg-light py-2">
				<h5 class="card-title mb-0">
					<span class="icon-calculator me-2"></span>
					<?php echo Text::_('COM_ALFA_ORDER_TOTALS'); ?>
				</h5>
			</div>

			<!-- Totals table -->
			<div class="card-body p-0">
				<?php if ($hasMoney) : ?>
					<table class="table table-sm mb-0">
						<tbody>

						<!-- ── Products subtotal ─────────────────── -->
						<tr>
							<td class="ps-3 border-0">
								<?php echo Text::_('COM_ALFA_PRODUCTS'); ?>
								<small class="text-muted ms-1">(<?php echo count($order->items ?? []); ?>)</small>
							</td>
							<td class="text-end pe-3 border-0">
								<?php echo $order->total_products_tax_incl->format(); ?>
							</td>
						</tr>

						<!-- ── Shipping ──────────────────────────── -->
						<?php if ($shippingIncl > 0) : ?>
							<tr>
								<td class="ps-3 border-0">
									<?php echo Text::_('COM_ALFA_SHIPPING'); ?>
								</td>
								<td class="text-end pe-3 border-0">
									<?php echo $order->total_shipping_tax_incl->format(); ?>
								</td>
							</tr>
						<?php endif; ?>

						<!-- ── Discounts (only if > 0) ──────────── -->
						<?php if ($discountsIncl > 0) : ?>
							<tr>
								<td class="ps-3 border-0">
									<?php echo Text::_('COM_ALFA_DISCOUNTS'); ?>
								</td>
								<td class="text-end pe-3 border-0 text-danger">
									−<?php echo $order->total_discounts_tax_incl->format(); ?>
								</td>
							</tr>
						<?php endif; ?>

						<!-- ── Separator ─────────────────────────── -->
						<tr><td colspan="2" class="p-0"><hr class="my-0"></td></tr>

						<!-- ── Grand total (incl. tax) ──────────── -->
						<tr style="background:#f8f9fa">
							<td class="ps-3 border-0">
								<strong><?php echo Text::_('COM_ALFA_GRAND_TOTAL'); ?></strong>
							</td>
							<td class="text-end pe-3 border-0">
								<strong style="font-size:1.1em">
									<?php echo $order->total_paid_tax_incl->format(); ?>
								</strong>
							</td>
						</tr>

						<!-- ── Grand total (excl. tax) ──────────── -->
						<tr>
							<td class="ps-3 border-0">
								<small class="text-muted"><?php echo Text::_('COM_ALFA_EXCL_VAT'); ?></small>
							</td>
							<td class="text-end pe-3 border-0">
								<small class="text-muted">
									<?php echo $order->total_paid_tax_excl->format(); ?>
								</small>
							</td>
						</tr>

						<!-- ── Separator ─────────────────────────── -->
						<tr><td colspan="2" class="p-0"><hr class="my-0"></td></tr>

						<!-- ── Paid ──────────────────────────────── -->
						<tr>
							<td class="ps-3 border-0">
								<?php echo Text::_('COM_ALFA_PAID'); ?>
							</td>
							<td class="text-end pe-3 border-0">
								<?php
								$paidClass = 'text-muted';
								if ($isFullyPaid) {
									$paidClass = 'text-success fw-bold';
								} elseif ($isPartialPaid) {
									$paidClass = 'text-warning fw-bold';
								}
								?>
								<span class="<?php echo $paidClass; ?>">
									<?php echo $order->total_paid_real->format(); ?>
								</span>
							</td>
						</tr>

						<!-- ── Balance due (only when not fully paid) ── -->
						<?php if (!$isFullyPaid) : ?>
							<tr style="background:#fff3cd">
								<td class="ps-3 border-0">
									<strong><?php echo Text::_('COM_ALFA_BALANCE_DUE'); ?></strong>
								</td>
								<td class="text-end pe-3 border-0">
									<strong class="text-danger" style="font-size:1.05em">
										<?php echo Money::of($balance, $order->currency)->format(); ?>
									</strong>
								</td>
							</tr>
						<?php endif; ?>

						</tbody>
					</table>
				<?php else : ?>
					<!-- Fallback when Money objects unavailable -->
					<div class="p-3 text-muted">
						<?php echo Text::_('COM_ALFA_TOTALS_UNAVAILABLE'); ?>
					</div>
				<?php endif; ?>
			</div>

		</div>
	</div>
</div>