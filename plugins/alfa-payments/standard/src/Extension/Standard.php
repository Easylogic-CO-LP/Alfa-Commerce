<?php

/**
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Standard
 *
 */

namespace Joomla\Plugin\AlfaPayments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
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

    // public function onOrderProcessView($order): string {

        // Log order.
        // $emptyLog = self::createEmptyLog();
        // $emptyLog['order_id'] = $order->id;
        // $emptyLog['status'] = 'P';
        // $emptyLog['order_total'] = $order->original_price;
        // $emptyLog['currency'] = $order->id_currency;
        // $emptyLog['created_on'] = Factory::getDate()->format('Y-m-d H:i:s');
        // $emptyLog['created_by'] = 1;
        // self::insertLog($emptyLog);


        // Insert payment.
        // $emptyPayment = self::createEmptyOrderPayment();
        // $emptyPayment['id_order']           = $order->id;
        // $emptyPayment['id_currency']        = $order->id_currency;
        // $emptyPayment['id_payment_method']  = $order->id_paymentmethod;
        // $emptyPayment['id_user']            = $order->id_user;
        // $emptyPayment['amount']             = $order->original_price;
        // $emptyPayment['conversion_rate']    = 1.00;
        // $emptyPayment['transaction_id']     = null;
        // $emptyPayment['date_add']           = Factory::getDate()->format('Y-m-d H:i:s');
        // self::insertOrderPayment($emptyPayment);

        // Redirect to order completed.
        // $redirectUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_completed';
        // return "";
    // }

    // public function onOrderCompleteView($order): string{
    //     $html = "Η παραγγελία σας έχει καταχωρηθεί. Ευχαριστούμε που μας επιλέξατε!<p>";
    //     return $html;
    // }

}
