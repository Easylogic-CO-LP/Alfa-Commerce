<?php
/**
 * Orders List: Main Template
 *
 * Architecture: row and detail panel are inline here (straightforward,
 * no reuse). The 5 detail panel sections are delegated to sub-templates
 * because they have distinct logic and grow independently.
 *
 * Sub-template map:
 *   default_products.php  -> Products grid
 *   default_shipments.php -> Shipments list + action button placeholders
 *   default_payments.php  -> Payments list + refund data + action placeholders
 *   default_discounts.php -> Cart rules / discount codes
 *   default_notes.php     -> Customer + internal notes
 *
 * Per-row data for sub-templates is passed via:
 *   $this->currentItem - the current order object (set once per loop iteration)
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\Actions;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\Columns;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;

// Web assets
$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
	->useScript('com_alfa.admin')
	->usePreset('com_alfa.toast')
	->useStyle('com_alfa.admin.orders-list')
	->useScript('com_alfa.admin.orders-list')
	->useScript('com_alfa.actions');

Text::script('JCLOSE'); //used by actions modal popups

// NOTE: table.columns deliberately NOT loaded; it adds "X/X Columns"
// dropdown to ALL <table> elements including inner detail panel tables.

// Loads Bootstrap UMD bundle -> creates window.bootstrap.
// Required for the action modal (Alfa.openActionFrame -> bootstrap.Modal).
// The edit view gets this via HTMLHelper::_('bootstrap.tooltip').
// The list view has no tooltips that trigger it implicitly.
HTMLHelper::_('bootstrap.modal');

// View-level variables
$listOrder     = $this->escape($this->state->get('list.ordering'));
$listDirn      = $this->escape($this->state->get('list.direction'));
$user          = $this->getCurrentUser();
$canEdit       = AlfaHelper::can(section: 'orders', action: 'edit', user: $user);
$canCheckin    = $canEdit; // releasing an edit lock is part of editing
// The inline status select: exactly what order.updateOrder asks for a status-only change.
$canChange     = AlfaHelper::can(section: 'orders', action: 'edit.state', user: $user);
$orderStatuses = $this->orderStatuses;

// Plugin-declared columns (onAlfaAdminListColumns)
// Grouped by their anchor; unknown anchors fold into Columns::END so a
// declared column always renders and the table stays rectangular.
$columnsByAnchor = Columns::prepare(
    $this->pluginColumns ?? [],
    ['id', 'customer', 'amount', 'date', 'payment', 'shipment', 'status'],
);
// 10, not 9: the attention indicator added a column of its own.
$colspan = 10 + count($this->pluginColumns ?? []) + Actions::span($this->pluginRowActions ?? []);
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=orders'
          . ($this->isModal ? '&tmpl=component&modal=1' : '')); ?>"
      method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">

				<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
                <div class="clearfix"></div>
                <?php // The product filter has no field in the bar on purpose, so it says
                      // itself what it is doing - otherwise it is an invisible filter that
                      // survives navigation. ?>
                <?php if (!empty($this->filteredItemName)) : ?>
                    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <span>
                            <span class="icon-info-circle" aria-hidden="true"></span>
                            <?php echo Text::sprintf('COM_ALFA_ORDERS_FILTERED_BY_ITEM', $this->escape($this->filteredItemName)); ?>
                        </span>
                        <?php if (!$this->isModal) : ?>
                            <a class="alert-link fw-semibold"
                               href="<?php echo Route::_('index.php?option=com_alfa&view=orders&filter[item_id]=0', false); ?>">
                                <?php echo Text::_('COM_ALFA_ORDERS_FILTERED_BY_ITEM_CLEAR'); ?> &rarr;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php // "Placed over 48 hours ago" inside a window that ends before the
                      // cutoff can never match. Without this the grid answers with a bare
                      // "no orders", which reads as a quiet shop, not a dead filter. ?>
                <?php if (!empty($this->unsatisfiableWaiting)) : ?>
                    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <span>
                            <span class="icon-warning" aria-hidden="true"></span>
                            <?php echo Text::sprintf('COM_ALFA_ORDERS_WAITING_EXCLUDES_RANGE', $this->escape($this->unsatisfiableWaiting)); ?>
                        </span>
                        <?php if (!$this->isModal) : ?>
                            <a class="alert-link fw-semibold"
                               href="<?php echo Route::_('index.php?option=com_alfa&view=orders&filter[waiting]=', false); ?>">
                                <?php echo Text::_('COM_ALFA_ORDERS_WAITING_EXCLUDES_RANGE_CLEAR'); ?> &rarr;
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php echo $this->renderSlot('before-grid'); ?><?php echo Actions::bulkBar($this->pluginBulkActions ?? []); ?>


                <?php // data-alfa-list is the refresh hook: orders-list.js replaces this node
                      // in place after an action. Keep it if you override the id. ?>
                <table class="table table-striped table-hover" id="orderList" data-alfa-list="orders">

                    <!--  HEADER                                      -->
                    <thead>
                    <tr>
                        <th style="width:1%"  class="text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                        <th style="width:1%"  class="text-center"></th>
                        <th style="width:1%"  class="text-center" title="<?php echo Text::_('COM_ALFA_LIST_ATTENTION'); ?>">
							<span class="icon-info-circle" aria-hidden="true"></span>
                        </th>
                        <th style="width:4%"  class="text-center"><?php echo HTMLHelper::_('searchtools.sort', 'ID', 'a.id', $listDirn, $listOrder); ?></th>
                        <?php echo Columns::heads($columnsByAnchor, 'id'); ?>
                        <th><?php echo Text::_('COM_ALFA_CUSTOMER'); ?></th>
                        <?php echo Columns::heads($columnsByAnchor, 'customer'); ?>
                        <th style="width:12%" class="text-center"><?php echo Text::_('COM_ALFA_AMOUNT'); ?></th>
                        <?php echo Columns::heads($columnsByAnchor, 'amount'); ?>
                        <th style="width:10%" class="text-center"><?php echo HTMLHelper::_('searchtools.sort', Text::_('COM_ALFA_DATE'), 'a.created', $listDirn, $listOrder); ?></th>
                        <?php echo Columns::heads($columnsByAnchor, 'date'); ?>
                        <th style="width:8%"  class="text-center"><?php echo Text::_('COM_ALFA_PAYMENT'); ?></th>
                        <?php echo Columns::heads($columnsByAnchor, 'payment'); ?>
                        <th style="width:16%" class="text-center"><?php echo Text::_('COM_ALFA_SHIPMENT'); ?></th>
                        <?php echo Columns::heads($columnsByAnchor, 'shipment'); ?>
                        <th style="width:8%"  class="text-center"><?php echo Text::_('COM_ALFA_STATUS'); ?></th>
                        <?php echo Columns::heads($columnsByAnchor, 'status'); ?>
                        <?php echo Columns::heads($columnsByAnchor, Columns::END); ?><?php echo Actions::head($this->pluginRowActions ?? []); ?>
                    </tr>
                    </thead>

                    <!--  FOOTER (pagination)                         -->

                    <!--  BODY                                        -->
                    <tbody>

					<?php if (empty($this->items)) : ?>
                        <tr>
                            <td colspan="<?php echo $colspan; ?>" class="text-center">
                                <p class="mt-4 mb-4"><?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?></p>
                            </td>
                        </tr>
					<?php else : ?>

						<?php foreach ($this->items as $i => $item) :

							// Per-row computed values
							$detailId        = 'od-' . (int) $item->id;
							$currentStatusId = (int) ($item->id_order_status ?? 0);
							$currentStatus   = $orderStatuses[$currentStatusId] ?? null;
							$pSts            = $item->payment_status ?? 'unpaid';
							$fStatus         = $item->fulfillment_status ?? 'unfulfilled';
							$totalItems      = (int) ($item->total_items ?? 0);
							$fulItems        = (int) ($item->fulfilled_items ?? 0);

							// Pass item to section sub-templates via $this->currentItem.
							// loadTemplate() shares the full $this scope so sub-templates
							// read it directly; no extract() or globals needed.
							$this->currentItem = $item;
							?>

                            <!-- Main row -->
                            <tr>

                                <!-- Checkbox -->
                                <td class="text-center">
									<?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
                                </td>

                                <!-- Attention
                                     DERIVED AND REASON-BEARING. One dot, but it
                                     names every reason behind it, because
                                     "something is up with this order" is not an
                                     answer an operator can act on. A defect
                                     (reconciliation, an order that names no
                                     currency) reads red; work (overpaid, an
                                     unsettled credit note) reads amber, and
                                     the two never merge. -->
                                <td class="text-center">
									<?php if (!empty($item->needs_attention)) : ?>
										<?php
											$reasons = array_map(
												static fn (string $r): string => Text::_('COM_ALFA_ATTENTION_' . strtoupper($r)),
												$item->attention_reasons ?? [],
											);
										?>
                                        <span class="badge <?php echo !empty($item->needs_reconciliation) || ($item->currency_notice ?? '') !== '' ? 'bg-danger' : 'bg-warning text-dark'; ?>"
                                              data-alfa-attention="<?php echo htmlspecialchars(implode(',', $item->attention_reasons ?? []), ENT_QUOTES, 'UTF-8'); ?>"
                                              title="<?php echo htmlspecialchars(implode(' - ', $reasons), ENT_QUOTES, 'UTF-8'); ?>">
											<?php echo \count($reasons); ?>
                                        </span>
									<?php endif; ?>
                                </td>

                                <!-- Expand / collapse detail panel -->
                                <td class="text-center">
                                    <button class="btn-expand" type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#<?php echo $detailId; ?>"
                                            aria-expanded="false"
                                            aria-controls="<?php echo $detailId; ?>">&#9654;</button>
                                </td>

                                <!-- Order ID + reference -->
                                <td class="text-center">
									<?php if (isset($item->checked_out) && $item->checked_out && ($canEdit || $canChange)) : ?>
										<?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor, $item->checked_out_time, 'orders.', $canCheckin); ?>
									<?php endif; ?>

									<?php if ($canEdit) : ?>
                                        <a href="<?php echo Route::_('index.php?option=com_alfa&task=order.edit&id=' . (int) $item->id); ?>"
                                           <?php echo $this->isModal ? 'target="_blank" rel="noopener"' : ''; ?>
                                           class="badge bg-primary text-white" style="font-size:1em">
                                            #<?php echo (int) $item->id; ?>
                                        </a>
									<?php else : ?>
                                        <span class="badge bg-secondary">#<?php echo (int) $item->id; ?></span>
									<?php endif; ?>

									<?php if (!empty($item->reference)) : ?>
                                        <small class="d-block text-muted"><?php echo $this->escape($item->reference); ?></small>
									<?php endif; ?>
                                </td>

                                <?php echo Columns::cells($columnsByAnchor, 'id', $item); ?>

                                <!-- Customer: the order's snapshot (guests and deleted accounts included) -->
                                <td>
									<?php $customer = \Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity::forOrder($item); ?>
									<?php if ($customer['name'] !== '') : ?>
                                        <div class="customer-name"><?php echo $this->escape($customer['name']); ?></div>
									<?php endif; ?>
									<?php if ($customer['email'] !== '') : ?>
                                        <div class="customer-email"><?php echo $this->escape($customer['email']); ?></div>
									<?php endif; ?>
									<?php if ($customer['name'] === '' && $customer['email'] === '') : ?>
                                        <span class="text-muted"><?php echo Text::_('COM_ALFA_GUEST'); ?></span>
									<?php elseif ($customer['kind'] === 'guest') : ?>
                                        <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ALFA_CUSTOMER_KIND_GUEST'); ?></span>
									<?php elseif ($customer['kind'] === 'deleted') : ?>
                                        <span class="badge bg-light text-muted border"><?php echo Text::_('COM_ALFA_CUSTOMER_KIND_DELETED'); ?></span>
									<?php endif; ?>
                                </td>

                                <?php echo Columns::cells($columnsByAnchor, 'customer', $item); ?>

                                <!-- Amount: paid / total + payment status badge -->
                                <td class="text-center">
									<?php if (($item->currency_notice ?? '') !== '') : ?>
										<?php // The order names no currency: it has no amount and no payment state to show. ?>
                                    <span class="badge bg-danger"><?php echo $this->escape($item->currency_notice); ?></span>
									<?php else : ?>
									<?php
									// Tooltip: breakdown of products + shipping - discounts
									$ttParts = [];
									if (isset($item->items_total_money))
										$ttParts[] = Text::_('COM_ALFA_PRODUCTS') . ': ' . $item->items_total_money->format();
									if ((float) ($item->shipping_total ?? 0) > 0 && isset($item->shipping_total_money))
										$ttParts[] = Text::_('COM_ALFA_SHIPPING') . ': ' . $item->shipping_total_money->format();
									if ((float) ($item->contributions_total ?? 0) > 0 && isset($item->contributions_total_money))
										$ttParts[] = Text::_('COM_ALFA_CONTRIBUTIONS') . ': ' . $item->contributions_total_money->format();
									if ((float) ($item->discount_total ?? 0) > 0 && isset($item->discount_total_money))
										$ttParts[] = Text::_('COM_ALFA_DISCOUNTS') . ': −' . $item->discount_total_money->format();
									$tt = implode('<br>', $ttParts);
									?>
                                    <div class="price-display alfa-tip"
									     <?php if ($tt) : ?>data-tip="<?php echo $this->escape($tt); ?>"<?php endif; ?>
                                         style="cursor:<?php echo $tt ? 'help' : 'default'; ?>">
                                        <span class="price-paid">
                                            <?php echo isset($item->total_paid_real_money)
	                                            ? $item->total_paid_real_money->format()
	                                            : number_format((float) ($item->total_paid_real ?? 0), 2); ?>
                                        </span>
                                        <span class="price-total">
                                            / <?php echo isset($item->order_total_money)
												? $item->order_total_money->format()
												: number_format((float) ($item->order_total ?? 0), 2); ?>
                                        </span>
                                        <small class="d-block text-muted" style="font-weight:normal;font-size:0.76em">
											<?php echo Text::_('COM_ALFA_EXCL_VAT'); ?>:
											<?php echo isset($item->order_total_tax_excl_money)
												? $item->order_total_tax_excl_money->format()
												: number_format((float) ($item->order_total_tax_excl ?? 0), 2); ?>
                                        </small>
                                    </div>

                                    <div class="mt-1">
                                        <span class="pay-sts badge-<?php echo $pSts; ?>">
                                            <?php switch ($pSts) {
	                                            case 'paid':    echo Text::_('COM_ALFA_PAID'); break;
	                                            case 'partial': echo Text::_('COM_ALFA_PARTIAL') . ' (' . ($item->payment_percentage ?? 0) . '%)'; break;
	                                            case 'refunded':           echo Text::_('COM_ALFA_REFUNDED'); break;
	                                            case 'partially_refunded': echo Text::_('COM_ALFA_PARTIALLY_REFUNDED'); break;
	                                            default:        echo Text::_('COM_ALFA_UNPAID');
                                            } ?>
                                        </span>
										<?php if (!empty($item->total_refunded_real)) : ?>
                                            <small class="d-block text-muted" style="font-weight:normal;font-size:0.76em">
                                                <?php echo Text::_('COM_ALFA_REFUNDED'); ?>:
                                                &minus;<?php echo isset($item->total_refunded_real_money)
													? $item->total_refunded_real_money->format()
													: number_format((float) $item->total_refunded_real, 2); ?>
                                            </small>
										<?php endif; ?>
                                    </div>

									<?php if ($pSts === 'partial') : ?>
                                        <div class="bar-wrap">
                                            <div class="bar-fill yellow" style="width:<?php echo $item->payment_percentage ?? 0; ?>%"></div>
                                        </div>
									<?php endif; ?>
									<?php endif; ?>
                                </td>

                                <?php echo Columns::cells($columnsByAnchor, 'amount', $item); ?>

                                <!-- Created date + age -->
                                <td class="text-center">
									<?php if (!empty($item->created)) : ?>
										<?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC2')); ?>
                                        <span class="order-age d-block">
                                            <?php echo ($item->order_age_days ?? 0) . ' ' . Text::_('COM_ALFA_DAYS_AGO'); ?>
                                        </span>
									<?php endif; ?>
                                </td>

                                <?php echo Columns::cells($columnsByAnchor, 'date', $item); ?>

                                <!-- Payment method badge -->
                                <td class="text-center">
									<?php if (!empty($item->payment_method_name)) : ?>
                                        <span class="method-badge"
                                              style="color:<?php echo $this->escape($item->payment_color ?? '#000'); ?>;
                                                      background:<?php echo $this->escape($item->payment_bg_color ?? '#d1ecf1'); ?>">
                                            <?php echo $this->escape($item->payment_method_name); ?>
                                        </span>
									<?php else : ?>
                                        <span class="text-muted">-</span>
									<?php endif; ?>
                                </td>

                                <?php echo Columns::cells($columnsByAnchor, 'payment', $item); ?>

                                <!-- Shipments: each shipment row + fulfillment summary -->
                                <td class="text-center">
									<?php if (!empty($item->_shipments)) : ?>
										<?php foreach ($item->_shipments as $si => $sh) :
											$shStatus   = $sh->status ?? 'pending';
											$shStsClass = in_array($shStatus, ['shipped','delivered','pending','cancelled'], true)
												? $shStatus : 'pending';
											?>
                                            <div class="ship-row<?php echo $si > 0 ? ' mt-1' : ''; ?>">
                                                <span class="ship-id">#<?php echo (int) $sh->id; ?></span>
                                                <span class="ship-method">
                                                    <?php echo $this->escape(mb_strimwidth($sh->shipment_method_name ?? '-', 0, 18, '...')); ?>
                                                </span>
                                                <span class="sts sts-<?php echo $shStsClass; ?>"><?php echo ucfirst($shStatus); ?></span>
												<?php if (!empty($sh->tracking_number)) : ?>
                                                    <small class="d-block text-mono text-muted" style="font-size:0.72em">
														<?php echo $this->escape($sh->tracking_number); ?>
                                                    </small>
												<?php endif; ?>
                                            </div>
										<?php endforeach; ?>
									<?php else : ?>
                                        <span class="text-muted">-</span>
									<?php endif; ?>

									<?php if ($totalItems > 0) : ?>
                                        <div class="mt-1" style="border-top:1px solid #eee;padding-top:3px">
											<?php if ($fStatus === 'fulfilled') : ?>
                                                <span class="ful-badge ok">✓ <?php echo Text::_('COM_ALFA_FULFILLED'); ?></span>
											<?php elseif ($fStatus === 'partial') : ?>
                                                <span class="ful-badge warn"><?php echo $fulItems . '/' . $totalItems; ?></span>
                                                <div class="bar-wrap">
                                                    <div class="bar-fill green" style="width:<?php echo $item->fulfillment_percentage ?? 0; ?>%"></div>
                                                </div>
											<?php else : ?>
                                                <span class="ful-badge danger"><?php echo Text::_('COM_ALFA_UNFULFILLED'); ?></span>
											<?php endif; ?>
                                        </div>
									<?php endif; ?>
                                </td>

                                <?php echo Columns::cells($columnsByAnchor, 'shipment', $item); ?>

                                <!-- Order status: editable dropdown or read-only badge -->
                                <td class="text-center">
									<?php if ($canChange) : ?>
                                        <!-- Editable: styled <select> with AJAX on change -->
                                        <select class="alfa-status-select"
                                                data-order-id="<?php echo (int) $item->id; ?>"
                                                data-original="<?php echo $currentStatusId; ?>"
                                                style="background-color:<?php echo $this->escape($currentStatus->bg_color ?? '#f0f0f0'); ?>;
                                                        color:<?php echo $this->escape($currentStatus->color ?? '#000'); ?>;">
											<?php foreach ($orderStatuses as $osId => $os) :
												// Show active statuses + the current one (even if inactive).
												// Inactive statuses that are not current are skipped.
												$isActive  = (int) ($os->state ?? 1) === 1;
												$isCurrent = ($osId === $currentStatusId);
												if (!$isActive && !$isCurrent) continue;
												?>
                                                <option value="<?php echo (int) $osId; ?>"
                                                        data-color="<?php echo $this->escape($os->color ?? '#000'); ?>"
                                                        data-bg="<?php echo $this->escape($os->bg_color ?? '#f0f0f0'); ?>"
													<?php echo $isCurrent ? 'selected' : ''; ?>>
													<?php echo $this->escape($os->name); ?>
                                                </option>
											<?php endforeach; ?>
                                        </select>
									<?php else : ?>
                                        <!-- Read-only: static badge (no edit.state permission) -->
										<?php if ($currentStatus) : ?>
                                            <span class="method-badge"
                                                  style="color:<?php echo $this->escape($currentStatus->color ?? '#000'); ?>;
                                                          background:<?php echo $this->escape($currentStatus->bg_color ?? '#f0f0f0'); ?>;">
                                                <?php echo $this->escape($currentStatus->name); ?>
                                            </span>
										<?php else : ?>
                                            <span class="text-muted"><?php echo Text::_('COM_ALFA_UNKNOWN'); ?></span>
										<?php endif; ?>
									<?php endif; ?>
                                </td>

                                <?php echo Columns::cells($columnsByAnchor, 'status', $item); ?>
                                <?php echo Columns::cells($columnsByAnchor, Columns::END, $item); ?><?php echo Actions::cell($this->pluginRowActions ?? [], $i, $item); ?>

                            </tr>
                            <!-- End main row -->

                            <!-- Detail panel -->
                            <!-- Collapsible row: expands below the main row.      -->
                            <!-- Each section delegates to its own sub-template.   -->
                            <tr class="detail-row">
                                <td colspan="<?php echo $colspan; ?>" class="p-0">
                                    <div class="collapse" id="<?php echo $detailId; ?>" data-order-id="<?php echo (int) $item->id; ?>">
                                        <div class="dp">

                                            <!-- Products grid -->
											<?php echo $this->loadTemplate('products'); ?>

                                            <!-- Shipments + Payments side by side -->
											<?php if (!empty($item->_shipments) || !empty($item->_payments)) : ?>
                                                <div class="dp-cols">
													<?php if (!empty($item->_shipments)) : ?>
														<?php echo $this->loadTemplate('shipments'); ?>
													<?php endif; ?>
													<?php if (!empty($item->_payments)) : ?>
														<?php echo $this->loadTemplate('payments'); ?>
													<?php endif; ?>
                                                </div>
											<?php endif; ?>

                                            <!-- Discounts -->
											<?php if (!empty($item->_discounts)) : ?>
												<?php echo $this->loadTemplate('discounts'); ?>
											<?php endif; ?>

                                            <!-- Notes -->
											<?php if (!empty($item->customer_note) || !empty($item->note)) : ?>
												<?php echo $this->loadTemplate('notes'); ?>
											<?php endif; ?>

                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <!-- End detail panel -->

						<?php endforeach; ?>

					<?php endif; ?>

                    </tbody>
                </table>

                <?php echo $this->pagination->getListFooter(); ?>

				<?php echo $this->filterForm->renderControlFields(); ?>

            </div>
        </div>
    </div>

	<?php echo HTMLHelper::_('form.token'); ?>
</form>