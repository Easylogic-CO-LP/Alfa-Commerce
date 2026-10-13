<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\CartAssetsRequest;
use Alfa\Component\Alfa\Site\Service\Cart\Method;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\WebAsset\WebAssetManager;

/**
 * The cart page asking a carrier what code its panel needs.
 *
 * Fired once per plugin during the HTML render of the storefront cart and
 * the order builder, before the head is compiled, so what is declared on
 * the Web Asset Manager here lands in <head>. The panel itself is drawn by
 * onCartView, which also runs inside JSON answers where nothing can be
 * declared; that is why the two are separate hooks.
 *
 * @since  2.0.0
 */
class CartViewAssetsEvent extends ShipmentsEvent
{
    /**
     * What this plugin is being asked to declare for.
     *
     *
     * @since  2.0.0
     */
    public function getRequest(): CartAssetsRequest
    {
        return $this->getSubject();
    }

    /**
     * 'shipment': the kind of method this plugin answers for.
     *
     *
     * @since  2.0.0
     */
    public function getKind(): string
    {
        return $this->getRequest()->kind;
    }

    /**
     * This plugin's published methods, keyed by id: offered for the cart or
     * not, because the assets are declared before the offer can narrow. None
     * is marked selected: a selection cannot matter to what a page loads.
     *
     * @return Method[]
     *
     * @since  2.0.0
     */
    public function getMethods(): array
    {
        return $this->getRequest()->methods;
    }

    /**
     * The page being built, for addScriptOptions() and Text::script().
     *
     *
     * @since  2.0.0
     */
    public function getDocument(): HtmlDocument
    {
        return $this->getRequest()->document;
    }

    /**
     * Where to declare the script and the stylesheet.
     *
     *
     * @since  2.0.0
     */
    public function getWebAssetManager(): WebAssetManager
    {
        return $this->getDocument()->getWebAssetManager();
    }
}
