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
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * The price check page: what the catalogue's prices say that a shop owner would not want them to say, one folded
 * section per kind of finding with its count, every row a link to the product, the price row, the discount or the tax
 * it is about, and the counters of the price index check last.
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
     * The price audit's report.
     *
     * @var array
     *
     * @since  2.3.0
     */
    public array $report = [];

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
     * Render the page for a Super User.
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

        $this->report = $this->getModel()->getReport();
        $this->currency = Currency::getDefault();

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
