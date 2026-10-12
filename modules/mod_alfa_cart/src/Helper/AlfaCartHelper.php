<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCart\Site\Helper;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartResolution;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class AlfaCartHelper
{
    /**
     * @throws Exception
     * @since  1.0.0
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

        $snapshot = CartService::ambient()->read(CartResolution::Current);

        $displayData = [
            'snapshot' => $snapshot,
            'priceSettings' => $priceSettings,
        ];

        $layout = new FileLayout('default_items', JPATH_ROOT . '/modules/mod_alfa_cart/tmpl/');
        $result = $layout->render($displayData);
        $response_data = [
            'tmpl' => $result,
            'total_quantity' => $snapshot->quantity,
            'total_items' => $snapshot->itemCount,
            'priceSettings' => $priceSettings,
            // 'isEmpty' => $isEmpty
        ];

        // Send the response as JSON
        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, 'Module cart layout loaded!', false);
        echo $response;

        $app->close();
    }
    /**
     * AJAX endpoint: add the requested item/quantity to the cart, then return the re-rendered cart-items
     * layout plus totals as JSON. Closes the application after responding.
     *
     * @return void
     *
     * @throws Exception
     * @since  1.0.0
     */
    public static function setQuantityAjax()
    {
        if (!$module = ModuleHelper::getModule('mod_alfa_cart')) {
            return;
        }

        $app = Factory::getApplication();
        $input = $app->input;

        // CSRF: this mutates the cart, so a forged cross-site request must be rejected.
        if (!Session::checkToken('post')) {
            header('Content-Type: application/json');
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN_NOTICE'), true);
            $app->close();
        }

        $params = new Registry($module->params);

        $lang = $app->getLanguage();
        $lang->load('mod_alfa_cart');
        $lang->load('com_alfa');

        $app->getSession()->set('application.queue', $app->getMessageQueue());

        $lineId = $input->getInt('id_line', 0);
        $quantity = $input->getInt('quantity', 1);

        $priceSettings = self::buildPriceSettings($params);

        // Line-based SET (not add): the quantity is clamped to stock and the
        // line removed when it reaches 0, and ownership is scoped to this cart.
        // What comes back is the verdict and a reading of the cart the change
        // left behind, never the thing that made it.
        $outcome = CartService::ambient()->setLineQuantity($lineId, $quantity);
        $errorOccurred = !$outcome->succeeded;
        $snapshot = $outcome->snapshot;

        // The drawer answers every change, a refused one included, with the
        // cart as it stands and what the cart said about the change inside it,
        // taken off the queue so it is said once.
        $displayData = [
            'snapshot' => $snapshot,
            'priceSettings' => $priceSettings,
            'messages' => $app->getMessageQueue(true),
        ];

        $layout = new FileLayout('default_items', JPATH_ROOT . '/modules/mod_alfa_cart/tmpl/');
        $result = $layout->render($displayData);

        $response_data = [
            'tmpl' => $result,
            'total_quantity' => $snapshot->quantity,
            'total_items' => $snapshot->itemCount,
        ];

        header('Content-Type: application/json');
        $response = new JsonResponse(
            $response_data,
            $errorOccurred ? Text::_('MOD_ALFA_CART_UPDATE_FAILED') : Text::_('MOD_ALFA_CART_UPDATE_SUCCESS'),
            $errorOccurred,
        );
        echo $response;

        $app->close();
    }

    /**
     * Build the price-display settings array from the module parameters, toggling each price component
     * (and its label) according to the corresponding _show / _show_label params.
     *
     * @param Registry $params The module parameters.
     *
     * @return array The resolved price settings for the cart layout.
     * @since  1.0.0
     */
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
