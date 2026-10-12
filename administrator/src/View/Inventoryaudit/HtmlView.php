<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Inventoryaudit;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * The inventory audit page: every inventory inconsistency of the catalogue, one table per
 * category, with the audited actions offered where they apply, and after them, under their
 * own heading, what is listed for information only: the order lines classified there and
 * the stock records whose reservations hold more than they have on hand, in the order the
 * reservations are served.
 *
 * Super Users only. The report is rendered from a fixed-path partial, not through the
 * overridable template system, so a template override cannot hide or fake a finding; the
 * template only echoes the rendered string.
 *
 * @since  1.14.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The action each finding category offers on its rows. A category absent from this map is
     * reported only: orphan stock rows, reserved quantities that disagree with the open
     * reservations and open reservations on a removed unit (no audited command clears any of
     * them), movement histories without a stock record and quantities no movement explains.
     *
     * - reconcile:      the audited repair of the item's stock figures (stock records unchanged),
     *                   which also ends the holds that keep its units from sale.
     * - writeoff:       the audited write-off of stock held on a stock identity kept only for order
     *                   history: the product itself of a product with combinations, an archived
     *                   combination or any identity of an archived product (reason required).
     * - tracking_hint:  a hint and a link to the product: switching tracking on with the quantity
     *                   on hand is how that stock is recorded or cleared.
     * - resolve:        the recorded resolution of an order line whose stock identity no longer
     *                   exists (reason required), offered only while none is recorded for the
     *                   line and a stock identity of its product remains to choose.
     * - classify:       the recorded historical parent identity of a line sold on the product
     *                   itself (reason required), offered only while none is recorded.
     * - correct:        a correction of a line's recorded resolution (reason required), offered
     *                   only while no stock has moved for the line on the stock identity it
     *                   records and a stock identity remains to choose. A line row of any of the
     *                   three kinds offers it instead of its own form once a resolution is
     *                   recorded ({@see self::lineAction()}).
     * - prioritise:     serving an order first (reason required), offered on a stock record
     *                   whose open reservations hold more than it has on hand, for each order
     *                   with a reservation there that waits for stock ({@see self::waitingOrders()}).
     *
     * @var array<string, string>
     *
     * @since  1.14.0
     */
    private const ROW_ACTIONS = [
        'ledger_missing' => 'reconcile',
        'physical_mismatch' => 'reconcile',
        'salable_mismatch' => 'reconcile',
        'untracked_projection' => 'reconcile',
        'integrity_hold' => 'reconcile',
        'stranded_parent' => 'writeoff',
        'archived_stock' => 'writeoff',
        'untracked_stock' => 'tracking_hint',
        'unresolved_line' => 'resolve',
        'historical_parent_line' => 'classify',
        'resolved_line' => 'correct',
        'reservation_queue' => 'prioritise',
    ];

    /**
     * The integrity reader's report over the whole catalogue: the inconsistencies and the
     * classifications, each grouped by category.
     *
     * @var array{findings: array<string, array>, classifications: array<string, array>, scanned: array{units: int, rows: int}}
     *
     * @since  1.14.0
     */
    public array $report = [];

    /**
     * The report, rendered from the fixed-path partial in {@see self::display()}.
     *
     *
     * @since  1.14.0
     */
    public string $reportHtml = '';

    /**
     * Render the page for a Super User.
     *
     * @param string|null $tpl The template name.
     *
     *
     * @throws NotAllowed When the user is not a Super User.
     *
     * @since  1.14.0
     */
    public function display($tpl = null): void
    {
        if (!$this->getCurrentUser()->authorise(action: 'core.admin', assetname: 'com_alfa')) {
            throw new NotAllowed(message: Text::_('JERROR_ALERTNOAUTHOR'), code: 403);
        }

        $this->report = $this->getModel()->getReport();

        ob_start();
        include __DIR__ . '/_report.php';
        $this->reportHtml = (string) ob_get_clean();

        $this->addToolbar();

        parent::display(tpl: $tpl);
    }

    /**
     * The action a finding category offers on its rows, or '' when it is reported only.
     *
     * @param string $category A finding category of the integrity reader.
     *
     * @return string reconcile | writeoff | tracking_hint | resolve | classify | correct | prioritise | ''
     *
     * @since  1.14.0
     */
    public function rowAction(string $category): string
    {
        return self::ROW_ACTIONS[$category] ?? '';
    }

    /**
     * The form an order-line row offers, from its finding: its category's own form while no
     * resolution is recorded for the line; once one is, a correction of it while it has
     * produced no stock movement and the finding names a stock identity a correction may
     * choose ({@see self::resolveTargets()}), and nothing otherwise, since the inventory
     * service refuses any change of a resolution stock has moved against, and a correction
     * with no identity to choose could only be refused.
     *
     * @param array $finding An unresolved_line, historical_parent_line or resolved_line finding.
     *
     * @return string resolve | classify | correct | ''
     *
     * @since  1.14.0
     */
    public function lineAction(array $finding): string
    {
        $action = $this->rowAction(category: (string) $finding['category']);
        $recorded = $finding['detail']['recorded'];

        if ($recorded === null) {
            return $action === 'correct' ? '' : $action;
        }

        if ((bool) $recorded['consumed']) {
            return '';
        }

        $targets = $this->resolveTargets(finding: $finding);

        return $targets['combinations'] === [] && !$targets['parent'] ? '' : 'correct';
    }

    /**
     * The stock identities an order line can be resolved to, or its resolution corrected to,
     * from its finding: each combination of its product, archived ones included, as the reader
     * lists them for the line, labelled '#id sku' with the archived suffix, and whether the
     * product itself is a choice (the historical parent identity). A line sold on the product
     * itself is never bound to a combination, so it is offered the product itself only. A line
     * the reader classifies acts on an existing product, so the product itself is a choice when
     * the finding does not state otherwise; the finding states it is not once the product itself
     * is its own current stock unit again, or no longer exists (then no combination is offered
     * either).
     *
     * @param array $finding An unresolved_line, historical_parent_line or resolved_line finding of
     *                       the integrity reader.
     *
     * @return array{combinations: list<array{id: int, label: string}>, parent: bool}
     *
     * @since  1.14.0
     */
    public function resolveTargets(array $finding): array
    {
        $combinations = [];
        $candidates = (int) $finding['combination_id'] > 0 ? (array) ($finding['detail']['candidates'] ?? []) : [];

        foreach ($candidates as $candidate) {
            $label = '#' . (int) $candidate['combination_id'] . ' ' . (string) $candidate['sku'];

            if ((bool) $candidate['archived']) {
                $label .= ' ' . Text::_('COM_ALFA_INVENTORY_AUDIT_ARCHIVED_SUFFIX');
            }

            $combinations[] = ['id' => (int) $candidate['combination_id'], 'label' => $label];
        }

        return ['combinations' => $combinations, 'parent' => (bool) ($finding['detail']['parent'] ?? true)];
    }

    /**
     * The open order lines that still rely on a stock identity kept for order history to deduct
     * their stock, and so keep the stock held on it from being written off, from a
     * stranded_parent or archived_stock finding.
     *
     * @param array $finding A stranded_parent or archived_stock finding of the integrity reader.
     *
     * @return list<array{order_id: int, line_id: int, ordered: float, outstanding: float}>
     *
     * @since  1.14.0
     */
    public function openLines(array $finding): array
    {
        return array_values((array) ($finding['detail']['open_lines'] ?? []));
    }

    /**
     * The orders that can be served first on a stock record whose open reservations hold
     * more than it has on hand: each order with a reservation there that waits for stock,
     * in the order the record serves them. A reservation of another source is not an
     * order's and offers nothing.
     *
     * @param array $finding A reservation_queue finding of the integrity reader.
     *
     * @return list<int> Order ids.
     *
     * @since  1.14.0
     */
    public function waitingOrders(array $finding): array
    {
        $orders = [];

        foreach ((array) ($finding['detail']['reservations'] ?? []) as $claim) {
            if (isset($claim['order_id']) && (float) $claim['waiting'] > InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                $orders[(int) $claim['order_id']] = (int) $claim['order_id'];
            }
        }

        return array_values(array: $orders);
    }

    /**
     * Build the toolbar.
     *
     *
     * @since  1.14.0
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(title: Text::_('COM_ALFA_TITLE_INVENTORY_AUDIT'), icon: 'generic');
    }
}
