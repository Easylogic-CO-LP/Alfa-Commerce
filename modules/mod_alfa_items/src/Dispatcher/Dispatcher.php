<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaItems\Site\Dispatcher;

use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Module\AlfaItems\Site\Helper\AlfaItemsHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher for mod_alfa_items.
 *
 *   dispatch()       -> resolves the groups, registers the assets, renders (parent)
 *   getLayoutData()  -> the data the layout needs, nothing else
 *
 * A module with nothing to show renders nothing at all: the groups are resolved
 * before any asset is registered, so an empty "recently viewed" costs the page
 * neither markup nor scripts.
 *
 * @since  1.0.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * @var array The resolved groups, built once in dispatch() and read by getLayoutData().
     * @since  1.0.0
     */
    private array $groups = [];

    /**
     * Resolve what the module shows, register the storefront assets, then render.
     *
     *
     * @since  1.0.0
     */
    public function dispatch(): void
    {
        $params = new Registry($this->module->params);

        // The module's own strings are needed before the parent loads them: a tab
        // without a label is named after its source. The card's strings (prices,
        // stock, add to cart) are the component's, and the module renders on any page.
        $this->app->getLanguage()->load('mod_alfa_items', JPATH_SITE);
        $this->app->getLanguage()->load('com_alfa', JPATH_SITE);

        $this->groups = AlfaItemsHelper::getGroups(params: $params, moduleId: (int) $this->module->id);

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
     * @since  1.0.0
     */
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();

        $data['groups'] = $this->groups;
        $data['display'] = AlfaItemsHelper::displayOptions($data['params']);
        $data['priceSettings'] = PriceSettings::get();
        $data['lazyUrl'] = AlfaItemsHelper::lazyUrl((int) $data['module']->id);

        return $data;
    }

    /**
     * Register the assets the cards and the module chrome need.
     *
     * The card and its look are the module's (items.css); what Alfa draws inside it (
     * price, stock, add to cart, variant and option choices) brings Alfa's styles and
     * scripts from com_alfa's registry, which has not necessarily been added on this
     * page, hence the explicit registration before any use. The slider library loads only when a tab is
     * a slider.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance id.
     *
     *
     * @since  1.0.0
     */
    private function registerAssets(Registry $params, int $moduleId): void
    {
        $document = $this->app->getDocument();
        $wa = $document->getWebAssetManager();

        $wa->getRegistry()->addExtensionRegistryFile('com_alfa');
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_items');

        $wa->useScript('core')
            ->useStyle('com_alfa.general')
            ->useStyle('com_alfa.item')
            ->useScript('com_alfa.item.recalculate')
            ->usePreset('com_alfa.item.addtocart')
            ->useScript('com_alfa.item.variant')
            ->useScript('com_alfa.showon.engine')
            ->useStyle('mod_alfa_items.items')
            ->useScript('mod_alfa_items.items');

        if (AlfaItemsHelper::usesSlider($params)) {
            $wa->useStyle('mod_alfa_items.keen-slider')
                ->useScript('mod_alfa_items.keen-slider');
        }

        // Add-to-cart and re-pricing are CSRF-protected posts; on a page where no
        // component published the token, the card scripts still need one.
        $document->addScriptOptions('csrf.token', Session::getFormToken());

        // Every tab's gap and grid columns, on wide screens and at the module's two widths.
        $wa->addInlineStyle(AlfaItemsHelper::responsiveCss(moduleId: $moduleId, params: $params, groups: $this->groups));

        // The cards: what every place (a tab) sets, on every screen and per breakpoint.
        // For every tab, fetched yet or not, so one opened later is styled.
        $cards = AlfaItemsHelper::lookCss(moduleId: $moduleId, params: $params, groups: $this->groups);

        if ($cards !== '') {
            $wa->addInlineStyle($cards);
        }

        // Only what the merchant actually set. An unconditional declaration here would
        // outrank both the module's own light/dark values and the storefront palette,
        // turning an untouched setting into a hardcoded one.
        $styleCss = '';

        foreach ([
            '--alfa-items-surface' => trim((string) $params->get('style_surface', '')),
            '--alfa-items-surface-color' => trim((string) $params->get('style_text', '')),
            '--alfa-items-muted' => trim((string) $params->get('style_muted', '')),
            '--alfa-items-hairline' => trim((string) $params->get('style_hairline', '')),
            '--mod-alfa-items-accent' => trim((string) $params->get('style_accent', '')),
        ] as $token => $value) {
            if ($value !== '') {
                $styleCss .= $token . ':' . $value . ';';
            }
        }

        if ($styleCss !== '') {
            $wa->addInlineStyle('.mod-alfa-items-' . $moduleId . '{' . $styleCss . '}');
        }

        // The dark twin of every colour above. Emitted as its own rule under the same
        // two selectors the module's own dark values use, so a merchant's dark choice
        // replaces the default exactly where that default sat, and a light choice
        // stops leaking into dark, which one value per colour could not avoid.
        $darkCss = '';

        foreach ([
            '--alfa-items-surface' => 'style_surface',
            '--alfa-items-surface-color' => 'style_text',
            '--alfa-items-muted' => 'style_muted',
            '--alfa-items-hairline' => 'style_hairline',
            '--mod-alfa-items-accent' => 'style_accent',
        ] as $token => $param) {
            $value = trim((string) $params->get($param . '_dark', ''));

            if ($value !== '') {
                $darkCss .= $token . ':' . $value . ';';
            }
        }

        if ($darkCss !== '') {
            $wa->addInlineStyle(
                ':is(:root[data-theme="dark"],:root[data-theme-default="dark"]:not([data-theme])) '
                . '.mod-alfa-items-' . $moduleId . '{' . $darkCss . '}'
                . '@media (prefers-color-scheme:dark){'
                . ':is(:root[data-theme="auto"],:root[data-theme-default="auto"]:not([data-theme]),'
                . ':root:not([data-theme]):not([data-theme-default])) '
                . '.mod-alfa-items-' . $moduleId . '{' . $darkCss . '}}',
            );
        }

        // The merchant's own CSS and script, last, so they win over everything above.
        // Neither may close the element it is written into.
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
