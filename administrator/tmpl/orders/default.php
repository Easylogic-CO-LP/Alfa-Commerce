<?php
/**
 * Orders List — Main Template
 *
 * Architecture: row and detail panel are inline here (straightforward,
 * no reuse). The 5 detail panel sections are delegated to sub-templates
 * because they have distinct logic and grow independently.
 *
 * Sub-template map:
 *   default_products.php  → Products grid
 *   default_shipments.php → Shipments list + action button placeholders
 *   default_payments.php  → Payments list + refund data + action placeholders
 *   default_discounts.php → Cart rules / discount codes
 *   default_notes.php     → Customer + internal notes
 *
 * Per-row data for sub-templates is passed via:
 *   $this->currentItem — the current order object (set once per loop iteration)
 *
 * @package    Com_Alfa
 * @subpackage Administrator.View.Orders
 * @version    8.0.0
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Language\Text;

// ── Web assets ───────────────────────────────────────────────────────
$wa = $this->getDocument()->getWebAssetManager();
$wa->useStyle('com_alfa.admin')
	->useScript('com_alfa.admin')
	->usePreset('com_alfa.notifications')
	->useStyle('com_alfa.admin.orders-list')
	->useScript('com_alfa.admin.orders-list')
	->useScript('com_alfa.order-actions');

Text::script('JCLOSE'); //used by actions modal popups

// NOTE: table.columns deliberately NOT loaded — it adds "X/X Columns"
// dropdown to ALL <table> elements including inner detail panel tables.

// Loads Bootstrap UMD bundle → creates window.bootstrap.
// Required for Alfa.showActionModal → bootstrap.Modal.getInstance().
// The edit view gets this via HTMLHelper::_('bootstrap.tooltip').
// The list view has no tooltips that trigger it implicitly.
HTMLHelper::_('bootstrap.modal');

// ── View-level variables ─────────────────────────────────────────────
$listOrder     = $this->escape($this->state->get('list.ordering'));
$listDirn      = $this->escape($this->state->get('list.direction'));
$user          = $this->getCurrentUser();
$canEdit       = $user->authorise('core.edit',       'com_alfa');
$canCheckin    = $user->authorise('core.manage',      'com_alfa');
$canChange     = $user->authorise('core.edit.state',  'com_alfa');
$orderStatuses = $this->orderStatuses;
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&view=orders'); ?>"
      method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">

				<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
                <div class="clearfix"></div>

                <table class="table table-striped table-hover" id="orderList">

                    <!-- ════════════════════════════════════════════ -->
                    <!--  HEADER                                      -->
                    <!-- ════════════════════════════════════════════ -->
                    <thead>
                    <tr>
                        <th style="width:1%"  class="text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                        <th style="width:1%"  class="text-center"></th>
                        <th style="width:4%"  class="text-center"><?php echo HTMLHelper::_('searchtools.sort', 'ID', 'a.id', $listDirn, $listOrder); ?></th>
                        <th><?php echo Text::_('COM_ALFA_CUSTOMER'); ?></th>
                        <th style="width:12%" class="text-center"><?php echo Text::_('COM_ALFA_AMOUNT'); ?></th>
                        <th style="width:10%" class="text-center"><?php echo HTMLHelper::_('searchtools.sort', Text::_('COM_ALFA_DATE'), 'a.created', $listDirn, $listOrder); ?></th>
                        <th style="width:8%"  class="text-center"><?php echo Text::_('COM_ALFA_PAYMENT'); ?></th>
                        <th style="width:16%" class="text-center"><?php echo Text::_('COM_ALFA_SHIPMENT'); ?></th>
                        <th style="width:8%"  class="text-center"><?php echo Text::_('COM_ALFA_STATUS'); ?></th>
                    </tr>
                    </thead>

                    <!-- ════════════════════════════════════════════ -->
                    <!--  FOOTER (pagination)                         -->
                    <!-- ════════════════════════════════════════════ -->
                    <tfoot>
                    <tr>
                        <td colspan="9"><?php echo $this->pagination->getListFooter(); ?></td>
                    </tr>
                    </tfoot>

                    <!-- ════════════════════════════════════════════ -->
                    <!--  BODY                                        -->
                    <!-- ════════════════════════════════════════════ -->
                    <tbody>

					<?php if (empty($this->items)) : ?>
                        <tr>
                            <td colspan="9" class="text-center">
                                <p class="mt-4 mb-4"><?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?></p>
                            </td>
                        </tr>
					<?php else : ?>

						<?php foreach ($this->items as $i => $item) :

							// ── Per-row computed values ───────────
							$detailId        = 'od-' . (int) $item->id;
							$currentStatusId = (int) ($item->id_order_status ?? 0);
							$currentStatus   = $orderStatuses[$currentStatusId] ?? null;
							$pSts            = $item->payment_status ?? 'unpaid';
							$fStatus         = $item->fulfillment_status ?? 'unfulfilled';
							$totalItems      = (int) ($item->total_items ?? 0);
							$fulItems        = (int) ($item->fulfilled_items ?? 0);

							// Pass item to section sub-templates via $this->currentItem.
							// loadTemplate() shares the full $this scope so sub-templates
							// read it directly — no extract() or globals needed.
							$this->currentItem = $item;
							?>

                            <!-- ════ MAIN ROW ════════════════════════════════════ -->
                            <tr>

                                <!-- Checkbox -->
                                <td class="text-center">
									<?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
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
                                        <a href="<?php echo Route::_('index.php?option=com_alfa&view=order&layout=edit&id=' . (int) $item->id); ?>"
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

                                <!-- Customer name + email -->
                                <td>
									<?php if (!empty($item->customer_name)) : ?>
                                        <div class="customer-name"><?php echo $this->escape($item->customer_name); ?></div>
									<?php endif; ?>
									<?php if (!empty($item->customer_email)) : ?>
                                        <div class="customer-email"><?php echo $this->escape($item->customer_email); ?></div>
									<?php endif; ?>
									<?php if (empty($item->customer_name) && empty($item->customer_email)) : ?>
                                        <span class="text-muted"><?php echo Text::_('COM_ALFA_GUEST'); ?></span>
									<?php endif; ?>
                                </td>

                                <!-- Amount: paid / total + payment status badge -->
                                <td class="text-center">
									<?php
									// Tooltip: breakdown of products + shipping − discounts
									$ttParts = [];
									if (isset($item->items_total_money))
										$ttParts[] = Text::_('COM_ALFA_PRODUCTS') . ': ' . $item->items_total_money->format();
									if ((float) ($item->shipping_total ?? 0) > 0 && isset($item->shipping_total_money))
										$ttParts[] = Text::_('COM_ALFA_SHIPPING') . ': ' . $item->shipping_total_money->format();
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
	                                            default:        echo Text::_('COM_ALFA_UNPAID');
                                            } ?>
                                        </span>
                                    </div>

									<?php if ($pSts === 'partial') : ?>
                                        <div class="bar-wrap">
                                            <div class="bar-fill yellow" style="width:<?php echo $item->payment_percentage ?? 0; ?>%"></div>
                                        </div>
									<?php endif; ?>
                                </td>

                                <!-- Created date + age -->
                                <td class="text-center">
									<?php if (!empty($item->created)) : ?>
										<?php echo HTMLHelper::_('date', $item->created, 'Y-m-d H:i'); ?>
                                        <span class="order-age d-block">
                                            <?php echo ($item->order_age_days ?? 0) . ' ' . Text::_('COM_ALFA_DAYS_AGO'); ?>
                                        </span>
									<?php endif; ?>
                                </td>

                                <!-- Payment method badge -->
                                <td class="text-center">
									<?php if (!empty($item->payment_method_name)) : ?>
                                        <span class="method-badge"
                                              style="color:<?php echo $this->escape($item->payment_color ?? '#000'); ?>;
                                                      background:<?php echo $this->escape($item->payment_bg_color ?? '#d1ecf1'); ?>">
                                            <?php echo $this->escape($item->payment_method_name); ?>
                                        </span>
									<?php else : ?>
                                        <span class="text-muted">—</span>
									<?php endif; ?>
                                </td>

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
                                                    <?php echo $this->escape(mb_strimwidth($sh->shipment_method_name ?? '—', 0, 18, '…')); ?>
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
                                        <span class="text-muted">—</span>
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

                                <!-- Order status: editable dropdown or read-only badge -->
                                <td class="text-center">
									<?php if ($canChange) : ?>
                                        <!-- Editable: styled <select> with AJAX on change -->
                                        <select class="alfa-status-select"
                                                data-order-id="<?php echo (int) $item->id; ?>"
                                                data-original="<?php echo $currentStatusId; ?>"
                                                style="background-color:<?php echo $currentStatus->bg_color ?? '#f0f0f0'; ?>;
                                                        color:<?php echo $currentStatus->color ?? '#000'; ?>;">
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
                                                  style="color:<?php echo $currentStatus->color ?? '#000'; ?>;
                                                          background:<?php echo $currentStatus->bg_color ?? '#f0f0f0'; ?>;">
                                                <?php echo $this->escape($currentStatus->name); ?>
                                            </span>
										<?php else : ?>
                                            <span class="text-muted"><?php echo Text::_('COM_ALFA_UNKNOWN'); ?></span>
										<?php endif; ?>
									<?php endif; ?>
                                </td>

                            </tr>
                            <!-- ════ END MAIN ROW ════════════════════════════════ -->

                            <!-- ════ DETAIL PANEL ════════════════════════════════ -->
                            <!-- Collapsible row — expands below the main row.     -->
                            <!-- Each section delegates to its own sub-template.   -->
                            <tr class="detail-row">
                                <td colspan="9" class="p-0">
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
                            <!-- ════ END DETAIL PANEL ════════════════════════════ -->

						<?php endforeach; ?>

					<?php endif; ?>

                    </tbody>
                </table>

				<?php echo $this->filterForm->renderControlFields(); ?>

            </div>
        </div>
    </div>

	<?php echo HTMLHelper::_('form.token'); ?>
</form>