<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Price check report.
 *
 * Rendered by {@see HtmlView::display()} through a fixed-path include, not through the overridable template system,
 * so a template override cannot hide or fake a finding. The page template only echoes the string this produces.
 *
 * First where the check stands: never run, running (the job followed on the page), complete at a moment, failed with
 * its reason, or out of date since prices or rules changed; the next scheduled run; and "Check again", which queues
 * the same job. Then what the stored result was read for: the moment, the shopper and the price index context, and
 * how many sale rows the pricing engine priced. Then one folded block per kind of finding, every kind shown so that
 * what was checked is on the page: its title with the count, its description, and a page of its rows, each a link to
 * the product (its name and sku, the combination named when one is), the price row and the discount or tax they are
 * about, with the finding's own facts as labelled values; a section with more rows than a page is walked page by page.
 * Money is the shop currency's, dates are in the user's time zone. Last, the counters of the price index check.
 * Nothing here changes data: every row leads to the screen where it is put right.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Priceaudit\HtmlView $this
 */

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAudit;
use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAuditStore;
use Alfa\Component\Alfa\Administrator\Service\Pricing\RuleReach;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

$status = $this->status;
$summary = $status['summary'];
$state = (string) $status['state'];

// A stored date in the user's time zone; none as a dash.
$date = static fn (?string $utc): string => $utc === null || $utc === '' ? '-' : HTMLHelper::_('date', $utc, Text::_('DATE_FORMAT_LC5'));

// A stored decimal as the shop's money.
$money = fn ($value): string => $this->currency->formatStored(value: (string) $value);

// A detail's label in words.
$detailLabel = static fn (string $name): string => Text::_('COM_ALFA_PRICE_AUDIT_DETAIL_' . strtoupper($name));

// A detail value by what its name says it is: money, a percentage, a date, a kind or a status in words, a row reference.
$detailValue = static function (string $name, $value) use ($date, $money): string {
    if ($value === null || $value === '') {
        return '-';
    }

    if (str_ends_with($name, '_price')) {
        return $money($value);
    }

    if (str_ends_with($name, '_percent')) {
        return AlfaHelper::percentDisplay(value: (string) $value);
    }

    if (str_ends_with($name, '_at')) {
        return $date((string) $value);
    }

    return match ($name) {
        'kind' => Text::_('COM_ALFA_PRICE_AUDIT_KIND_' . strtoupper((string) $value)),
        'status' => Text::_('COM_ALFA_RULE_PERIOD_STATUS_' . strtoupper((string) $value)),
        'sale_pins' => Text::_((string) $value === 'final' ? 'COM_ALFA_PRICES_OVERRIDE_FINAL' : 'COM_ALFA_PRICES_OVERRIDE_START'),
        'sale_row' => '#' . (int) $value,
        default => (string) $value,
    };
};

// Each record opens with this page as its way back on Close and Save & Close.
$itemUrl = static fn (int $id): string => Route::_(url: AlfaHelper::withReturn('index.php?option=com_alfa&task=item.edit&id=' . $id));
$ruleUrl = static fn (string $kind, int $id): string => Route::_(url: AlfaHelper::withReturn('index.php?option=com_alfa&task=' . ($kind === RuleReach::TAX ? 'tax' : 'discount') . '.edit&id=' . $id));

// The sections' words: the two judged against the share carry it.
$share = $summary === null ? (string) PriceAudit::LARGE_SHARE_DEFAULT : AlfaHelper::percentDisplay(value: (string) ($summary['options']['large_share'] ?? PriceAudit::LARGE_SHARE_DEFAULT));
$withShare = [PriceAudit::SECTION_SALE_LARGE, PriceAudit::SECTION_STACKED];
$words = static fn (string $key, string $section): string => \in_array($section, $withShare, true) ? Text::sprintf($key, $share) : Text::_($key);

$page = Route::_('index.php?option=com_alfa&view=priceaudit', false);
$stateClass = match ($state) {
    PriceAuditStore::STATE_FAILED => 'alert-danger',
    PriceAuditStore::STATE_STALE, PriceAuditStore::STATE_NEVER => 'alert-warning',
    default => 'alert-info',
};
?>
<div class="price-audit audit-report">
    <?php // Where the check stands, and the one action: the same job, queued again. ?>
    <div class="alert <?php echo $stateClass; ?> d-flex flex-wrap align-items-center gap-3" role="status" data-audit-state="<?php echo $this->escape(var: $state); ?>">
        <div class="flex-grow-1">
            <?php echo match ($state) {
                PriceAuditStore::STATE_NEVER => Text::_('COM_ALFA_PRICE_AUDIT_STATE_NEVER'),
                PriceAuditStore::STATE_RUNNING => Text::_('COM_ALFA_PRICE_AUDIT_STATE_RUNNING'),
                PriceAuditStore::STATE_FAILED => Text::sprintf('COM_ALFA_PRICE_AUDIT_STATE_FAILED', (string) $status['failure']),
                PriceAuditStore::STATE_STALE => Text::sprintf('COM_ALFA_PRICE_AUDIT_STATE_STALE', $date((string) $status['stale_since'])),
                default => Text::sprintf('COM_ALFA_PRICE_AUDIT_STATE_COMPLETE', $date((string) ($summary['finished_at'] ?? ''))),
            }; ?>
            <?php if ($summary !== null && $state !== PriceAuditStore::STATE_COMPLETE) : ?>
                <span class="d-block small"><?php echo Text::sprintf('COM_ALFA_PRICE_AUDIT_STATE_COMPLETE', $date((string) ($summary['finished_at'] ?? ''))); ?></span>
            <?php endif; ?>
            <span class="d-block small text-muted">
                <?php echo $status['scheduled'] ? Text::sprintf('COM_ALFA_PRICE_AUDIT_NEXT_RUN', $date((string) $status['next_run_at'])) : Text::_('COM_ALFA_PRICE_AUDIT_NOT_SCHEDULED'); ?>
            </span>
        </div>
        <form method="post" action="<?php echo Route::_('index.php?option=com_alfa&task=priceaudit.recheck'); ?>" class="mb-0">
            <input type="hidden" name="<?php echo Session::getFormToken(); ?>" value="1">
            <button type="submit" class="btn btn-sm btn-secondary"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_RECHECK'); ?></button>
        </form>
    </div>

    <?php if ($status['job'] !== null) : ?>
        <?php // The job running, followed while the page is open; it loads the page again when the job ends. ?>
        <?php echo LayoutHelper::render('tasks.progress', ['job' => $status['job'], 'missing' => false, 'id' => (int) $status['job']['id']], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
    <?php endif; ?>

    <?php if ($summary === null) : ?>
</div>
<?php return; endif;

$index = (array) $summary['index'];
$context = (array) $summary['context'];
$engine = (array) $summary['engine'];
$counts = PriceAudit::counts(summary: $summary);
$findings = array_sum(array_map(static fn (array $section): int => (int) ($section['count'] ?? 0), (array) $summary['sections']));
?>
    <p class="text-muted">
        <?php echo Text::sprintf('COM_ALFA_PRICE_AUDIT_READ', $date((string) $summary['now'])); ?>
        <?php if ($context['id'] !== null) : ?>
            <?php echo Text::sprintf('COM_ALFA_PRICE_AUDIT_CONTEXT', (int) $context['id']); ?>
        <?php endif; ?>
        <?php if ((int) $engine['runs'] > 0 || (int) $engine['unchecked'] > 0) : ?>
            <?php echo Text::sprintf('COM_ALFA_PRICE_AUDIT_ENGINE_RUNS', (int) $engine['runs'], (int) $engine['unchecked']); ?>
        <?php endif; ?>
    </p>

    <?php if ($context['id'] === null) : ?>
        <div class="alert alert-warning" role="status">
            <span class="icon-warning" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_PRICE_AUDIT_NO_CONTEXT'); ?>
        </div>
    <?php endif; ?>

    <?php if ($findings === 0 && $index['complete']) : ?>
        <div class="alert alert-success" role="status">
            <span class="icon-checkmark-circle" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_PRICE_AUDIT_CLEAN'); ?>
        </div>
    <?php endif; ?>

    <?php foreach (PriceAudit::SECTIONS as $key) : ?>
        <?php
        $section = (array) ($summary['sections'][$key] ?? ['count' => 0, 'available' => true]);
        $pageOf = $this->pages[$key] ?? ['rows' => [], 'total' => 0, 'start' => 0, 'limit' => 1];
        $rows = (array) $pageOf['rows'];
        $count = (int) ($section['count'] ?? 0);
        $available = (bool) ($section['available'] ?? true);
        $langKey = 'COM_ALFA_PRICE_AUDIT_SECTION_' . strtoupper($key);
        $badge = !$available ? 'bg-secondary' : ($count > 0 ? $this->badge(section: $key) : 'bg-secondary');
        $open = $key === $this->section;
        ?>
        <details class="card mb-4 audit-section" id="price-audit-<?php echo $this->escape(var: $key); ?>" data-audit-section="<?php echo $this->escape(var: $key); ?>"<?php echo $open ? ' open' : ''; ?>>
            <summary class="card-header">
                <?php // The card header is a flex row; one block inside it keeps the description under the title. ?>
                <div class="audit-summary">
                    <h2 class="h4 mb-1">
                        <span class="audit-caret icon-chevron-down" aria-hidden="true"></span>
                        <?php echo $words($langKey, $key); ?> <span class="badge <?php echo $badge; ?>"><?php echo $available ? $count : '-'; ?></span>
                    </h2>
                    <span class="d-block small text-muted"><?php echo $words($langKey . '_DESC', $key); ?></span>
                </div>
            </summary>
            <div class="card-body p-0">
                <?php if (!$available) : ?>
                    <p class="p-3 mb-0 text-muted"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_UNAVAILABLE'); ?></p>
                <?php elseif ($key === PriceAudit::SECTION_UNTAXED && (int) ($section['taxes'] ?? 0) === 0) : ?>
                    <p class="p-3 mb-0 text-muted"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_UNTAXED_NO_TAXES'); ?></p>
                <?php elseif ($rows === []) : ?>
                    <p class="p-3 mb-0 text-muted"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_SECTION_CLEAN'); ?></p>
                <?php else : ?>
                    <?php if ((int) $pageOf['total'] > \count($rows)) : ?>
                        <p class="p-3 mb-0 small text-muted border-bottom">
                            <?php echo Text::sprintf('COM_ALFA_PRICE_AUDIT_LISTED', (int) $pageOf['start'] + 1, (int) $pageOf['start'] + \count($rows), (int) $pageOf['total']); ?>
                            <?php if (!$open) : ?>
                                <a href="<?php echo $page . '&section=' . $this->escape(var: $key) . '#price-audit-' . $this->escape(var: $key); ?>"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_PAGES'); ?></a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <table class="table table-striped mb-0">
                        <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_HEADING_ITEM'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_HEADING_ROW'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_HEADING_RULE'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_HEADING_DETAIL'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $finding) : ?>
                            <?php
                            $item = $finding['item'] === null ? null : (array) $finding['item'];
                            $rule = $finding['rule'] === null ? null : (array) $finding['rule'];
                            ?>
                            <tr data-item-id="<?php echo $item === null ? 0 : (int) $item['id']; ?>" data-rule-id="<?php echo $rule === null ? 0 : (int) $rule['id']; ?>">
                                <td>
                                    <?php if ($item !== null) : ?>
                                        <a href="<?php echo $itemUrl((int) $item['id']); ?>">
                                            #<?php echo (int) $item['id']; ?> <?php echo $this->escape(var: (string) ($item['name'] ?? '')); ?>
                                        </a>
                                        <?php if ((string) ($item['sku'] ?? '') !== '') : ?>
                                            <span class="text-muted small"><?php echo $this->escape(var: (string) $item['sku']); ?></span>
                                        <?php endif; ?>
                                        <?php if ((int) ($item['combination_id'] ?? 0) > 0) : ?>
                                            <span class="d-block small text-muted"><?php echo Text::sprintf('COM_ALFA_PRICE_AUDIT_COMBINATION', (int) $item['combination_id']); ?></span>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $finding['row_id'] === null ? '-' : '#' . (int) $finding['row_id']; ?></td>
                                <td>
                                    <?php if ($rule !== null) : ?>
                                        <span class="text-muted small"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_RULE_KIND_' . strtoupper((string) $rule['kind'])); ?></span>
                                        <a href="<?php echo $ruleUrl((string) $rule['kind'], (int) $rule['id']); ?>">
                                            #<?php echo (int) $rule['id']; ?> <?php echo $this->escape(var: (string) ($rule['name'] ?? '')); ?>
                                        </a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ((array) $finding['detail'] === []) : ?>
                                        -
                                    <?php endif; ?>
                                    <?php foreach ((array) $finding['detail'] as $name => $value) : ?>
                                        <div><span class="text-muted"><?php echo $this->escape(var: $detailLabel((string) $name)); ?>:</span> <?php echo $this->escape(var: $detailValue((string) $name, $value)); ?></div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($open && (int) $pageOf['total'] > (int) $pageOf['limit']) : ?>
                        <?php // The pager of the section walked: Joomla's, over the stored rows, by limitstart. ?>
                        <div class="p-3 border-top">
                            <?php echo (new Pagination((int) $pageOf['total'], (int) $pageOf['start'], (int) $pageOf['limit']))->getPagesLinks(); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>

    <?php // The price index: the counters of its check, the context this report read, and what to run when a figure is off. ?>
    <details class="card mb-4 audit-section" id="price-audit-<?php echo PriceAudit::SECTION_INDEX; ?>" data-audit-section="<?php echo PriceAudit::SECTION_INDEX; ?>" <?php echo $index['complete'] ? '' : 'open'; ?>>
        <summary class="card-header">
            <div class="audit-summary">
                <h2 class="h4 mb-1">
                    <span class="audit-caret icon-chevron-down" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_PRICE_AUDIT_SECTION_INDEX'); ?>
                    <span class="badge <?php echo $index['complete'] ? 'bg-success' : $this->badge(section: PriceAudit::SECTION_INDEX); ?>">
                        <?php echo Text::_($index['complete'] ? 'COM_ALFA_PRICE_AUDIT_INDEX_OK' : 'COM_ALFA_PRICE_AUDIT_INDEX_CHECK'); ?>
                    </span>
                </h2>
                <span class="d-block small text-muted"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_SECTION_INDEX_DESC'); ?></span>
            </div>
        </summary>
        <div class="card-body">
            <?php if (!$index['available']) : ?>
                <p class="mb-0 text-muted"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_UNAVAILABLE'); ?></p>
            <?php else : ?>
                <?php if ($index['building']) : ?>
                    <p><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_BUILDING'); ?></p>
                <?php endif; ?>
                <p>
                    <?php echo Text::sprintf($index['complete'] ? 'COM_ALFA_PRICE_AUDIT_INDEX_COMPLETE' : 'COM_ALFA_PRICE_AUDIT_INDEX_INCOMPLETE', (int) $index['contexts'], (int) $index['units']); ?>
                </p>
                <dl class="row mb-0 small">
                    <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_MISSING'); ?></dt>
                    <dd class="col-sm-8"><?php echo (int) $index['missing']; ?></dd>
                    <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_STALE'); ?></dt>
                    <dd class="col-sm-8"><?php echo (int) $index['stale']; ?></dd>
                    <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_GAPS'); ?></dt>
                    <dd class="col-sm-8"><?php echo (int) $index['gaps']; ?></dd>
                    <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_POINTERS'); ?></dt>
                    <dd class="col-sm-8"><?php echo (int) $index['pointers']; ?></dd>
                    <dt class="col-sm-4"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_LEFT_OUT'); ?></dt>
                    <dd class="col-sm-8">
                        <?php
                        // A display limit: the first twenty ids are named and the rest are counted beside them, so
                        // the line never says less than the total before it. The product list has no filter by a
                        // set of ids to link them to.
                        $leftOut = array_map('intval', $index['left_out']);
                        $leftOutNamed = \array_slice($leftOut, 0, 20);
                        $leftOutMore = \count($leftOut) - \count($leftOutNamed);
                        ?>
                        <?php echo \count($leftOut); ?>
                        <?php if ($leftOut !== []) : ?>
                            <span class="text-muted">(#<?php echo implode(', #', $leftOutNamed); ?><?php echo $leftOutMore > 0 ? Text::sprintf('COM_ALFA_PRICE_AUDIT_INDEX_LEFT_OUT_MORE', $leftOutMore) : ''; ?>)</span>
                        <?php endif; ?>
                    </dd>
                </dl>
                <?php if (!$index['complete']) : ?>
                    <p class="mb-0 mt-3 small"><?php echo Text::_('COM_ALFA_PRICE_AUDIT_INDEX_HINT'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </details>
</div>
