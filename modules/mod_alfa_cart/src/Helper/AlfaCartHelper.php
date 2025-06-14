<?php

namespace Alfa\Module\AlfaCart\Site\Helper;

use Alfa\Component\Alfa\Site\Helper\CartHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\Registry\Registry;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Response\JsonResponse;

defined('_JEXEC') or die;

class AlfaCartHelper
{
    /**
     * @throws \Exception
     */

    public static function reloadAjax()
    {
        if (!$module = ModuleHelper::getModule('mod_alfa_cart')) {
            return;
        }

        $app = Factory::getApplication();
        $params = new Registry($module->params);

        $lang = $app->getLanguage();
        $lang->load('mod_alfa_cart');
        $lang->load('com_alfa');

        $cart = new CartHelper();
//        $isEmpty = $cart->isEmpty();

        $layout = new FileLayout('default_items', JPATH_ROOT . '/modules/mod_alfa_cart/tmpl/');
        $result = $layout->render($cart);
        $response_data = array(
            'tmpl' => $result,
            'total_quantity' => $cart->getTotalQuantity(),
            'total_items' => $cart->getTotalItems(),
            // 'isEmpty' => $isEmpty
        );

        // Send the response as JSON
        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, 'Module cart layout loaded!' , false);
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

        $cart = new CartHelper();

        $errorOccured = !$cart->addToCart($itemId,$quantity);

        $result = '';

        $isEmpty = $cart->isEmpty();

        $layout = new FileLayout('default_items', JPATH_ROOT . '/modules/mod_alfa_cart/tmpl/');
        $result = $layout->render($cart);

        $response_data = array(
            'tmpl' => $result,
            'total_quantity' => $cart->getTotalQuantity(),
            'total_items' => $cart->getTotalItems(),
            // 'isEmpty' => $isEmpty
        );

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccured ? 'Item Failed to be updated!' : 'Item successfully updated!', $errorOccured);
        echo $response;

        $app->close();
    }
}
