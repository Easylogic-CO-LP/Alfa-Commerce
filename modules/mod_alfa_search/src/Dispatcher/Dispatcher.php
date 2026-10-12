<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaSearch\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Router\Route;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher class for mod_alfasearch
 *
 * @since  1.0.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
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

        $wa = $this->app->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_search');
        // The storefront palette is com_alfa's asset and this module renders on any page,
        // including ones where the component never runs and would otherwise leave every
        // --alfa-* token undefined. The owner's registry must be added before the name is
        // used: useStyle() throws UnknownAssetException otherwise.
        $wa->getRegistry()->addExtensionRegistryFile('com_alfa');
        $wa->useScript('mod_alfa_search.searchbar')
            ->useStyle('com_alfa.tokens')
            ->useStyle('mod_alfa_search.searchbar');

        // Only what the merchant actually set. An unconditional declaration here would
        // outrank both the module's own light/dark values and the storefront palette,
        // turning an untouched setting into a hardcoded one.
        $params = $data['params'];
        $styleCss = '';
        $radius = trim((string) $params->get('style_radius', ''));

        foreach ([
            '--alfa-search-surface' => trim((string) $params->get('style_surface', '')),
            '--alfa-search-surface-color' => trim((string) $params->get('style_text', '')),
            '--alfa-search-muted' => trim((string) $params->get('style_muted', '')),
            '--alfa-search-hairline' => trim((string) $params->get('style_hairline', '')),
            '--alfa-search-radius' => $radius === '' ? '' : max(0, min(80, (int) $radius)) . 'px',
        ] as $token => $value) {
            if ($value !== '') {
                $styleCss .= $token . ':' . $value . ';';
            }
        }

        if ($styleCss !== '') {
            $wa->addInlineStyle('.alfasearch-wrapper-' . (int) $data['module']->id . '{' . $styleCss . '}');
        }

        // The dark twin of every colour above. Emitted as its own rule under the same
        // two selectors the module's own dark values use, so a merchant's dark choice
        // replaces the default exactly where that default sat, and a light choice
        // stops leaking into dark, which one value per colour could not avoid.
        $darkCss = '';

        foreach ([
            '--alfa-search-surface' => 'style_surface',
            '--alfa-search-surface-color' => 'style_text',
            '--alfa-search-muted' => 'style_muted',
            '--alfa-search-hairline' => 'style_hairline',
        ] as $token => $param) {
            $value = trim((string) $params->get($param . '_dark', ''));

            if ($value !== '') {
                $darkCss .= $token . ':' . $value . ';';
            }
        }

        if ($darkCss !== '') {
            $wa->addInlineStyle(
                ':is(:root[data-theme="dark"],:root[data-theme-default="dark"]:not([data-theme])) '
                . '.alfasearch-wrapper-' . (int) $data['module']->id . '{' . $darkCss . '}'
                . '@media (prefers-color-scheme:dark){'
                . ':is(:root[data-theme="auto"],:root[data-theme-default="auto"]:not([data-theme]),'
                . ':root:not([data-theme]):not([data-theme-default])) '
                . '.alfasearch-wrapper-' . (int) $data['module']->id . '{' . $darkCss . '}}',
            );
        }

        // Route::_ roots what it returns (SiteRouter::buildBase() prepends Uri::root(true) to
        // every path it builds), so nothing prefixes the base here. Concatenating Uri::base(true)
        // in front of it emitted the base TWICE (/shop/shop/...) on any install below the document
        // root, and nothing at all on one at the root, which is why it went unseen.
        $data['formAction'] = Route::_('index.php?option=com_alfa&view=items&category_id=0');
        // Route::_ (clean "&") so the com_ajax URL is rooted + SEF/language-aware,
        // not a relative "index.php?..." that the router can't parse (404).
        $data['ajaxAction'] = Route::_('index.php?option=com_ajax&module=alfa_search&method=get&format=json', false);

        $filters = $this->input->get('filter', [], 'array');
        $data['currentSearch'] = $filters['search'] ?? '';

        return $data;
    }
}
