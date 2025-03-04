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
use Joomla\CMS\Session\Session;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\User\UserFactoryInterface;
use \Joomla\Utilities\ArrayHelper;
use \Joomla\CMS\Response\JsonResponse;
use \Joomla\CMS\Layout\LayoutHelper;
use \Joomla\CMS\Layout\FileLayout;

use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;
// use \Alfa\Component\Alfa\Site\Helper\CartHelper;
use \Alfa\Component\Alfa\Site\Helper\OrderPlaceHelper;


/**
 * Item class.
 *
 * @since  1.6.0
 */
class PaymentController extends BaseController
{

    public function response(){
            $app = $this->app;
//echo "aaa";

//            exit;
            
            // echo '<pre>';
            // print_r($app->input->server->get('REQUEST_URI', '', 'STRING'));
            // echo '</pre>';
            // exit;
//            $app->setUserState('com_alfa.order_id', 1);
            $orderId = $app->getUserState('com_alfa.order_id');

//            $orderId = 1;

            if ($orderId == null) {
                $app->enqueueMessage('Order ID is not set.', 'error');
                $app->redirect(Route::_('/index.php'));//redirect to home page
            }

            $ordersModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

            
            $orderData = $ordersModel->getItem($orderId);

            if($orderData == null){
                $app->enqueueMessage('Order with this order id:'.$orderId.' not found.', 'error');
                $app->redirect(Route::_('/index.php'));//redirect to home page
            }
            
            $onResponsePaymentEventName = 'onPaymentResponse';

            $onResponsePaymentEventResult = $app->bootPlugin($orderData->payment->type, "alfa-payments")->{$onResponsePaymentEventName}($orderData);
            
//            $app->close();
            // $this->event = new \stdClass();
            // $this->event->{$onResponsePaymentEventName} = $onResponsePaymentEventResult;
    }


}
