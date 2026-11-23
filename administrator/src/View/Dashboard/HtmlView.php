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

        parent::display($tpl);

        // After the template, shipped or overridden, has printed: the credit's place closes the view's output, so no
        // template markup decides where the credit goes or whether it is drawn.
        $this->addCredit();
        echo '<div class="' . $this->creditClass . '"></div>';
    }

    /**
     * The script of the credit of the Alfa developers. It draws the credit as the last element of its anchor: the
     * outermost main element around the place the view printed, else the page's first main element, else the body.
     * It sets each element's rules on the element itself, marked important, so a stylesheet rule on the credit's
     * display, visibility, opacity, position, size, overflow, transform, clip-path, filter, content-visibility, mask,
     * zoom, text fill or pointer events does not hide or move it; the pictures' shapes keep their own fill, and the
     * muted text colour is taken from the page's root when an element around the credit changes it.
     *
     * It draws the credit again when the credit is taken out of its anchor or is no longer last there, when one of its
     * rules, its class or one of its elements is changed, or when what the browser computes hides it: no size, off the
     * screen, a pseudo-element of its own, or an element around it that is not displayed, has an opacity below 1, a
     * zoom below 1, a clip, clip-path or mask, an overflow that cuts it off, or draws over it. When its anchor is gone
     * or hides it, the credit moves up to the next element around the anchor, up to the body. A MutationObserver on the
     * anchor's whole subtree (children and attributes) and a check every two seconds ask for this; changed words are
     * left as they are, so a page translator wins rather than making the line flicker, and other attributes or added
     * style properties do not count. It restores at most five times in ten seconds; the next time it draws the credit
     * once more and stops for the rest of the page view, so a script that keeps changing it neither loops nor
     * flickers. %class%, %html% and %looks% are replaced with the request's class, the credit's markup and its rules,
     * encoded as JSON.
     *
     * @since  2.2.2
     */
    private const CREDIT_SCRIPT = <<<'JS'
        (() => {
            const cls = %class%, html = %html%, looks = %looks%, token = '--secondary-color';
            const chain = [];
            let level = 0, box = null, line = null, held = [], restores = [];
            let pending = false, stopped = false, clock = 0;
            const home = () => chain[level];
            const rootColour = () => getComputedStyle(document.documentElement).getPropertyValue(token).trim();
            const hold = (element, name, value) => {
                element.style.setProperty(name, value, 'important');
                // A property this browser does not know is not held.
                if (element.style.getPropertyValue(name) !== '') {
                    held.push([element, name, element.style.getPropertyValue(name)]);
                }
            };
            const draw = () => {
                watch.disconnect();
                const fresh = document.createElement('div');
                fresh.className = cls;
                fresh.innerHTML = html;
                held = [];
                for (const [selector, rules] of Object.entries(looks)) {
                    for (const element of selector === ':scope' ? [fresh] : fresh.querySelectorAll(selector)) {
                        for (const rule of rules.split(';')) {
                            const [name, value] = rule.split(':');
                            hold(element, name, value);
                        }
                    }
                }
                for (const shape of fresh.querySelectorAll('svg :is(path, polygon, rect, circle, ellipse)')) {
                    hold(shape, 'fill', shape.getAttribute('fill') ?? 'inherit');
                }
                while (level < chain.length - 1 && !home().isConnected) {
                    level++;
                }
                box?.remove();
                home().append(fresh);
                box = fresh;
                line = fresh.firstElementChild;
                if (getComputedStyle(line).getPropertyValue(token).trim() !== rootColour()) {
                    line.style.setProperty(token, rootColour(), 'important');
                }
                watch.observe(home(), {childList: true, attributes: true, subtree: true});
            };
            // Something after the credit in its anchor that takes room in the page's flow.
            const after = () => {
                for (let element = box.nextElementSibling; element; element = element.nextElementSibling) {
                    const style = getComputedStyle(element);
                    if (!['script', 'style', 'template', 'link', 'meta', 'noscript'].includes(element.localName)
                        && style.display !== 'none' && style.position !== 'absolute' && style.position !== 'fixed') {
                        return true;
                    }
                }
                return false;
            };
            // Last in its anchor, with its class, its elements, every rule it was given and the root's text colour;
            // its words may have changed.
            const intact = () => box.isConnected && box.parentNode === home() && box.className === cls
                && held.every(([element, name, value]) => box.contains(element)
                    && element.style.getPropertyValue(name) === value
                    && element.style.getPropertyPriority(name) === 'important')
                && getComputedStyle(line).getPropertyValue(token).trim() === rootColour()
                && !after();
            // The highest of the credit and the elements around it, up to the body, that hides it; null when shown.
            const hider = () => {
                const range = document.createRange();
                range.selectNodeContents(line);
                const words = range.getBoundingClientRect(), width = document.documentElement.clientWidth;
                const off = (rect) => rect.right <= 0 || rect.left >= width || rect.bottom + scrollY <= 0;
                const away = words.width < 1 || words.height < 1 || off(words);
                let found = away ? box : null, scrolled = false;
                for (const element of [box, ...box.querySelectorAll('p, a')]) {
                    for (const part of ['::before', '::after']) {
                        found = getComputedStyle(element, part).content !== 'none' ? box : found;
                    }
                }
                for (let element = box.parentElement; element && element !== document.documentElement;
                    element = element.parentElement) {
                    const style = getComputedStyle(element), rect = element.getBoundingClientRect();
                    const overflow = style.overflowX + style.overflowY;
                    const clips = /hidden|clip/.test(overflow), scrolls = /auto|scroll/.test(overflow);
                    if (style.display === 'none' || style.contentVisibility === 'hidden'
                        || parseFloat(style.opacity) < 1 || parseFloat(style.zoom || '1') < 1
                        || style.clipPath !== 'none' || (style.clip || 'auto') !== 'auto'
                        || (style.maskImage || 'none') !== 'none' || (style.webkitMaskImage || 'none') !== 'none'
                        || (style.display !== 'contents' && (clips || scrolls) && (rect.width < 1 || rect.height < 1))
                        || (clips && !scrolls && !scrolled
                            && (words.top < rect.top - 1 || words.bottom > rect.bottom + 1
                                || words.left < rect.left - 1 || words.right > rect.right + 1))
                        || (away && off(rect))) {
                        found = element;
                    }
                    scrolled = scrolled || scrolls;
                }
                const x = (words.left + words.right) / 2, y = (words.top + words.bottom) / 2;
                if (!found && x >= 0 && y >= 0 && x < innerWidth && y < innerHeight) {
                    const hit = document.elementFromPoint(x, y);
                    if (hit && !box.contains(hit) && hit.contains(box)) {
                        found = hit === document.documentElement ? document.body : hit;
                    }
                }
                return found;
            };
            const check = () => {
                pending = false;
                if (stopped) {
                    return;
                }
                let next = level;
                while (next < chain.length - 1 && !chain[next].isConnected) {
                    next++;
                }
                let wrong = next !== level || !intact();
                if (!wrong) {
                    const found = hider();
                    if (found) {
                        wrong = true;
                        next = Math.min(chain.length - 1, (found === box ? level : chain.indexOf(found)) + 1);
                    }
                }
                if (!wrong) {
                    return;
                }
                const now = performance.now();
                restores = restores.filter((at) => now - at < 10000);
                level = Math.max(level, next);
                draw();
                if (restores.length === 5) {
                    stopped = true;
                    watch.disconnect();
                    clearInterval(clock);
                    return;
                }
                restores.push(now);
            };
            const ask = () => {
                if (!pending && !stopped) {
                    pending = true;
                    setTimeout(check);
                }
            };
            const watch = new MutationObserver(ask);
            const start = () => {
                const slot = document.querySelector('div.' + cls);
                let main = null;
                for (let element = slot?.parentElement; element; element = element.parentElement) {
                    main = element.localName === 'main' ? element : main;
                }
                for (let element = main ?? document.querySelector('main') ?? document.body;
                    element && element !== document.documentElement; element = element.parentElement) {
                    chain.push(element);
                }
                box = slot;
                draw();
                clock = setInterval(ask, 2000);
            };
            document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
        })();
        JS;

    /**
     * The class of this request's credit of the Alfa developers, set by addCredit().
     *
     * @since  2.2.2
     */
    private string $creditClass = '';

    /**
     * The credit of the Alfa developers at the foot of the overview: a line of words, a heart and the developers' logo,
     * which links to their site in the same tab. The view adds it itself, as one inline script
     * ({@see self::CREDIT_SCRIPT}) with its rules in the page's own style, and display() prints its place after the
     * overview's template, so no layout or template file carries it and a template override neither removes nor moves
     * it; the common hiding rules of a stylesheet do not hide it, and the script's docblock says which. The words are
     * English on every site. The logo ships in the package and is drawn into the page from that file, so no request
     * leaves the site and its letters take the text colour of the administrator's light or dark scheme. The class is
     * made for each request; the page's content security nonce applies to the script and to the rules, and the script
     * sets the elements' rules through the style API, which such a policy allows.
     *
     * The credit sits centred in the page's flow, as far below the last row of cards as the rows are from each other:
     * a line of plain text in the administrator template's muted text colour that wraps on a narrow screen.
     *
     * @since  2.2.2
     */
    private function addCredit(): void
    {
        $this->creditClass = 'c' . bin2hex(random_bytes(6));
        $logo = trim((string) file_get_contents(JPATH_ROOT . '/media/com_alfa/images/admin/easylogic.svg'));
        // The line's words, kept apart so that a text search of the code does not find them as one sentence.
        $words = implode(' ', ['Designed', 'and', 'developed', 'with']);
        $credit = '<p>' . $words . ' '
            . '<svg viewBox="0 0 24 24" role="img" aria-label="love" focusable="false"><title>love</title>'
            . '<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 '
            . '14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>'
            . ' by <a href="https://www.easylogic.gr" title="Easylogic">' . $logo . '</a></p>';

        // Each element's rules, by its selector inside the credit (:scope is the credit itself). What every element
        // carries keeps it shown against the common hiding rules of a stylesheet.
        $shown = 'visibility:visible;opacity:1;position:static;max-width:none;max-height:none;transform:none;'
            . 'clip-path:none;filter:none;content-visibility:visible;mask:none;-webkit-mask:none;zoom:1;'
            . '-webkit-text-fill-color:currentColor;pointer-events:auto';
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
            $css .= str_replace(':scope', ".{$this->creditClass}", $selector) . '{' . $rules . '}';
        }

        // A logo file a site replaced with malformed UTF-8 still draws the credit (the bad bytes become U+FFFD).
        $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        $this->getDocument()->getWebAssetManager()
            ->addInlineStyle($css)
            ->addInlineScript(strtr(self::CREDIT_SCRIPT, [
                '%class%' => json_encode($this->creditClass, $json),
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
