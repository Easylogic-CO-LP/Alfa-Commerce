<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Priceaudit;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Pricing\PriceAudit;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * The price check page: what the catalogue's prices say that a shop owner would not want them to say, as the check's
 * job last stored it, one folded section per kind of finding with its count and a page of its rows, every row a link to
 * the product, the price row, the discount or the tax it is about, and the counters of the price index check last.
 * First where the check stands: when it ran, whether it is out of date, running (followed on the page) or failed, and
 * when it runs next; "Check again" queues the same job.
 *
 * Super Users only. The report is rendered from a fixed-path partial, not through the overridable template system, so
 * a template override cannot hide or fake a finding; the template only echoes the rendered string.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The badge each section carries: a defect in red, a warning in amber, information in blue.
     *
     * @var array<string, string>
     *
     * @since  2.3.0
     */
    private const BADGES = [
        PriceAudit::SECTION_UNPRICED => 'bg-danger',
        PriceAudit::SECTION_SALE_ABOVE => 'bg-danger',
        PriceAudit::SECTION_SALE_LARGE => 'bg-warning text-dark',
        PriceAudit::SECTION_SALE_BELOW_RULES => 'bg-warning text-dark',
        PriceAudit::SECTION_SALE_OPEN => 'text-bg-info',
        PriceAudit::SECTION_RULES_PERIOD => 'bg-warning text-dark',
        PriceAudit::SECTION_UNTAXED => 'bg-danger',
        PriceAudit::SECTION_STACKED => 'bg-warning text-dark',
        PriceAudit::SECTION_INVERTED => 'bg-danger',
        PriceAudit::SECTION_INDEX => 'bg-warning text-dark',
    ];

    /**
     * Where the check stands (PriceAuditStore::status()): its state, the last complete summary, the job running, the
     * failure of the last run, the moment the result went out of date, the next scheduled run.
     *
     * @var array
     *
     * @since  2.3.0
     */
    public array $status = [];

    /**
     * One page of each section's stored findings, by section: rows (the products named), total, start, limit. Every
     * section shows its first page; the section asked for (&section=) shows the page asked for (&limitstart=).
     *
     * @var array<string, array{rows: list<array>, total: int, start: int, limit: int}>
     *
     * @since  2.3.0
     */
    public array $pages = [];

    /**
     * The section whose pages are walked, '' for none.
     *
     * @since  2.3.0
     */
    public string $section = '';

    /**
     * The report, rendered from the fixed-path partial in {@see self::display()}.
     *
     * @since  2.3.0
     */
    public string $reportHtml = '';

    /**
     * The shop's currency, which every money figure of the report is in.
     *
     * @since  2.3.0
     */
    public Currency $currency;

    /**
     * Render the page for a Super User: the stored result and where the check stands, nothing computed here.
     *
     * @param string|null $tpl The template name.
     *
     * @throws NotAllowed When the user is not a Super User.
     *
     * @since  2.3.0
     */
    public function display($tpl = null): void
    {
        if (!$this->getCurrentUser()->authorise(action: 'core.admin', assetname: 'com_alfa')) {
            throw new NotAllowed(message: Text::_('JERROR_ALERTNOAUTHOR'), code: 403);
        }

        $app = Factory::getApplication();
        $model = $this->getModel();
        $this->status = $model->getStatus();
        $this->currency = Currency::getDefault();
        $input = $app->getInput();
        $section = $input->getCmd('section', '');
        $this->section = \in_array($section, PriceAudit::SECTIONS, true) ? $section : '';
        // The page's rows: Joomla's list limit, the one every list of the back office pages by.
        $limit = max(1, (int) $app->get('list_limit', 20));
        $start = max(0, $input->getInt('limitstart', 0));

        if ($this->status['summary'] !== null) {
            foreach (PriceAudit::SECTIONS as $key) {
                $this->pages[$key] = $model->getPage(section: $key, start: $key === $this->section ? $start : 0, limit: $limit);
            }
        }

        // The report is a fixed-path partial, so its stylesheet is the view's to load, not the template's.
        $this->getDocument()->getWebAssetManager()->useStyle('com_alfa.admin');
        ob_start();
        include __DIR__ . '/_report.php';
        $this->reportHtml = (string) ob_get_clean();
        $this->addToolbar();
        parent::display(tpl: $tpl);
    }

    /**
     * The badge class of a section.
     *
     * @param string $section A section of the report.
     *
     * @since  2.3.0
     */
    public function badge(string $section): string
    {
        return self::BADGES[$section] ?? 'bg-secondary';
    }

    /**
     * Build the toolbar: the check is reached from the discounts list and the dashboard, and the way back leads to the
     * lists its findings are put right on.
     *
     * @since  2.3.0
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(title: Text::_('COM_ALFA_TITLE_PRICE_AUDIT'), icon: 'generic');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->linkButton('discounts')
            ->text('COM_ALFA_TITLE_DISCOUNTS')
            ->url(Route::_(url: 'index.php?option=com_alfa&view=discounts'))
            ->icon('icon-arrow-left');
        $toolbar->linkButton('taxes')
            ->text('COM_ALFA_TITLE_TAXES')
            ->url(Route::_(url: 'index.php?option=com_alfa&view=taxes'))
            ->icon('icon-list');
        $toolbar->linkButton('items')
            ->text('COM_ALFA_TITLE_ITEMS')
            ->url(Route::_(url: 'index.php?option=com_alfa&view=items'))
            ->icon('icon-list');
        $toolbar->preferences('com_alfa');
    }
}
