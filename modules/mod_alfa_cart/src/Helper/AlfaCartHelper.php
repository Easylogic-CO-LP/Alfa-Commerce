<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCart\Site\Helper;

use Alfa\Component\Alfa\Site\Helper\CartHelper;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class AlfaCartHelper
{
    /**
     * @throws Exception
     */

    public static function reloadAjax()
    {
        if (!$module = ModuleHelper::getModule('mod_alfa_cart')) {
            return;
        }

        $app = Factory::getApplication();
        $params = new Registry($module->params);

        $priceSettings = self::buildPriceSettings($params);

        $lang = $app->getLanguage();
        $lang->load('mod_alfa_cart');
        $lang->load('com_alfa');

        $cart = new CartHelper();
        //        $isEmpty = $cart->isEmpty();

        $displayData = [
            'cart' => $cart,
            'priceSettings' => $priceSettings,
        ];

        $layout = new FileLayout('default_items', JPATH_ROOT . '/modules/mod_alfa_cart/tmpl/');
        $result = $layout->render($displayData);
        $response_data = [
            'tmpl' => $result,
            'total_quantity' => $cart->getTotalQuantity(),
            'total_items' => $cart->getTotalItems(),
            'priceSettings' => $priceSettings,
            // 'isEmpty' => $isEmpty
        ];

        // Send the response as JSON
        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, 'Module cart layout loaded!', false);
        echo $response;

        $app->close();
    }
    public static function getAjax()
    {
        if (!$module = ModuleHelper::getModule('mod_alfa_cart')) {
            return;
        }

        $app = Factory::getApplication();
        $input = $app->input;

        $params = new Registry($module->params);

        $lang = $app->getLanguage();
        $lang->load('mod_alfa_cart');
        $lang->load('com_alfa');

        $cartItems = [];

        $app->getSession()->set('application.queue', $app->getMessageQueue());

        $errorOccured = false;

        $itemId = $input->getInt('id_item', 0);
        $quantity = $input->getInt('quantity', 1);
        $userId = Factory::getApplication()->getIdentity()->id;

        $priceSettings = self::buildPriceSettings($params);

        $cart = new CartHelper();

        $errorOccured = !$cart->addToCart($itemId, $quantity);

        $result = '';

        $isEmpty = $cart->isEmpty();

        $displayData = [
            'cart' => $cart,
            'priceSettings' => $priceSettings,
        ];

        $layout = new FileLayout('default_items', JPATH_ROOT . '/modules/mod_alfa_cart/tmpl/');
        $result = $layout->render($displayData);

        $response_data = [
            'tmpl' => $result,
            'total_quantity' => $cart->getTotalQuantity(),
            'total_items' => $cart->getTotalItems(),
            // 'isEmpty' => $isEmpty
        ];

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccured ? 'Item Failed to be updated!' : 'Item successfully updated!', $errorOccured);
        echo $response;

        $app->close();
    }

    public static function buildPriceSettings(Registry $params): array
    {
        $builder = PriceSettings::make();

        if ($params->get('base_price_show')) {
            $builder->show(
                'base_price',
                (bool) $params->get('base_price_show_label', 1),
            );
        }

        if ($params->get('discount_amount_show')) {
            $builder->show(
                'discount_amount',
                (bool) $params->get('discount_amount_show_label', 1),
            );
        }

        if ($params->get('base_price_with_discounts_show')) {
            $builder->show(
                'base_price_with_discounts',
                (bool) $params->get('base_price_with_discounts_show_label', 1),
            );
        }

        if ($params->get('tax_amount_show')) {
            $builder->show(
                'tax_amount',
                (bool) $params->get('tax_amount_show_label', 1),
            );
        }

        if ($params->get('base_price_with_tax_show')) {
            $builder->show(
                'base_price_with_tax',
                (bool) $params->get('base_price_with_tax_show_label', 1),
            );
        }

        if ($params->get('final_price_show', 1)) {
            $builder->show(
                'final_price',
                (bool) $params->get('final_price_show_label', 0),
            );
        }

        if ($params->get('price_breakdown_show')) {
            $builder->show('price_breakdown');
        }

        return $builder->get();
    }
}
