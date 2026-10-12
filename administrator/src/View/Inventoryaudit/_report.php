<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Inventory audit report.
 *
 * Rendered by {@see HtmlView::display()} through a fixed-path include, not through the
 * overridable template system, so a template override cannot hide or fake a finding. The
 * page template only echoes the string this produces.
 *
 * One table per category that has findings: the inconsistencies first, then, under their own
 * heading, the classifications (order lines listed for information, which never make the
 * catalogue unclean). Levels, detail names and identity reasons are shown in words; a listed
 * detail (the stock records of a product, the open order lines on it) one labelled line per
 * entry, and a hold's own facts as three labelled lines. Rows whose category offers the repair
 * carry it once per product and table: a POST form with the product id and the form token. Rows
 * of stock held on a stock identity kept for order history carry the audited write-off once per
 * stock identity and table: the product and combination ids, a required reason and the token.
 * A stock identity whose stock is still reserved, awaits a stock effect or is still needed by
 * open order lines is shown those instead of the write-off, which would be refused. An order
 * line whose stock identity no longer exists carries one form per way it can be resolved
 * (bound to a combination of its product, or to the product itself), each with its own
 * required reason; a line sold on the product itself carries the form that records its
 * historical parent identity until one is recorded. A line with a recorded resolution shows it
 * with every resolution it took the place of, and, while no stock has moved for the line on the
 * stock identity it records, the same choices as correction forms naming that resolution.
 * A stock record whose open reservations hold more than it has on hand lists them in the
 * order they are served, each with what the stock on hand covers and what waits, and
 * carries, for each order with a reservation there that waits, a POST form that serves
 * that order first: the order id, a required reason and the token.
 * Nothing here changes data; every action is a controller task that checks the permission
 * itself.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Inventoryaudit\HtmlView $this
 */

use Alfa\Component\Alfa\Administrator\Model\InventoryauditModel;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

$scanned = $this->report['scanned'];

// The inconsistencies, then the classifications; only the first make the catalogue unclean.
$groups = ['defects' => $this->report['findings'], 'classifications' => $this->report['classifications']];
$total = array_sum(array_map('count', $groups['defects']));
$classified = array_sum(array_map('count', $groups['classifications']));

$reconcileUrl = Route::_(url: 'index.php?option=com_alfa&task=inventoryaudit.reconcile');
$writeOffUrl = Route::_(url: 'index.php?option=com_alfa&task=inventoryaudit.writeOff');
$resolveUrl = Route::_(url: 'index.php?option=com_alfa&task=inventoryaudit.resolveLine');
$priorityUrl = Route::_(url: 'index.php?option=com_alfa&task=inventoryaudit.prioritise');

// A quantity as the stock screens print it; a figure the finding does not carry as a dash.
$figure = static fn ($value): string => $value === null
    ? '-'
    : InventoryAvailabilityService::formatQuantity(value: (float) $value);

// A record reference; 0 or null (the product itself, a unit-level finding) as a dash.
$reference = static fn (?int $id): string => (int) $id > 0 ? '#' . (int) $id : '-';

// A finding's level (product, combination, product total, order line) in words.
$level = static fn (string $unit): string => Text::_('COM_ALFA_INVENTORY_AUDIT_UNIT_' . strtoupper($unit));

// A detail's label in words.
$detailLabel = static fn (string $name): string => Text::_('COM_ALFA_INVENTORY_AUDIT_DETAIL_' . strtoupper($name));

// A detail value: record ids as references, an identity reason in words, flags as yes/no,
// counts as they are and quantities as the stock screens print them.
$detailValue = static function (string $name, $value) use ($figure, $reference): string {
    if (\is_bool($value)) {
        return Text::_($value ? 'JYES' : 'JNO');
    }

    if (str_ends_with($name, '_id')) {
        return $reference($value === null ? null : (int) $value);
    }

    if ($name === 'reason' && \is_string($value)) {
        return Text::_('COM_ALFA_INVENTORY_AUDIT_REASON_' . strtoupper($value));
    }

    if (\is_int($value)) {
        return (string) $value;
    }

    if (\is_float($value) || $value === null) {
        return $figure($value);
    }

    return (string) $value;
};

// One entry of a listed detail (a stock record, an open order line): its fields as labelled values on one line.
$detailEntry = static function (string $name, $entry) use ($detailLabel, $detailValue): string {
    if (!\is_array($entry) && !\is_object($entry)) {
        return $detailValue($name, $entry);
    }

    $parts = [];

    foreach ((array) $entry as $field => $value) {
        $parts[] = $detailLabel((string) $field) . ': ' . $detailValue((string) $field, $value);
    }

    return implode(', ', $parts);
};

// A recorded order-line resolution, the effective one or one it took the place of: what it
// chose (the combination, when it names one), its number, when (in the operator's time zone)
// and by whom, and the operator's reason exactly as typed.
$recordedText = static function (array $recorded, int $itemId) use ($reference): string {
    $bind = (string) $recorded['action'] === InventoryService::RESOLVE_BIND_UNIT;
    $chosen = Text::_($bind ? 'COM_ALFA_INVENTORY_LINE_RESOLVE_BIND' : 'COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT');

    if ($bind && (int) ($recorded['resolved_combination_id'] ?? 0) > 0) {
        $chosen .= ': ' . Text::sprintf('COM_ALFA_INVENTORY_LINE_UNIT_COMBINATION', (int) $recorded['resolved_combination_id'], $itemId);
    }

    return $chosen . ' ' . $reference((int) ($recorded['resolution_id'] ?? $recorded['id']))
        . ', ' . HTMLHelper::_('date', (string) $recorded['created'], Text::_('DATE_FORMAT_LC6'))
        . ', ' . $reference((int) $recorded['created_by'])
        . ' - ' . Text::_('COM_ALFA_INVENTORY_AUDIT_DETAIL_REASON') . ': ' . (string) $recorded['reason'];
};

// An integrity hold's own facts, shown as three labelled lines instead of one per fact: since
// when and by whom, the command that began it (and the order line it recorded), and the stock
// and available figures the unit showed then.
$holdFacts = ['hold_id', 'created', 'created_by', 'operation_id', 'source_line_id', 'stock_figure', 'available_figure'];
$holdLines = static function (array $detail) use ($figure, $reference, $detailLabel): array {
    $operation = (string) $detail['operation_id'];

    if ((int) $detail['source_line_id'] > 0) {
        $operation .= ', ' . $detailLabel('line_id') . ' ' . $reference((int) $detail['source_line_id']);
    }

    return [
        'COM_ALFA_INVENTORY_AUDIT_DETAIL_HOLD_SINCE' => HTMLHelper::_('date', (string) $detail['created'], Text::_('DATE_FORMAT_LC6'))
            . ', ' . $reference((int) $detail['created_by']),
        'COM_ALFA_INVENTORY_AUDIT_DETAIL_HOLD_OPERATION' => $operation,
        'COM_ALFA_INVENTORY_AUDIT_DETAIL_HOLD_FIGURES' => $figure($detail['stock_figure']) . ' / ' . $figure($detail['available_figure']),
    ];
};
?>
<div class="com-alfa-inventory-audit">
    <p class="text-muted">
        <?php echo Text::sprintf('COM_ALFA_INVENTORY_AUDIT_SCANNED', (int) $scanned['units'], (int) $scanned['rows']); ?>
    </p>

    <?php if ($total === 0) : ?>
        <div class="alert alert-success" role="status">
            <span class="icon-checkmark-circle" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_CLEAN'); ?>
        </div>
    <?php endif; ?>

    <?php foreach ($groups as $group => $categories) : ?>
    <?php if ($group === 'classifications' && $classified > 0) : ?>
        <h2 class="h3 mt-4 mb-3" id="inventory-audit-classifications"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_CLASSIFICATIONS'); ?></h2>
    <?php endif; ?>

    <?php foreach ($categories as $category => $rows) : ?>
        <?php
        if ($rows === []) {
            continue;
        }

        $category = (string) $category;
        $action = $this->rowAction(category: $category);
        $offered = [];
        $langKey = 'COM_ALFA_INVENTORY_AUDIT_CATEGORY_' . strtoupper($category);

        // What still claims the stock of each stock identity offered for write-off, and the open
        // order lines that still rely on it to deduct their stock. The write-off is refused while
        // any reserved quantity, open reservation, pending stock effect or such line remains, so
        // such an identity is shown them instead of a form that could only fail. Every held
        // record of the identity is a row here, so its reserved quantities add up; the counts
        // are the identity's own, repeated on each of its rows.
        $claims = [];
        $needed = [];

        if ($action === 'writeoff') {
            foreach ($rows as $finding) {
                $unitKey = (int) $finding['item_id'] . '|' . (int) $finding['combination_id'];
                $claimed = $claims[$unitKey] ?? ['reserved' => 0.0, 'reservations' => 0, 'pending' => 0];

                $claims[$unitKey] = [
                    'reserved' => $claimed['reserved'] + (float) ($finding['detail']['reserved'] ?? 0),
                    'reservations' => max($claimed['reservations'], (int) ($finding['detail']['active_reservations'] ?? 0)),
                    'pending' => max($claimed['pending'], (int) ($finding['detail']['pending_effects'] ?? 0)),
                ];
                $needed[$unitKey] = $this->openLines(finding: $finding);
            }
        }
        ?>
        <section class="card mb-4" data-audit-category="<?php echo $this->escape(var: $category); ?>">
            <div class="card-header">
                <h2 class="h4 mb-1"><?php echo Text::_($langKey); ?> <span class="badge <?php echo $group === 'classifications' ? 'bg-info text-dark' : 'bg-danger'; ?>"><?php echo \count($rows); ?></span></h2>
                <p class="small text-muted mb-0"><?php echo Text::_($langKey . '_DESC'); ?></p>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0" id="inventory-audit-<?php echo $this->escape(var: $category); ?>">
                    <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_ITEM'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_COMBINATION'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_LOCATION'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_UNIT'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_PROJECTION'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_CANONICAL'); ?></th>
                        <th scope="col" class="text-end"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_DRIFT'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_DETAIL'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_ACTION'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $finding) : ?>
                        <?php
                        $itemId = (int) $finding['item_id'];
                        $combinationId = (int) $finding['combination_id'];
                        // One form per table: the write-off works on one stock identity, every other
                        // action on the whole product.
                        $unitKey = $itemId . '|' . $combinationId;
                        $offerKey = $action === 'writeoff' ? $unitKey : (string) $itemId;
                        $firstOfItem = $itemId > 0 && !isset($offered[$offerKey]);
                        $offered[$offerKey] = true;
                        $claim = $claims[$unitKey] ?? null;
                        $claimed = $claim !== null && (
                            $claim['reserved'] > InventoryAvailabilityService::QUANTITY_TOLERANCE
                            || $claim['reservations'] > 0
                            || $claim['pending'] > 0
                        );
                        $openLines = $needed[$unitKey] ?? [];
                        // An order line's forms name the line; a line finding is one line. Its form
                        // depends on what is recorded for it (see HtmlView::lineAction()).
                        $lineRow = \in_array($action, ['resolve', 'classify', 'correct'], true);
                        $lineId = $lineRow ? (int) $finding['detail']['line_id'] : 0;
                        $lineAction = $lineRow ? $this->lineAction(finding: $finding) : '';
                        ?>
                        <tr data-item-id="<?php echo $itemId; ?>" data-finding-key="<?php echo $this->escape(var: (string) $finding['key']); ?>">
                            <td><?php echo $reference($itemId); ?></td>
                            <td><?php echo $reference($finding['combination_id']); ?></td>
                            <td><?php echo $reference($finding['location_id']); ?></td>
                            <td><?php echo $this->escape(var: $level((string) $finding['unit'])); ?></td>
                            <td class="text-end"><?php echo $figure($finding['projection']); ?></td>
                            <td class="text-end"><?php echo $figure($finding['canonical']); ?></td>
                            <td class="text-end"><?php echo $figure($finding['drift']); ?></td>
                            <td class="small">
                                <?php if ($category === 'integrity_hold') : ?>
                                    <div data-integrity-hold="<?php echo (int) $finding['detail']['hold_id']; ?>">
                                        <?php foreach ($holdLines($finding['detail']) as $holdKey => $holdValue) : ?>
                                            <div><span class="text-muted"><?php echo Text::_($holdKey); ?>:</span> <?php echo $this->escape(var: $holdValue); ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ($finding['detail'] as $name => $value) : ?>
                                    <?php
                                    $name = (string) $name;

                                    // The stock identities a line can be resolved to are the choices of its forms;
                                    // a hold's own facts are shown above.
                                    if ($name === 'candidates' || $name === 'parent'
                                        || ($category === 'integrity_hold' && \in_array($name, $holdFacts, true))) {
                                        continue;
                                    }
                                    ?>
                                    <?php if ($name === 'recorded') : ?>
                                        <div data-recorded-resolution="<?php echo $value === null ? 0 : (int) $value['resolution_id']; ?>">
                                            <span class="text-muted"><?php echo $this->escape(var: $detailLabel($name)); ?>:</span>
                                            <?php echo $this->escape(var: $value === null ? Text::_('JNO') : $recordedText($value, $itemId)); ?>
                                        </div>
                                        <?php if ($value !== null) : ?>
                                            <div><span class="text-muted"><?php echo $this->escape(var: $detailLabel('revision')); ?>:</span> <?php echo (int) $value['revision']; ?></div>
                                            <div><span class="text-muted"><?php echo $this->escape(var: $detailLabel('consumed')); ?>:</span> <?php echo $this->escape(var: $detailValue('consumed', (bool) $value['consumed'])); ?></div>
                                            <?php if ($value['superseded'] !== []) : ?>
                                                <div><span class="text-muted"><?php echo $this->escape(var: $detailLabel('superseded')); ?>:</span></div>
                                                <?php foreach ($value['superseded'] as $earlier) : ?>
                                                    <div class="ms-2" data-superseded-resolution="<?php echo (int) $earlier['id']; ?>">
                                                        <?php echo $this->escape(var: $recordedText((array) $earlier, $itemId)); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php elseif (\is_array($value)) : ?>
                                        <div><span class="text-muted"><?php echo $this->escape(var: $detailLabel($name)); ?>:</span><?php echo $value === [] ? ' -' : ''; ?></div>
                                        <?php foreach ($value as $entry) : ?>
                                            <div class="ms-2"><?php echo $this->escape(var: $detailEntry($name, $entry)); ?></div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <div><span class="text-muted"><?php echo $this->escape(var: $detailLabel($name)); ?>:</span> <?php echo $this->escape(var: $detailValue($name, $value)); ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($action === 'writeoff') : ?>
                                    <span class="badge bg-warning text-dark"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_EXCLUDED'); ?></span>
                                <?php endif; ?>

                                <?php if ($action === 'reconcile' && $firstOfItem) : ?>
                                    <form action="<?php echo $reconcileUrl; ?>" method="post" class="mt-1">
                                        <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_REPAIR_BTN'); ?>
                                        </button>
                                        <div class="form-text"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_REPAIR_HINT'); ?></div>
                                    </form>
                                <?php elseif ($action === 'writeoff' && $firstOfItem && ($claimed || $openLines !== [])) : ?>
                                    <?php $unitLabel = InventoryUnitException::labelFor(itemId: $itemId, combinationId: $combinationId); ?>
                                    <?php if ($claimed) : ?>
                                        <div class="small text-danger mt-2" data-writeoff-refused="<?php echo $itemId; ?>">
                                            <?php echo $this->escape(var: Text::sprintf(
                                                'COM_ALFA_INVENTORY_AUDIT_WRITEOFF_REFUSED_CLAIMS',
                                                $unitLabel,
                                                $figure($claim['reserved']),
                                                $claim['reservations'],
                                                $claim['pending'],
                                            )); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($openLines !== []) : ?>
                                        <div class="small text-danger mt-2" data-writeoff-open-lines="<?php echo $itemId; ?>">
                                            <?php echo $this->escape(var: Text::sprintf(
                                                'COM_ALFA_INVENTORY_AUDIT_WRITEOFF_REFUSED_OPEN_LINES',
                                                $unitLabel,
                                                \count($openLines),
                                                InventoryauditModel::openLinesText(lines: $openLines),
                                            )); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($action === 'writeoff' && $firstOfItem) : ?>
                                    <form action="<?php echo $writeOffUrl; ?>" method="post" class="mt-2" data-writeoff-unit="<?php echo $itemId . '-' . $combinationId; ?>">
                                        <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                                        <input type="hidden" name="combination_id" value="<?php echo $combinationId; ?>">
                                        <label class="form-label small" for="inventory-audit-reason-<?php echo $itemId . '-' . $combinationId; ?>">
                                            <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_WRITEOFF_REASON'); ?>
                                        </label>
                                        <input type="text" class="form-control form-control-sm mb-1" name="reason"
                                               id="inventory-audit-reason-<?php echo $itemId . '-' . $combinationId; ?>" required maxlength="255">
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_WRITEOFF_BTN'); ?>
                                        </button>
                                        <div class="form-text"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_WRITEOFF_HINT'); ?></div>
                                    </form>
                                <?php elseif ($action === 'tracking_hint' && $firstOfItem) : ?>
                                    <div class="small"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_UNTRACKED_HINT'); ?></div>
                                    <a class="small" href="<?php echo Route::_(url: 'index.php?option=com_alfa&task=item.edit&id=' . $itemId); ?>">
                                        <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_HEADING_ITEM'); ?> <?php echo $reference($itemId); ?>
                                    </a>
                                <?php elseif ($lineAction === 'resolve') : ?>
                                    <?php
                                    // Nothing is recorded for the line yet; once a resolution is, the detail column
                                    // shows it and the correction takes the place of these forms.
                                    $targets = $this->resolveTargets(finding: $finding);
                                    ?>
                                    <?php if ($targets['combinations'] === [] && !$targets['parent']) : ?>
                                        <div class="small text-muted" data-resolve-none="<?php echo $lineId; ?>">
                                            <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_RESOLVE_NO_TARGET'); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($targets['combinations'] !== []) : ?>
                                        <form action="<?php echo $resolveUrl; ?>" method="post" class="mt-1" data-resolve-bind="<?php echo $lineId; ?>">
                                            <fieldset>
                                                <legend class="small fw-bold mb-1"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_BIND'); ?></legend>
                                                <div class="form-text mt-0 mb-1"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_BIND_HINT'); ?></div>
                                                <div class="small text-muted"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_RESOLVE_TARGET'); ?></div>
                                                <?php foreach ($targets['combinations'] as $target) : ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="combination_id" value="<?php echo $target['id']; ?>"
                                                               id="inventory-audit-bind-<?php echo $lineId; ?>-<?php echo $target['id']; ?>" required>
                                                        <label class="form-check-label small" for="inventory-audit-bind-<?php echo $lineId; ?>-<?php echo $target['id']; ?>">
                                                            <?php echo $this->escape(var: $target['label']); ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </fieldset>
                                            <input type="hidden" name="line_id" value="<?php echo $lineId; ?>">
                                            <input type="hidden" name="action" value="<?php echo InventoryService::RESOLVE_BIND_UNIT; ?>">
                                            <label class="form-label small mt-1" for="inventory-audit-bind-reason-<?php echo $lineId; ?>">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_REASON'); ?>
                                            </label>
                                            <textarea class="form-control form-control-sm mb-1" name="reason" rows="2"
                                                      id="inventory-audit-bind-reason-<?php echo $lineId; ?>" required maxlength="255"></textarea>
                                            <?php echo HTMLHelper::_('form.token'); ?>
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_RESOLVE_BTN'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($targets['parent']) : ?>
                                        <form action="<?php echo $resolveUrl; ?>" method="post" class="mt-2" data-resolve-parent="<?php echo $lineId; ?>">
                                            <div class="small fw-bold"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT'); ?></div>
                                            <div class="form-text mt-0 mb-1"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT_HINT'); ?></div>
                                            <input type="hidden" name="line_id" value="<?php echo $lineId; ?>">
                                            <input type="hidden" name="action" value="<?php echo InventoryService::RESOLVE_HISTORICAL_PARENT; ?>">
                                            <label class="form-label small" for="inventory-audit-parent-reason-<?php echo $lineId; ?>">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_REASON'); ?>
                                            </label>
                                            <textarea class="form-control form-control-sm mb-1" name="reason" rows="2"
                                                      id="inventory-audit-parent-reason-<?php echo $lineId; ?>" required maxlength="255"></textarea>
                                            <?php echo HTMLHelper::_('form.token'); ?>
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_RESOLVE_BTN'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($lineAction === 'classify') : ?>
                                    <form action="<?php echo $resolveUrl; ?>" method="post" class="mt-1" data-classify-line="<?php echo $lineId; ?>">
                                        <div class="form-text mt-0 mb-1"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT_HINT'); ?></div>
                                        <input type="hidden" name="line_id" value="<?php echo $lineId; ?>">
                                        <input type="hidden" name="action" value="<?php echo InventoryService::RESOLVE_HISTORICAL_PARENT; ?>">
                                        <label class="form-label small" for="inventory-audit-classify-reason-<?php echo $lineId; ?>">
                                            <?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_REASON'); ?>
                                        </label>
                                        <textarea class="form-control form-control-sm mb-1" name="reason" rows="2"
                                                  id="inventory-audit-classify-reason-<?php echo $lineId; ?>" required maxlength="255"></textarea>
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                        <button type="submit" class="btn btn-sm btn-secondary">
                                            <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_CLASSIFY_BTN'); ?>
                                        </button>
                                    </form>
                                <?php elseif ($lineAction === 'correct') : ?>
                                    <?php
                                    // Each form names the effective resolution it takes the place of, so one recorded
                                    // or corrected after this page was loaded is refused, never overwritten unseen.
                                    $targets = $this->resolveTargets(finding: $finding);
                                    $supersedes = (int) $finding['detail']['recorded']['resolution_id'];
                                    ?>
                                    <div class="form-text mt-1" data-correct-line="<?php echo $lineId; ?>"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_CORRECT_HINT'); ?></div>
                                    <?php if ($targets['combinations'] !== []) : ?>
                                        <form action="<?php echo $resolveUrl; ?>" method="post" class="mt-1" data-correct-bind="<?php echo $lineId; ?>">
                                            <fieldset>
                                                <legend class="small fw-bold mb-1"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_BIND'); ?></legend>
                                                <div class="form-text mt-0 mb-1"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_BIND_HINT'); ?></div>
                                                <div class="small text-muted"><?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_RESOLVE_TARGET'); ?></div>
                                                <?php foreach ($targets['combinations'] as $target) : ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="combination_id" value="<?php echo $target['id']; ?>"
                                                               id="inventory-audit-correct-bind-<?php echo $lineId; ?>-<?php echo $target['id']; ?>" required>
                                                        <label class="form-check-label small" for="inventory-audit-correct-bind-<?php echo $lineId; ?>-<?php echo $target['id']; ?>">
                                                            <?php echo $this->escape(var: $target['label']); ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </fieldset>
                                            <input type="hidden" name="line_id" value="<?php echo $lineId; ?>">
                                            <input type="hidden" name="action" value="<?php echo InventoryService::RESOLVE_BIND_UNIT; ?>">
                                            <input type="hidden" name="supersedes" value="<?php echo $supersedes; ?>">
                                            <label class="form-label small mt-1" for="inventory-audit-correct-bind-reason-<?php echo $lineId; ?>">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_REASON'); ?>
                                            </label>
                                            <textarea class="form-control form-control-sm mb-1" name="reason" rows="2"
                                                      id="inventory-audit-correct-bind-reason-<?php echo $lineId; ?>" required maxlength="255"></textarea>
                                            <?php echo HTMLHelper::_('form.token'); ?>
                                            <button type="submit" class="btn btn-sm btn-secondary">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_CORRECT_BTN'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($targets['parent']) : ?>
                                        <form action="<?php echo $resolveUrl; ?>" method="post" class="mt-2" data-correct-parent="<?php echo $lineId; ?>">
                                            <div class="small fw-bold"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT'); ?></div>
                                            <div class="form-text mt-0 mb-1"><?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_PARENT_HINT'); ?></div>
                                            <input type="hidden" name="line_id" value="<?php echo $lineId; ?>">
                                            <input type="hidden" name="action" value="<?php echo InventoryService::RESOLVE_HISTORICAL_PARENT; ?>">
                                            <input type="hidden" name="supersedes" value="<?php echo $supersedes; ?>">
                                            <label class="form-label small" for="inventory-audit-correct-parent-reason-<?php echo $lineId; ?>">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_LINE_RESOLVE_REASON'); ?>
                                            </label>
                                            <textarea class="form-control form-control-sm mb-1" name="reason" rows="2"
                                                      id="inventory-audit-correct-parent-reason-<?php echo $lineId; ?>" required maxlength="255"></textarea>
                                            <?php echo HTMLHelper::_('form.token'); ?>
                                            <button type="submit" class="btn btn-sm btn-secondary">
                                                <?php echo Text::_('COM_ALFA_INVENTORY_AUDIT_CORRECT_BTN'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($action === 'prioritise') : ?>
                                    <?php
                                    // One form per order with a reservation here that waits for stock; serving it
                                    // first moves its open reservations ahead on every stock record they stand on.
                                    $recordId = (int) $finding['detail']['stock_id'];
                                    $waitingOrders = $this->waitingOrders(finding: $finding);
                                    ?>
                                    <?php foreach ($waitingOrders as $waitingOrder) : ?>
                                        <form action="<?php echo $priorityUrl; ?>" method="post" class="mt-2" data-prioritise-order="<?php echo $waitingOrder; ?>">
                                            <input type="hidden" name="order_id" value="<?php echo $waitingOrder; ?>">
                                            <label class="form-label small" for="inventory-audit-prioritise-reason-<?php echo $recordId . '-' . $waitingOrder; ?>">
                                                <?php echo Text::sprintf('COM_ALFA_INVENTORY_AUDIT_PRIORITISE_REASON', $waitingOrder); ?>
                                            </label>
                                            <textarea class="form-control form-control-sm mb-1" name="reason" rows="2"
                                                      id="inventory-audit-prioritise-reason-<?php echo $recordId . '-' . $waitingOrder; ?>" required maxlength="255"></textarea>
                                            <?php echo HTMLHelper::_('form.token'); ?>
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <?php echo Text::_(string: 'COM_ALFA_INVENTORY_AUDIT_PRIORITISE_BTN'); ?>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                    <?php if ($waitingOrders !== []) : ?>
                                        <div class="form-text"><?php echo Text::_(string: 'COM_ALFA_INVENTORY_AUDIT_PRIORITISE_HINT'); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    <?php endforeach; ?>
</div>
