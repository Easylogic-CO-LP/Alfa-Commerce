<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Cart\Method;
use Joomla\CMS\Document\HtmlDocument;

/**
 * What a payment or shipping plugin is asked to load for the cart page.
 *
 * ASKED ONCE PER PLUGIN, WHILE THE PAGE IS BUILT, never per method and
 * never inside an AJAX answer. A page cannot take a script once its head is
 * compiled, and a JSON answer has no head at all; so every plugin that owns
 * a published method is asked on the HTML render, whether or not the cart
 * offers that method right now. A carrier that becomes eligible after a
 * country change then finds its script already on the page.
 *
 * The plugin's published methods travel with the request, for a declaration
 * that depends on how the merchant configured them. What varies per method
 * while the shopper checks out belongs on the panel as data attributes,
 * where the JSON re-render refreshes it, not here.
 *
 * @since  1.10.0
 */
final class CartAssetsRequest
{
    /**
     * @param string $kind 'payment' or 'shipment'.
     * @param Method[] $methods This plugin's published methods of that kind, keyed by id.
     * @param HtmlDocument $document The page being built.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $methods,
        public readonly HtmlDocument $document,
    ) {
    }
}
