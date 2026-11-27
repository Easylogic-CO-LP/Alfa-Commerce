<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Dispatcher;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Site\Helper\ShopMode;
use Alfa\Module\AlfaCatalog\Site\Helper\AlfaCatalogHelper;
use Alfa\Module\AlfaCatalog\Site\Provider\Providers;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher for mod_alfa_catalog.
 *
 *   dispatch()       -> resolves the groups, registers the assets, renders (parent)
 *   getLayoutData()  -> the data the layout needs, nothing else
 *
 * A module with nothing to show renders nothing at all: the groups are resolved before any asset is registered, so
 * an empty "recently viewed" costs the page neither markup nor scripts.
 *
 * @since  2.3.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * @var array The resolved groups, built once in dispatch() and read by getLayoutData().
     * @since  2.3.0
     */
    private array $groups = [];

    /**
     * Resolve what the module shows, register the storefront assets, then render.
     *
     * A failure to read is logged and the module draws nothing: one module never takes the page down with it.
     *
     * @since  2.3.0
     */
    public function dispatch(): void
    {
        $params = new Registry($this->module->params);

        // The module's own strings are needed before the parent loads them: a tab without a label is named after
        // what it shows.
        $this->app->getLanguage()->load('mod_alfa_catalog', JPATH_SITE);

        try {
            $this->groups = AlfaCatalogHelper::getGroups(params: $params, moduleId: (int) $this->module->id);
        } catch (Throwable $e) {
            Log::add('mod_alfa_catalog could not read what it shows: ' . ErrorText::of($e), Log::ERROR, 'com_alfa');

            return;
        }

        if ($this->groups === []) {
            return;
        }

        $this->registerAssets($params, (int) $this->module->id);

        parent::dispatch();
    }

    /**
     * Returns the layout data.
     *
     * @return array
     *
     * @since  2.3.0
     */
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();

        $data['groups'] = $this->groups;
        $data['needs'] = AlfaCatalogHelper::needs($this->groups);
        $data['lazyUrl'] = AlfaCatalogHelper::lazyUrl((int) $data['module']->id);

        return $data;
    }

    /**
     * Whether the module's product cards sell: the first drawn product's word ($item->sellsOnline, set by the model
     * that drew it); a module whose product tabs are all drawn later, on demand, has no product yet and asks the
     * shop's switch.
     *
     * @since  2.3.0
     */
    private function cardsSell(): bool
    {
        foreach ($this->groups as $group) {
            if ($group['kind'] !== Providers::KIND_PRODUCTS) {
                continue;
            }

            foreach ((array) ($group['items'] ?? []) as $item) {
                return (bool) ($item->sellsOnline ?? true);
            }
        }

        return ShopMode::sellsOnline();
    }

    /**
     * Register what the tabs use and nothing else (AlfaCatalogHelper::needs()).
     *
     * The module's stylesheet always. Its script only for a tab strip or a slider: a single grid or list is plain
     * markup. The slider library only when a tab is a slider. What the catalogue draws inside a product (price,
     * stock, add to cart, variant and option choices) brings its styles and scripts from com_alfa's registry, which
     * has not necessarily been added on this page, hence the explicit registration; a module of categories or
     * manufacturers loads none of it.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance id.
     *
     * @since  2.3.0
     */
    private function registerAssets(Registry $params, int $moduleId): void
    {
        $document = $this->app->getDocument();
        $wa = $document->getWebAssetManager();
        $needs = AlfaCatalogHelper::needs($this->groups);

        if ($needs['shop']) {
            // Before the module's own stylesheet, which then has the last word on its cards. The strings of what the catalogue draws in a product: prices, stock, add to cart.
            $this->app->getLanguage()->load('com_alfa', JPATH_SITE);

            $wa->getRegistry()->addExtensionRegistryFile('com_alfa');
            $wa->useStyle('com_alfa.general')
                ->useStyle('com_alfa.item');
        }

        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_catalog');
        $wa->useStyle('mod_alfa_catalog.catalog');

        if ($needs['script']) {
            $wa->useScript('mod_alfa_catalog.catalog');
        }

        if ($needs['slider']) {
            $wa->useStyle('mod_alfa_catalog.keen-slider')
                ->useScript('mod_alfa_catalog.keen-slider');
        }

        // The buying scripts only for cards that sell: in catalogue-only mode a card has no buy form. Add to cart
        // and re-pricing are CSRF-protected posts; on a page where no component published the token, the card
        // scripts still need one.
        if ($needs['buy'] && $this->cardsSell()) {
            $wa->useScript('core')
                ->useScript('com_alfa.item.recalculate')
                ->usePreset('com_alfa.item.addtocart')
                ->useScript('com_alfa.item.variant')
                ->useScript('com_alfa.showon.engine');

            $document->addScriptOptions('csrf.token', Session::getFormToken());
        }

        // Every grid tab's gap and columns, on wide screens and at the module's two widths; then the cards: what
        // every tab sets, on every screen and per breakpoint. For every tab, fetched yet or not, so one opened
        // later is styled.
        foreach ([
            AlfaCatalogHelper::responsiveCss(moduleId: $moduleId, params: $params, tabs: $this->groups),
            AlfaCatalogHelper::lookCss(moduleId: $moduleId, params: $params, tabs: $this->groups),
        ] as $css) {
            if ($css !== '') {
                $wa->addInlineStyle($css);
            }
        }

        // Only the colours the merchant actually set, each with its dark twin. An unconditional declaration here
        // would outrank both the module's own light and dark values and the storefront palette, turning an
        // untouched setting into a fixed one. The dark values go under the same two selectors the stylesheet's own
        // dark values use, so a dark choice replaces the default exactly where that default sat.
        $tokens = [
            '--mod-alfa-catalog-surface' => 'style_surface',
            '--mod-alfa-catalog-surface-color' => 'style_text',
            '--mod-alfa-catalog-muted' => 'style_muted',
            '--mod-alfa-catalog-hairline' => 'style_hairline',
            '--mod-alfa-catalog-accent' => 'style_accent',
        ];
        $light = '';
        $dark = '';

        foreach ($tokens as $token => $param) {
            $value = trim((string) $params->get($param, ''));
            $light .= $value !== '' ? $token . ':' . $value . ';' : '';
            $value = trim((string) $params->get($param . '_dark', ''));
            $dark .= $value !== '' ? $token . ':' . $value . ';' : '';
        }

        $root = '.mod-alfa-catalog-' . $moduleId;

        if ($light !== '') {
            $wa->addInlineStyle($root . '{' . $light . '}');
        }

        if ($dark !== '') {
            $wa->addInlineStyle(
                ':is(:root[data-theme="dark"],:root[data-theme-default="dark"]:not([data-theme])) '
                . $root . '{' . $dark . '}'
                . '@media (prefers-color-scheme:dark){'
                . ':is(:root[data-theme="auto"],:root[data-theme-default="auto"]:not([data-theme]),'
                . ':root:not([data-theme]):not([data-theme-default])) '
                . $root . '{' . $dark . '}}',
            );
        }

        // The merchant's own CSS and script, last, so they win over everything above. Neither may close the
        // element it is written into.
        $customCss = trim((string) $params->get('custom_css', ''));
        $customJs = trim((string) $params->get('custom_js', ''));

        if ($customCss !== '') {
            $wa->addInlineStyle(str_ireplace('</style', '<\\/style', $customCss));
        }

        if ($customJs !== '') {
            $wa->addInlineScript(str_ireplace('</script', '<\\/script', $customJs));
        }
    }
}
