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
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The rendered setup card.
     * @since  2.0.0
     */
    public string $cardHtml = '';

    /**
     * @var string The store's configured name, '' until the merchant sets it.
     * @since  2.0.0
     */
    public string $storeName = '';

    /**
     * @var array<string, string> Rendered card surfaces, keyed by surface value.
     * @since  2.0.0
     */
    public array $surfaces = [];

    /**
     * @var int How many days back the cards on this screen are reading.
     * @since  2.0.0
     */
    public int $days = DashboardPeriod::DEFAULT_DAYS;

    /**
     * Whether every setup check passes: the dashboard title shows its tick from this,
     * and the checklist strip hides itself. Read from the same evaluation the card
     * rendered, which is request-static, so it costs nothing extra.
     *
     * @since  2.0.0
     */
    public bool $setupReady = false;

    /**
     * @param string $tpl The name of the template file to parse.
     *
     * @return void
     *
     * @since  2.0.0
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
        $this->addCredit();

        parent::display($tpl);
    }

    /**
     * The credit's script. It builds the credit, appends it as the last element of the overview (.alfa-dashboard), or
     * of the page's main content when that element is missing, and sets each element's rules on the element itself,
     * marked important, so no stylesheet rule hides or moves it. A MutationObserver draws it again when it is removed
     * or changed, on the next task, so a script that keeps removing it never freezes the page. %class%, %html% and
     * %looks% are replaced with the request's class, the credit's markup and its rules, encoded as JSON.
     *
     * @since  2.2.2
     */
    private const CREDIT_SCRIPT = <<<'JS'
        (() => {
            const cls = %class%, html = %html%, looks = %looks%;
            let home = null, box = null, queued = false;
            const draw = () => {
                watch.disconnect();
                const fresh = document.createElement('div');
                fresh.className = cls;
                fresh.innerHTML = html;
                for (const [selector, rules] of Object.entries(looks)) {
                    for (const element of selector === ':scope' ? [fresh] : fresh.querySelectorAll(selector)) {
                        for (const rule of rules.split(';')) {
                            const [name, value] = rule.split(':');
                            element.style.setProperty(name, value, 'important');
                        }
                    }
                }
                if (box?.parentNode === home) {
                    box.replaceWith(fresh);
                } else {
                    box?.remove();
                    home.append(fresh);
                }
                box = fresh;
                watch.observe(home, {childList: true});
                watch.observe(box, {attributes: true, childList: true, subtree: true, characterData: true});
            };
            const watch = new MutationObserver((records) => {
                if (!queued && records.some((r) => box.contains(r.target) || Array.from(r.removedNodes).includes(box))) {
                    queued = true;
                    setTimeout(() => {
                        queued = false;
                        draw();
                    });
                }
            });
            const start = () => {
                home = document.querySelector('.alfa-dashboard') ?? document.querySelector('main') ?? document.body;
                draw();
            };
            document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
        })();
        JS;

    /**
     * The Easylogic credit at the foot of the overview: "Designed and developed with", a heart, "by" and the Easylogic
     * logo, which links to easylogic.gr in the same tab. The view adds it itself, as one inline script
     * ({@see self::CREDIT_SCRIPT}) with its rules in the page's own style, so no layout or template file carries it,
     * a template override does not remove it, and a template's stylesheet does not hide it. The words are English on
     * every site. The logo ships in the package and is drawn into the page from that file, so no request leaves the
     * site and its letters take the text colour of the administrator's light or dark scheme. The class is made for
     * each request; the page's content security nonce applies to the script and to the rules, and the script sets the
     * elements' rules through the style API, which such a policy allows.
     *
     * The credit sits centred in the page's flow, as far below the last row of cards as the rows are from each other:
     * a line of plain text in the administrator template's muted text colour that wraps on a narrow screen.
     *
     * @since  2.2.2
     */
    private function addCredit(): void
    {
        $class = 'c' . bin2hex(random_bytes(6));
        $logo = trim((string) file_get_contents(JPATH_ROOT . '/media/com_alfa/images/admin/easylogic.svg'));
        $credit = '<p>Designed and developed with '
            . '<svg viewBox="0 0 24 24" role="img" aria-label="love" focusable="false"><title>love</title>'
            . '<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 '
            . '14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>'
            . ' by <a href="https://www.easylogic.gr" title="Easylogic">' . $logo . '</a></p>';

        // Each element's rules, by its selector inside the credit (:scope is the credit itself). What every element
        // carries keeps it shown, whatever a stylesheet says.
        $shown = 'visibility:visible;opacity:1;position:static;max-width:none;max-height:none;transform:none;clip-path:none;'
            . 'filter:none';
        $looks = [
            ':scope' => "display:block;width:auto;height:auto;overflow:visible;margin:1rem 0 0;{$shown}",
            ':scope>p' => 'display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:.35em;width:auto;'
                . "height:auto;margin:0;color:var(--secondary-color);font-size:.8125rem;line-height:1.2;{$shown}",
            ':scope p>svg' => "display:block;width:.95em;height:.95em;fill:#e0245e;{$shown}",
            ':scope a' => "display:inline-flex;align-items:center;width:auto;height:auto;color:inherit;{$shown}",
            ':scope a>svg' => "display:block;width:3.85rem;height:.95rem;{$shown}",
        ];
        $css = '';

        foreach ($looks as $selector => $rules) {
            $css .= str_replace(':scope', ".{$class}", $selector) . '{' . $rules . '}';
        }

        // A logo file a site replaced with malformed UTF-8 still draws the credit (the bad bytes become U+FFFD).
        $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        $this->getDocument()->getWebAssetManager()
            ->addInlineStyle($css)
            ->addInlineScript(strtr(self::CREDIT_SCRIPT, [
                '%class%' => json_encode($class, $json),
                '%html%' => json_encode($credit, $json),
                '%looks%' => json_encode($looks, $json),
            ]));
    }

    /**
     * Title, Options (for admins) and the notification bell: the dashboard is a
     * home, not a list screen, so the toolbar stays quiet.
     *
     *
     * @since  2.0.0
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
