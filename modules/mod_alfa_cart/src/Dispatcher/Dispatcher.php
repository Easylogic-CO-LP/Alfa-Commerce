<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright   (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCart\Site\Dispatcher;

use Alfa\Component\Alfa\Site\Helper\CartHelper;
use Alfa\Module\AlfaCart\Site\Helper\AlfaCartHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher class for mod_alfa-cart
 *
 * @since  4.4.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * Returns the layout data.
     *
     * @return array
     *
     * @since   4.4.0
     */
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();
        $app = $data['app'];
        $input = $data['input'];
        $params = $data['params'];

        $data['cart'] = new CartHelper();
        $option = $input->getCmd('option'); // Get current component

        // Load language only if we're not in com_alfa
        if ($option !== 'com_alfa') {
            $app->getLanguage()->load('com_alfa');
        }

        $wa = $this->app->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_alfa');
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_cart');
        $wa->useStyle('mod_alfa_cart.cart')
            ->useScript('mod_alfa_cart.cart');

        // for quantity and recalculate css and js funcitonallity
        // $wa->useStyle('com_alfa.item')
        // ->useScript('com_alfa.item.recalculate');

        $togglerColor = $params->get('togglerColor', '#FFFFFF');
        $togglerCounterColor = $params->get('togglerCounterColor', '#FF0000');
        $togglerCounterValue = $params->get('togglerCounterValue', 0);

        // set in css the variable that sets the background color of the toggler
        $wa->addInlineStyle(':root{
            --mod-alfa-cart-counter-color: ' . $togglerCounterColor . ';
            --mod-alfa-cart-toggler-color: ' . $togglerColor . ';
        }');

        // set counter for the first time the file loaded
        $data['togglerCounter'] = (intval($togglerCounterValue) ? $data['cart']->getTotalItems() : $data['cart']->getTotalQuantity());
        // pass the setting into the javascript file as variable modAlfaCartTogglerCounterValue
        $wa->addInlineScript('var modAlfaCartTogglerCounterValue = "' . intval($togglerCounterValue) . '";');

        $data['priceSettings'] = AlfaCartHelper::buildPriceSettings($params);

        return $data;
    }
}
