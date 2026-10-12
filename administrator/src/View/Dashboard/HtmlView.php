<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Card\CardRegistry;
use Alfa\Component\Alfa\Administrator\Card\DashboardSurface;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\Stats\DashboardPeriod;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * The component's home screen (what opening "Commerce" lands on): the setup checklist
 * card (rendered by the single render path in {@see SetupHelper::renderCard()}, from
 * the CACHED verdict; page loads never pay for the probes), then every card any
 * contributor placed on a surface.
 *
 * The screen owns exactly one control: how far back it is looking. That choice is a
 * cache DIMENSION as well as a query parameter (a card resolved for seven days must
 * never be served to somebody asking for ninety), so it is passed to the registry as a
 * scope rather than read behind its back.
 *
 * @since  1.10.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The rendered setup card.
     * @since  1.10.0
     */
    public string $cardHtml = '';

    /**
     * @var string The store's configured name, '' until the merchant sets it.
     * @since  1.10.0
     */
    public string $storeName = '';

    /**
     * @var array<string, string> Rendered card surfaces, keyed by surface value.
     * @since  1.10.0
     */
    public array $surfaces = [];

    /**
     * @var int How many days back the cards on this screen are reading.
     * @since  1.10.0
     */
    public int $days = DashboardPeriod::DEFAULT_DAYS;

    /**
     * Whether every setup check passes: the dashboard title shows its tick from this,
     * and the checklist strip hides itself. Read from the same evaluation the card
     * rendered, which is request-static, so it costs nothing extra.
     *
     * @since  1.10.0
     */
    public bool $setupReady = false;

    /**
     * @param string $tpl The name of the template file to parse.
     *
     * @return void
     *
     * @since  1.10.0
     */
    public function display($tpl = null)
    {
        $this->cardHtml = SetupHelper::renderCard();

        // Resolved ONCE, before any card is asked for anything: the period is read
        // from the request through user state here, so the switcher's link both
        // answers this page load and is remembered for the next.
        $this->days = DashboardPeriod::days();
        $scope = DashboardPeriod::scope();

        // Every surface the screen exposes, in enum order. The registry fills them;
        // adding a card is a declaration by whoever owns it, never an edit here. The
        // enum is also why nothing can be "unplaced": a contributor cannot name a
        // surface that does not exist.
        foreach (DashboardSurface::cases() as $surface) {
            $this->surfaces[$surface->value] = CardRegistry::render(CardRegistry::SCREEN_DASHBOARD, $surface, $scope);
        }
        $this->storeName = trim((string) ComponentHelper::getParams('com_alfa')->get('store_name', ''));

        $counts = SetupHelper::status()['counts'] ?? [];
        $this->setupReady = ($counts['total'] ?? 0) > 0 && ($counts['done'] ?? 0) === ($counts['total'] ?? 0);

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Title, Options (for admins) and the notification bell: the dashboard is a
     * home, not a list screen, so the toolbar stays quiet.
     *
     *
     * @since  1.10.0
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_DASHBOARD'), 'home');

        $toolbar = $this->getDocument()->getToolbar();
        $user = $this->getCurrentUser();

        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
