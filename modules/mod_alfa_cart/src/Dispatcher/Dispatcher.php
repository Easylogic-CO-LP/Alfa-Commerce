<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCart\Site\Dispatcher;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartResolution;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Alfa\Module\AlfaCart\Site\Helper\AlfaCartHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Session\Session;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher class for mod_alfa-cart
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
        $app = $data['app'];
        $input = $data['input'];
        $params = $data['params'];

        // The module reads; it hands the layout a reading, not the cart.
        $data['snapshot'] = CartService::ambient()->read(CartResolution::Current);
        $option = $input->getCmd('option'); // Get current component

        // Load language only if we're not in com_alfa
        if ($option !== 'com_alfa') {
            $app->getLanguage()->load('com_alfa');
        }

        $wa = $this->app->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_alfa');
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_cart');
        $wa->useStyle('com_alfa.tokens')
            ->useStyle('com_alfa.stepper')
            ->useStyle('mod_alfa_cart.cart')
            ->useScript('mod_alfa_cart.cart');

        // The mini-cart's quantity/remove AJAX is CSRF-protected; publish the token
        // so its script can send it (the module renders on any page, so it can't
        // rely on another extension having registered it).
        $this->app->getDocument()->addScriptOptions('csrf.token', Session::getFormToken());

        // for quantity and recalculate css and js funcitonallity
        // $wa->useStyle('com_alfa.item')
        // ->useScript('com_alfa.item.recalculate');

        $togglerCounterValue = $params->get('togglerCounterValue', 0);

        // Only what the merchant actually chose. A variable written here ALWAYS wins
        // over the stylesheet's own value, so writing one unconditionally makes that
        // value dead, which is how the cart icon ended up painted white on a white
        // header. Left unset, each token keeps the module's own colour for the mode
        // the page is in, and the storefront palette wherever that is loaded.
        $px = static fn (string $v): string => trim($v) === '' ? '' : max(0, min(80, (int) $v)) . 'px';

        // Which module token each setting drives. The same list is read twice: once
        // for the light value, once for the `_dark` twin.
        $tokens = [
            '--mod-alfa-cart-counter-color' => 'togglerCounterColor',
            '--mod-alfa-cart-toggler-color' => 'togglerColor',
            '--mod-alfa-cart-checkout-color' => 'checkoutColor',
            '--mod-alfa-cart-checkout-text-color' => 'checkoutTextColor',
            '--alfa-cart-surface' => 'style_surface',
            '--alfa-cart-surface-color' => 'style_text',
            '--alfa-cart-muted' => 'style_muted',
            '--alfa-cart-hairline' => 'style_hairline',
        ];

        $declarations = function (string $suffix) use ($params, $tokens): string {
            $css = '';

            foreach ($tokens as $token => $param) {
                $value = trim((string) $params->get($param . $suffix, ''));

                if ($value !== '') {
                    $css .= $token . ':' . $value . ';';
                }
            }

            return $css;
        };

        // Rounding is a shape, not a colour: one value serves both modes.
        $light = $declarations('') . (static function () use ($px, $params): string {
            $radius = $px((string) $params->get('style_radius', ''));

            return $radius === '' ? '' : '--alfa-cart-radius:' . $radius . ';';
        })();
        $dark = $declarations('_dark');

        $scope = '.mod-alfa-cart-' . (int) $data['module']->id;
        $cartCss = '';

        if ($light !== '') {
            // Scoped to THIS instance, not :root. A token written at :root is further
            // from the element than the stylesheet's own `.mod-alfa-cart` declaration,
            // so it would lose to it, and two carts on a page could not differ.
            $cartCss .= $scope . '{' . $light . '}';
        }

        if ($dark !== '') {
            // The same two selectors the module's own dark values use, so the merchant's
            // choice sits exactly where the default it replaces sat. Keyed on what the
            // PAGE declares: a visitor who picks light on a dark device must get light.
            $cartCss .= ':is(:root[data-theme="dark"],:root[data-theme-default="dark"]:not([data-theme])) '
                . $scope . '{' . $dark . '}'
                . '@media (prefers-color-scheme:dark){'
                . ':is(:root[data-theme="auto"],:root[data-theme-default="auto"]:not([data-theme]),'
                . ':root:not([data-theme]):not([data-theme-default])) '
                . $scope . '{' . $dark . '}}';
        }

        if ($cartCss !== '') {
            $wa->addInlineStyle($cartCss);
        }

        // set counter for the first time the file loaded
        $data['togglerCounter'] = intval($togglerCounterValue)
            ? $data['snapshot']->itemCount
            : $data['snapshot']->quantity;
        // pass the setting into the javascript file as variable modAlfaCartTogglerCounterValue
        $wa->addInlineScript('var modAlfaCartTogglerCounterValue = "' . intval($togglerCounterValue) . '";');

        $data['priceSettings'] = AlfaCartHelper::buildPriceSettings($params);

        return $data;
    }
}
