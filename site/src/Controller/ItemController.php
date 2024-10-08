<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Exception;
use \Joomla\CMS\Application\SiteApplication;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Multilanguage;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\MVC\Controller\BaseController;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use \Joomla\Utilities\ArrayHelper;
use \Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Layout\LayoutHelper;

// use \Alfa\Component\Alfa\Site\Helper\ProductHelper;
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;

/**
 * Item class.
 *
 * @since  1.6.0
 */
class ItemController extends BaseController
{

//     $app = Factory::getApplication();


//         $limit        = $input->getInt('limit', 6);
    // $limitstart   = $input->getInt('limitstart', 0);
    // $keyword = $input->getString('query', '');
    public function recalculate()
    {

        $errorOccured = false;

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $productId = $input->getInt('product_id', 0);

        // $this->app->enqueueMessage('to minima 1');

        // $this->app->enqueueMessage('egine ekeino');

//		$model = $this->getModel('Item');
//		$item = $model->getItem($id);

        // $data = ['thesi1'=>'periexomeno 1','asda'=>'awkdawodwa'];
        // $items = $this->getModel()->getItems();

        $calculator = new PriceCalculator($productId, $quantity, $userGroupId, $currencyId);

        // Calculate price
        $price = $calculator->calculatePrice();

        $priceLayout = LayoutHelper::render('price', $price);

        // $price = ProductHelper::getPrices($productId,$quantity);

        $response = new JsonResponse($priceLayout, 'Prices return successfully', $errorOccured);
// echo $productId;

        echo $response;
        $this->app->close();
        // echo json_encode('test');
        // exit;
    }

    public function addProductsToDatabase($cartId, $productId, $quantity)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $productColumns = array(
            $db->quoteName('id_cart') . ' = ' . $db->quote($cartId),
            $db->quoteName('id_item') . ' = ' . $db->quote($productId),
            $db->quoteName('quantity') . ' = ' . $db->quote($quantity),
            $db->quoteName('date_add') . ' = ' . $db->quote(Factory::getDate()->toSql()),
        );

        $query = $db->getQuery(true);
        $query->insert('#__alfa_cart_items')
            ->set($productColumns);
        $db->setQuery($query);
        $db->execute();
    }

    public function addToCart()
    {
        $errorOccured = false;

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $productId = $input->getInt('product_id', 0);
        $userId = Factory::getApplication()->getIdentity()->id;
        $data = [];

        $cookieName = 'recognize_key';
        $cookieValue = rand();//TODO: something more specific to the user computer to be surely unique
        $rkCookie = $this->app->input->cookie->get($cookieName, '');

        if ($rkCookie == '') {
            // Define the cookie parameters
            $expires = time() + 3600 * 24; // Cookie expires in 1 hour
            $path = '/'; // Cookie is available across the entire domain
            $domain = ''; // Leave empty for the current domain
            $secure = true; // Set to true if using HTTPS
            $httponly = true; // Cookie is not accessible via JavaScript
            $samesite = 'Strict'; // Can be 'Strict', 'Lax', or 'None'
            $this->app->input->cookie->set(
                $cookieName,
                $cookieValue,
                [
                    'expires' => $expires,
                    'path' => $path,
                    'domain' => $domain,
                    'secure' => $secure,
                    'httponly' => $httponly,
                    'samesite' => $samesite
                ]);
            $rkCookie = $this->app->input->cookie->get($cookieName, $cookieValue);
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $query = $db->getQuery(true);
            $query->select($db->quoteName('id_cart'))
                ->from($db->quoteName('#__alfa_cart'));
            if ($userId > 0) {
                $query->where($db->quoteName('id_customer') . ' = ' . $db->quote($userId));
            } else {
                $query->where($db->quoteName('recognize_key') . ' = ' . $db->quote($rkCookie));
            }
            $db->setQuery($query);
            $cartId = intval($db->loadResult());

            if (!$cartId) {

                $cartObject = new \stdClass();
                $cartObject->id_shop_group = 1;
                $cartObject->id_carrier = 1;
                $cartObject->delivery_option = 'walking';
                $cartObject->id_lang = 1;
                $cartObject->id_address_delivery = 1;
                $cartObject->id_address_invoice = 1;
                $cartObject->id_currency = 1;
                $cartObject->id_customer = $userId;
                $cartObject->date_add = Factory::getDate()->toSql();
                $cartObject->date_upd = Factory::getDate()->toSql();
                $cartObject->recognize_key = $rkCookie;

                $db->insertObject('#__alfa_cart', $cartObject);
                $cartId = $db->insertid();

            }

            $this->addProductsToDatabase($cartId, $productId, $quantity);
            $data['insert_query'] = ['success', $cartId]; //TODO: remove ids from errors

        } catch (Exception $e) {
            $data['error_message'] = $e->getMessage(); //TODO: remove ids from errors
            $errorOccured = true;
        }

        $response = new JsonResponse($data, $errorOccured ? 'Item failed to be added' : 'Item added successfully', $errorOccured);
        echo $response;
        $this->app->close();
        // $response = new JsonResponse($priceLayout,'Prices return successfully',$errorOccured);
        // echo $productId;
        // echo json_encode('test');
        // exit;
    }


}
