<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * Order Activity Timeline
 *
 * Single data source: order_activity_log (unified event table)
 * Every event has a status snapshot — no separate status table needed.
 *
 * Data: $this->activityLog (from OrderModel::getOrderActivityLog())
 *
 * @package    Com_Alfa
 * @subpackage Administrator
 * @since      3.4.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

$log = $this->activityLog ?? [];

// ================================================================
// Event metadata: icons, colors per entity type
// ================================================================
$entityMeta = [
	'order'    => ['icon' => 'icon-file-alt', 'color' => 'secondary', 'label' => 'Order'],
	'item'     => ['icon' => 'icon-cube',     'color' => 'primary',   'label' => 'Items'],
	'payment'  => ['icon' => 'icon-credit',   'color' => 'success',   'label' => 'Payments'],
	'shipment' => ['icon' => 'icon-cubes',    'color' => 'info',      'label' => 'Shipments'],
];

// Action part → badge color
$actionColors = [
	'created'            => 'secondary',
	'edited'             => 'info',
	'added'              => 'success',
	'deleted'            => 'danger',
	'captured'           => 'success',
	'refunded'           => 'dark',
	'failed'             => 'danger',
	'tracking_updated'   => 'info',
	'delivered'          => 'success',
];
?>

<div class="row mt-3">
    <div class="col-12">

        <!-- Header + Filters -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">
                <span class="icon-clock me-2"></span>
				<?php echo Text::_('History'); ?>
            </h3>
            <div class="btn-group btn-group-sm flex-nowrap" role="group" id="tl-filter">
                <button type="button" class="btn btn-outline-secondary active" data-filter="all">
					<?php echo Text::_('All'); ?>
                </button>
				<?php foreach ($entityMeta as $entityKey => $meta) : ?>
                    <button type="button" class="btn btn-outline-<?php echo $meta['color']; ?>" data-filter="<?php echo $entityKey; ?>">
                        <span class="<?php echo $meta['icon']; ?>"></span> <?php echo Text::_($meta['label']); ?>
                    </button>
				<?php endforeach; ?>
            </div>
        </div>

		<?php if (empty($log)) : ?>
            <div class="alert alert-info">
				<?php echo Text::_('COM_ALFA_NO_HISTORY'); ?>
            </div>
		<?php else : ?>
            <div class="alfa-tl">
				<?php foreach ($log as $entry) :
					// Parse "entity.action" from event column
					$parts  = explode('.', $entry->event, 2);
					$entity = $parts[0] ?? 'order';
					$action = $parts[1] ?? '';
					$meta   = $entityMeta[$entity] ?? ['icon' => 'icon-info', 'color' => 'secondary'];
					$aColor = $actionColors[$action] ?? 'secondary';

					// Context JSON
					$context    = !empty($entry->context) ? json_decode($entry->context, true) : null;
					$hasContext = !empty($context);
					?>
                    <div class="alfa-tl-row" data-entity="<?php echo $this->escape($entity); ?>">

                        <!-- Marker -->
                        <div class="alfa-tl-marker bg-<?php echo $meta['color']; ?>">
                            <span class="<?php echo $meta['icon']; ?>"></span>
                        </div>

                        <!-- Card -->
                        <div class="alfa-tl-card">
                            <!-- Top: badges + status pill + timestamp -->
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
									<span class="badge bg-<?php echo $aColor; ?>">
										<?php echo ucfirst($this->escape(str_replace('_', ' ', $action))); ?>
									</span>
                                    <span class="badge text-<?php echo $meta['color']; ?> border border-<?php echo $meta['color']; ?>">
										<?php echo ucfirst($this->escape($entity)); ?>
									</span>
									<?php if (!empty($entry->status_name)) : ?>
                                        <span class="badge bg-light text-dark border ms-1" title="<?php echo Text::_('COM_ALFA_ORDER_STATUS_AT_TIME'); ?>">
											<?php echo $this->escape($entry->status_name); ?>
										</span>
									<?php endif; ?>
                                </div>
                                <small class="text-muted text-nowrap ms-3">
									<?php echo HTMLHelper::_('date', $entry->created, Text::_('DATE_FORMAT_LC2')); ?>
                                </small>
                            </div>

                            <!-- Summary -->
                            <div class="alfa-tl-summary mt-1">
								<?php echo $this->escape($entry->summary); ?>
                            </div>

                            <!-- Actor + expand -->
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <small class="text-muted">
									<?php if (!empty($entry->employee_name)) : ?>
                                        <span class="icon-user"></span>
										<?php echo $this->escape($entry->employee_name); ?>
									<?php endif; ?>
                                </small>
								<?php if ($hasContext) : ?>
                                    <button type="button"
                                            class="btn btn-sm btn-link p-0 text-muted alfa-tl-toggle"
                                            data-target="ctx-<?php echo (int) $entry->id; ?>">
										<?php echo Text::_('Details'); ?>
                                        <span class="icon-chevron-down"></span>
                                    </button>
								<?php endif; ?>
                            </div>

                            <!-- Expandable context -->
							<?php if ($hasContext) : ?>
                                <div class="alfa-tl-ctx" id="ctx-<?php echo (int) $entry->id; ?>" style="display:none;">
									<?php
									// Diff events (edited) have {field: {from, to}} structure
									// Snapshot events (added, deleted, created) have flat key-value
									$isDiff = ($action === 'edited');
									?>
									<?php if ($isDiff) : ?>
                                        <!-- Changes diff table -->
                                        <table class="table table-sm table-bordered mb-0 mt-2" style="font-size:.85em;">
                                            <thead>
                                            <tr><th><?php echo Text::_('COM_ALFA_FIELD'); ?></th><th><?php echo Text::_('COM_ALFA_BEFORE'); ?></th><th><?php echo Text::_('COM_ALFA_AFTER'); ?></th></tr>
                                            </thead>
                                            <tbody>
											<?php foreach ($context as $field => $diff) :
												if (!is_array($diff)) continue;
												$fromDisplay = $diff['from_name'] ?? $diff['from'] ?? '—';
												$toDisplay   = $diff['to_name'] ?? $diff['to'] ?? '—';
												if (is_array($fromDisplay)) $fromDisplay = json_encode($fromDisplay);
												if (is_array($toDisplay)) $toDisplay = json_encode($toDisplay);
												?>
                                                <tr>
                                                    <td class="fw-bold"><?php echo $this->escape($field); ?></td>
                                                    <td class="text-danger"><?php echo $this->escape($fromDisplay); ?></td>
                                                    <td class="text-success"><?php echo $this->escape($toDisplay); ?></td>
                                                </tr>
											<?php endforeach; ?>
                                            </tbody>
                                        </table>
									<?php else : ?>
                                        <!-- Generic: key-value list -->
                                        <div class="mt-2 p-2 bg-light rounded" style="font-size:.85em;">
											<?php foreach ($context as $key => $val) : ?>
                                                <div class="d-flex justify-content-between py-1 border-bottom">
                                                    <span class="fw-bold"><?php echo $this->escape($key); ?></span>
                                                    <span class="text-muted"><?php echo $this->escape(is_array($val) ? json_encode($val) : $val); ?></span>
                                                </div>
											<?php endforeach; ?>
                                        </div>
									<?php endif; ?>
                                </div>
							<?php endif; ?>
                        </div>
                    </div>
				<?php endforeach; ?>
            </div>
		<?php endif; ?>
    </div>
</div>

<!-- Styles -->
<style>
    .alfa-tl { position: relative; padding-left: 40px; }
    .alfa-tl::before { content:''; position:absolute; left:15px; top:0; bottom:0; width:2px; background:#dee2e6; }
    .alfa-tl-row { position: relative; padding-bottom: 20px; }
    .alfa-tl-row:last-child { padding-bottom: 0; }
    .alfa-tl-row[data-hidden="true"] { display: none; }
    .alfa-tl-marker {
        position:absolute; left:-40px; top:0; width:30px; height:30px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        color:#fff; font-size:12px; z-index:1; border:3px solid #fff; box-shadow:0 0 0 2px #dee2e6;
    }
    .alfa-tl-card {
        background:#fff; border:1px solid #e9ecef; border-radius:6px; padding:12px 16px;
        transition: border-color .15s, box-shadow .15s;
    }
    .alfa-tl-card:hover { border-color:#ced4da; box-shadow:0 1px 4px rgba(0,0,0,.06); }
    .alfa-tl-summary { font-size:.925em; color:#212529; }
</style>

<!-- JS: Filter + Expand -->
<script>
    (function() {
        'use strict';

        // Filter
        document.querySelectorAll('#tl-filter [data-filter]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var filter = this.getAttribute('data-filter');
                document.querySelectorAll('#tl-filter .btn').forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');

                document.querySelectorAll('.alfa-tl-row').forEach(function(row) {
                    var entity = row.getAttribute('data-entity');
                    row.setAttribute('data-hidden', filter !== 'all' && entity !== filter ? 'true' : 'false');
                });
            });
        });

        // Expand/collapse
        document.querySelectorAll('.alfa-tl-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var el = document.getElementById(this.getAttribute('data-target'));
                if (el) {
                    var open = el.style.display === 'none';
                    el.style.display = open ? '' : 'none';
                    var icon = this.querySelector('[class*="icon-chevron"]');
                    if (icon) icon.className = open ? 'icon-chevron-up' : 'icon-chevron-down';
                }
            });
        });
    })();
</script>