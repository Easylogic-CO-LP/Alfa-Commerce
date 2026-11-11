<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentWebhookEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ShipmentWebhookEvent;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Throwable;

\defined('_JEXEC') or die;

/**
 * The provider callback endpoints' delivery to the plugin of record (2.2.0, design 2.15.5):
 * the plugin the address names, booted through the plugin helper, the event delivered to its declared handler; never
 * another plugin. A webhook that cannot be processed is never acknowledged: a plugin of record that is disabled,
 * missing or cannot be loaded is answered 503 with Retry-After (the provider keeps retrying) and raises one
 * operational alert per plugin; a handler that throws is answered 500; a plugin present that has no webhook handler
 * keeps its 404, because there is no endpoint.
 *
 * @since  2.2.0
 */
trait ProviderCallbacks
{
    /**
     * Seconds a provider is asked to wait before retrying a delivery the plugin of record could not take.
     *
     * @since  2.2.0
     */
    private static int $retryAfterSeconds = 600;

    /**
     * Deliver a provider's webhook to its plugin of record and answer the provider.
     *
     * @param string $group The plugin group (alfa-payments or alfa-shipments).
     * @param string $type The element the address names.
     * @param PaymentWebhookEvent|ShipmentWebhookEvent $event The webhook event; its response code, body and content type are the answer.
     *
     * @since  2.2.0
     */
    private function deliverWebhook(string $group, string $type, PaymentWebhookEvent|ShipmentWebhookEvent $event): void
    {
        $plugins = AlfaPluginHelper::get();

        try {
            $id = PluginId::from($group, $type);
        } catch (InvalidArgumentException) {
            $this->answerWebhook(404, 'Not found');

            return;
        }

        try {
            $plugin = $plugins->bootSingle($id);
        } catch (PluginUnavailable $unavailable) {
            $this->refuseDelivery($id, $unavailable);

            return;
        }

        if (!$plugins->declares($plugin, $event->getName())) {
            $this->answerWebhook(404, 'Not found');

            return;
        }

        // Anything leaving the handler, a PluginUnavailable of a nested call included, is the handler's failure: 500, so
        // the provider retries; never the plugin of record's unavailability.
        try {
            $plugins->dispatchTo($plugin, $event);
        } catch (Throwable $e) {
            Log::add('Webhook: ' . $id . ' failed while processing (' . $e::class . ': ' . $e->getMessage() . '); answered 500 so the provider retries.', Log::ERROR, 'com_alfa.plugins');
            $this->answerWebhook(500, '');

            return;
        }

        $this->answerWebhook($event->getResponseCode(), $event->getResponseBody(), $event->getContentType());
    }

    /**
     * A delivery its plugin of record cannot take. Addressed to the plugin of some method of the shop: 503 with
     * Retry-After and an empty body, one WARNING and the operational alert. Addressed to an element no method names: no
     * endpoint (404), and no alert.
     *
     * @param PluginId $id The plugin the address names.
     * @param PluginUnavailable $unavailable Why it cannot take it.
     *
     * @since  2.2.0
     */
    private function refuseDelivery(PluginId $id, PluginUnavailable $unavailable): void
    {
        if (!self::isPluginOfRecord($id)) {
            $this->answerWebhook(404, 'Not found');

            return;
        }

        Log::add('Webhook: ' . $id . ' cannot take the delivery (' . $unavailable->reason . ': ' . $unavailable->getMessage() . '); answered 503, the provider retries.', Log::WARNING, 'com_alfa.plugins');

        try {
            NotificationHelper::pluginUnavailable($id, $unavailable->reason);
        } catch (Throwable $e) {
            Log::add('Webhook: the alert for ' . $id . ' could not be written: ' . $e->getMessage(), Log::ERROR, 'com_alfa.plugins');
        }

        $this->app->setHeader('Retry-After', (string) self::$retryAfterSeconds, true);
        $this->answerWebhook(503, '');
    }

    /**
     * Whether a method of the shop names the plugin (its type), so a provider can be holding its address.
     *
     * @param PluginId $id The plugin.
     *
     * @since  2.2.0
     */
    private static function isPluginOfRecord(PluginId $id): bool
    {
        $table = match ($id->group) {
            'alfa-payments' => '#__alfa_payments',
            'alfa-shipments' => '#__alfa_shipments',
            default => null,
        };

        if ($table === null) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $element = $id->element;

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName($table))
                ->where($db->quoteName('type') . ' = :type')
                ->bind(':type', $element),
        )->loadResult() > 0;
    }

    /**
     * Emit a raw HTTP answer to the provider and terminate the request.
     *
     * @param int $code HTTP status code
     * @param string $body Response body
     * @param string $contentType Content-Type header value
     *
     * @since  1.0.0
     */
    private function answerWebhook(int $code, string $body, string $contentType = 'text/plain; charset=utf-8'): void
    {
        $app = $this->app;

        $app->setHeader('status', $code, true);
        $app->setHeader('Content-Type', $contentType, true);
        $app->sendHeaders();

        echo $body;

        $app->close();
    }
}
