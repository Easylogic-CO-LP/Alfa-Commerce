<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Standard
 *
 */

namespace Joomla\Plugin\AlfaPayments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Joomla\CMS\Factory;

// use Joomla\CMS\Component\ComponentHelper;
// use Joomla\CMS\Factory;
// use Joomla\CMS\Layout\FileLayout;
// use Joomla\CMS\Plugin\PluginHelper;
// use \Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fields Text Plugin
 *
 * @since  3.7.0
 */
final class Standard extends PaymentsPlugin
{

    public function onOrderCompleteView($event){
        $order = $event->getOrder();
        $method = $event->getMethod();

        $layoutData = [
            'method'    => $method,
            'order'     => $order,
        ];

        $event->setLayout('default_payment_success');
        $event->setLayoutData($layoutData);
    }



    public function onOrderProcessView($event) {

        $order = $event->getOrder();

        // Insert payment.
        $emptyPayment = self::createEmptyOrderPayment();
        $emptyPayment['id_order']           = $order->id;
        $emptyPayment['id_currency']        = $order->id_currency;
        $emptyPayment['id_payment_method']  = $order->id_payment_method;
        $emptyPayment['id_user']            = $order->id_user;
        $emptyPayment['amount']             = $order->original_price;
        $emptyPayment['conversion_rate']    = 1.00;
        $emptyPayment['transaction_id']     = null;
        $emptyPayment['added']           = Factory::getDate()->format('Y-m-d H:i:s');
        $id_order_payment = self::insertOrderPayment($emptyPayment);

        // Log order.
        $emptyLog = self::createEmptyLog();
        $emptyLog['order_id'] = $order->id;
        $emptyLog['id_order_payment'] = $id_order_payment;
        $emptyLog['status'] = 'P';
        $emptyLog['order_total'] = $order->original_price;
        $emptyLog['currency'] = $order->id_currency;
        $emptyLog['created_on'] = Factory::getDate()->format('Y-m-d H:i:s');
        $emptyLog['created_by'] = 1;
        self::insertLog($emptyLog);

        // Redirect to order completed.
        $event->setRedirectUrl("index.php?option=com_alfa&view=cart&layout=default_order_completed");
    }

    // public function onOrderCompleteView($order): string{
    //     $html = "Η παραγγελία σας έχει καταχωρηθεί. Ευχαριστούμε που μας επιλέξατε!<p>";
    //     return $html;
    // }

}
