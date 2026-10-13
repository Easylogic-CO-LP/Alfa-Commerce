<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentCheckupEvent;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentEnvironment;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Throwable;

/**
 * Draws the checkup card of a payment method: loads the saved method, asks its
 * gateway plugin for the steps (onPaymentCheckup), and renders them.
 *
 * Shared by the settings field, which draws the card on page load, and the
 * "Check connection" endpoint, which draws it again after the plugin has
 * asked its provider, so both produce the same markup from the same path.
 *
 * @since  2.0.0
 */
final class PaymentCheckupHelper
{
    /**
     * @param int $methodId The payment method (#__alfa_payments.id); 0 while unsaved.
     * @param string $variant 'sandbox' or 'live' (a credential set's card), or 'general'.
     * @param bool $check True when the operator pressed "Check connection".
     * @param string $domId DOM id for the card, so the button can swap it in place.
     * @param string $title Translated card title.
     *
     * @return string The card's HTML.
     *
     * @since  2.0.0
     */
    public static function render(int $methodId, string $variant, bool $check, string $domId, string $title): string
    {
        // A pressed "Check connection" answers with the card open, so the
        // result is on screen instead of behind a collapsed header.
        $data = ['id' => $domId, 'title' => $title, 'steps' => [], 'notice' => '', 'check' => null, 'webhook' => '', 'return' => '', 'open' => $check, 'active' => null];

        $method = $methodId > 0 ? self::loadMethod($methodId) : null;

        if ($method === null) {
            $data['notice'] = Text::_('COM_ALFA_CHECKUP_SAVE_FIRST');

            return self::draw($data);
        }

        // Whether this card's set is the one new payments use, as saved; set
        // before the notices below, so they carry the badge too.
        if ($variant !== 'general') {
            try {
                $data['active'] = PaymentEnvironment::active(params: (string) $method->params) === $variant;
            } catch (Throwable $e) {
                // Unreadable saved settings: no badge; the detail goes to the log.
                Log::add('Payment checkup of method #' . $methodId . ': its saved settings cannot be read: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            }
        }

        $type = (string) $method->type;

        if ($type === '' || !PluginHelper::isEnabled('alfa-payments', $type)) {
            $data['notice'] = Text::_('COM_ALFA_CHECKUP_PLUGIN_DISABLED');

            return self::draw($data);
        }

        $plugin = Factory::getApplication()->bootPlugin($type, 'alfa-payments');

        if (!method_exists($plugin, 'onPaymentCheckup')) {
            $data['notice'] = Text::_('COM_ALFA_CHECKUP_NOT_OFFERED');

            return self::draw($data);
        }

        // The button's request has no settings form around it, so the plugin's
        // own strings are not loaded yet.
        Factory::getApplication()->getLanguage()->load('plg_alfa-payments_' . $type, JPATH_ADMINISTRATOR);

        $event = new PaymentCheckupEvent('onPaymentCheckup', ['subject' => $method, 'variant' => $variant, 'check' => $check]);

        try {
            $plugin->onPaymentCheckup($event);
        } catch (Throwable $e) {
            // A gateway that fails while describing itself must not take the
            // settings page with it; the operator sees one line, the log the rest.
            Log::add('Payment checkup of method #' . $methodId . ' (' . $type . ') failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $data['notice'] = Text::_('COM_ALFA_CHECKUP_ERROR');

            return self::draw($data);
        }

        $data['steps'] = $event->getSteps();
        $data['webhook'] = $event->webhookUrl();
        $data['return'] = $event->returnUrl();

        if ($event->isCheckOffered()) {
            $data['check'] = [
                'url' => Route::_('index.php?option=com_alfa&task=paymentcheckup.check', false),
                'token' => Session::getFormToken(),
                'method' => $methodId,
                'variant' => $variant,
                'checked' => $event->getLastChecked(),
            ];
        }

        return self::draw($data);
    }

    /**
     * The SAVED method row; the checkup never judges a half-edited form.
     *
     * @param int $methodId The method id.
     *
     * @return object|null id, type, state, params.
     *
     * @since  2.0.0
     */
    private static function loadMethod(int $methodId): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'type', 'state', 'params']))
            ->from($db->quoteName('#__alfa_payments'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $methodId, \Joomla\Database\ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * @param array $data Layout data.
     *
     *
     * @since  2.0.0
     */
    private static function draw(array $data): string
    {
        return LayoutHelper::render('payments.checkup', $data, JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');
    }
}
