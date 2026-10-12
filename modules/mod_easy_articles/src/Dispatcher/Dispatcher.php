<?php

/**
 * @package    Easy Articles
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Easy\Module\EasyArticles\Site\Dispatcher;

use Easy\Module\EasyArticles\Site\Helper\EasyArticlesHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher for mod_easy_articles.
 *
 *   dispatch()       -> records the article being read, resolves the groups, registers the assets, renders (parent)
 *   getLayoutData()  -> the data the layout needs, nothing else
 *
 * A module with nothing to show renders nothing at all: the groups are resolved
 * before any asset is registered, so an empty "recently read" costs the page
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
     * Record the article on screen, resolve what the module shows, register the
     * assets, then render.
     *
     *
     * @since  1.0.0
     */
    public function dispatch(): void
    {
        $params = new Registry($this->module->params);

        // The module's strings are needed before the parent loads them: a tab
        // without a label is named after its source.
        $this->app->getLanguage()->load('mod_easy_articles', JPATH_SITE);

        EasyArticlesHelper::recordRead();

        $this->groups = EasyArticlesHelper::getGroups(params: $params, moduleId: (int) $this->module->id);

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
        $data['display'] = EasyArticlesHelper::displayOptions($data['params']);
        $data['lazyUrl'] = EasyArticlesHelper::lazyUrl((int) $data['module']->id);

        return $data;
    }

    /**
     * Register the module's assets: its own style and script, and the slider
     * library it ships only when a tab is a slider.
     *
     * @param Registry $params Module parameters.
     * @param int $moduleId Module instance id.
     *
     *
     * @since  1.0.0
     */
    private function registerAssets(Registry $params, int $moduleId): void
    {
        $wa = $this->app->getDocument()->getWebAssetManager();

        $wa->getRegistry()->addExtensionRegistryFile('mod_easy_articles');

        $wa->useStyle('mod_easy_articles.articles')
            ->useScript('mod_easy_articles.articles');

        if (EasyArticlesHelper::usesSlider($params)) {
            $wa->useStyle('mod_easy_articles.keen-slider')
                ->useScript('mod_easy_articles.keen-slider');
        }

        // Every tab's gap and grid columns, on wide screens and at the module's two widths.
        $wa->addInlineStyle(EasyArticlesHelper::responsiveCss(moduleId: $moduleId, params: $params, groups: $this->groups));

        // The cards: what every place (a tab) sets, on every screen and per breakpoint.
        // For every tab, fetched yet or not, so one opened later is styled.
        $cards = EasyArticlesHelper::lookCss(moduleId: $moduleId, params: $params, groups: $this->groups);

        if ($cards !== '') {
            $wa->addInlineStyle($cards);
        }

        // Only what the merchant actually set. An unconditional declaration here would
        // outrank both the module's own light/dark values and the site's colours,
        // turning an untouched setting into a hardcoded one.
        $tokens = [
            '--easy-articles-surface' => 'style_surface',
            '--easy-articles-surface-color' => 'style_text',
            '--easy-articles-muted' => 'style_muted',
            '--easy-articles-hairline' => 'style_hairline',
            '--mod-easy-articles-accent' => 'style_accent',
        ];

        foreach (['' => '', '_dark' => ''] as $suffix => $css) {
            foreach ($tokens as $token => $param) {
                $value = trim((string) $params->get($param . $suffix, ''));

                if (preg_match('/^#[0-9a-f]{3,8}$/i', $value)) {
                    $css .= $token . ':' . $value . ';';
                }
            }

            if ($css === '') {
                continue;
            }

            if ($suffix === '') {
                $wa->addInlineStyle('.mod-easy-articles-' . $moduleId . '{' . $css . '}');

                continue;
            }

            // The dark twin, under the same two selectors the module's own dark values
            // use, so a merchant's dark choice replaces the default exactly where it sat.
            $wa->addInlineStyle(
                ':is(:root[data-theme="dark"],:root[data-theme-default="dark"]:not([data-theme])) '
                . '.mod-easy-articles-' . $moduleId . '{' . $css . '}'
                . '@media (prefers-color-scheme:dark){'
                . ':is(:root[data-theme="auto"],:root[data-theme-default="auto"]:not([data-theme]),'
                . ':root:not([data-theme]):not([data-theme-default])) '
                . '.mod-easy-articles-' . $moduleId . '{' . $css . '}}',
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
