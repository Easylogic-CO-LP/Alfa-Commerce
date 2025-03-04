<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\View\Cart;
// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Alfa\Component\Alfa\Site\Helper\OrderHelper;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\Router\Route;
use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    protected $state;

    protected $cart;

    protected $items;

	protected $app;

    protected $payment_methods;

    protected $form;

    protected $params;

    protected $event;
    
    /**
     * Display the view
     *
     * @param   string  $tpl  Template name
     *
     * @return void
     *
     * @throws Exception
     */
    public function display($tpl = null)
    {
        $this->app  = $app = Factory::getApplication();
        $user = $app->getIdentity();
        $input = $app->input;

        $this->state  = $this->get('State');
        $this->cart   = $this->get('Item');
        // $this->items   = $this->get('Items');
        $this->params = $app->getParams('com_alfa');

        // Check for errors.
        if (count($errors = $this->get('Errors')))
        {
            throw new \Exception(implode("\n", $errors));
        }

        if ((!$this->cart->getData() || !is_array($this->cart->getData()->items) || !count($this->cart->getData()->items)) 
            && $this->_layout=='default') {
            $this->_layout = 'default_cart_empty';
        }

        if($this->_layout == 'default') {

            // load each payment method onCartView event
            $onCartViewPaymentEventName = 'onCartView';
            foreach ($this->cart->getPaymentMethods() as &$payment_method) {
                $payment_method->event = new \stdClass();
                $payment_method->event->{$onCartViewPaymentEventName} = ($app->bootPlugin($payment_method->type, "alfa-payments")->{$onCartViewPaymentEventName}($this->cart));
            }
            
        }


        if( $this->_layout == 'default_order_process' ||
            $this->_layout == 'default_order_completed'){
            $orderId = $app->getUserState('com_alfa.order_id');

            if ($orderId == null) {
                $app->enqueueMessage('Order ID is not set.', 'error');
                $app->redirect(Route::_('/index.php'));//redirect to home page
            }

            $ordersModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

            
            $orderData = $ordersModel->getItem($orderId);

            if($orderData == null){
                $app->enqueueMessage('Order with this order id:'.$orderId.' not found.', 'error');
                $app->redirect(Route::_('/index.php'));//redirect to home pagee
            }


            if($this->_layout == 'default_order_process'){
            
                $onProcessPaymentEventName = 'onOrderProcessView';

                //METHOD 1 : OF CALLING A PLUGIN
    //            PluginHelper::importPlugin('alfa-payments', $orderData->payment->type);
    //            $dispatcher = $app->getDispatcher();
    //
    //            $event = \Joomla\CMS\Event\AbstractEvent::create(
    //                $onProcessPaymentEventName,
    //                [
    //                    'subject'=>(object)['event'=>$onProcessPaymentEventName],
    //                    $orderData,
    //                ]
    //            );
    //
    //            $eventResult = $dispatcher->dispatch($onProcessPaymentEventName, $event);
    //            $this->event->{$onProcessPaymentEventName} = $eventResult['result'][0];

                //METHOD 2 : OF CALLING A PLUGIN

                $onProcessPaymentEventResult = $app->bootPlugin($orderData->payment->type, "alfa-payments")->{$onProcessPaymentEventName}($orderData);

                $this->event = new \stdClass();
                $this->event->{$onProcessPaymentEventName} = $onProcessPaymentEventResult;

                // Empty string returned, redirecting to default_order_completed.
                if(empty($onProcessPaymentEventResult)){

                    $this->_layout = 'default_order_completed';
                }
                
            }


            if($this->_layout == 'default_order_completed'){
                $onCompleteOrderEventName = 'onOrderCompleteView';
                $onCompleteOrderEventResult = $app->bootPlugin($orderData->payment->type, "alfa-payments")->{$onCompleteOrderEventName}($orderData);

                $this->event = new \stdClass();
                $this->event->{$onCompleteOrderEventName} = $onCompleteOrderEventResult;
            }


        }
        

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Prepares the document
     *
     * @return void
     *
     * @throws Exception
     */
    protected function _prepareDocument()
    {
        $app   = Factory::getApplication();
        $menus = $app->getMenu();
        $title = null;

        // Because the application sets a default page title,
        // We need to get it from the menu manufacturer itself
        $menu = $menus->getActive();

        if ($menu)
        {
            $this->params->def('page_heading', $this->params->get('page_title', $menu->title));
        }
        else
        {
            $this->params->def('page_heading', Text::_('COM_ALFA_DEFAULT_PAGE_TITLE'));
        }

        // $title = $this->item->name;

        // if (empty($title))
        // {
        //     $title = $app->get('sitename');
        // }
        // elseif ($app->get('sitename_pagetitles', 0) == 1)
        // {
        //     $title = Text::sprintf('JPAGETITLE', $app->get('sitename'), $title);
        // }
        // elseif ($app->get('sitename_pagetitles', 0) == 2)
        // {
        //     $title = Text::sprintf('JPAGETITLE', $title, $app->get('sitename'));
        // }

        // $this->document->setTitle($title);

        // if ($this->params->get('menu-meta_description'))
        // {
        //     $this->document->setDescription($this->params->get('menu-meta_description'));
        // }

        // if ($this->params->get('menu-meta_keywords'))
        // {
        //     $this->document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
        // }

        // if ($this->params->get('robots'))
        // {
        //     $this->document->setMetadata('robots', $this->params->get('robots'));
        // }

        // // Add Breadcrumbs
        // $pathway = $app->getPathway();
        // $breadcrumbList = Text::_('COM_ALFA_TITLE_ITEMS');

        // if(!in_array($breadcrumbList, $pathway->getPathwayNames())) {
        //     $pathway->addItem($breadcrumbList, "index.php?option=com_alfa&view=items");
        // }
        // $breadcrumbTitle = $this->item->name;

        // if(!in_array($breadcrumbTitle, $pathway->getPathwayNames())) {
        //     $pathway->addItem($breadcrumbTitle);
        // }
    }

}
