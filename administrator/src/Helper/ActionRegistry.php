<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Central registry for plugin actions.
 * Boots the correct plugin and dispatches onGetActions / onExecuteAction.
 *
 * Error messages are ALWAYS shown (admin must know when something is broken).
 * Step-by-step trace only appears when Joomla Debug System is enabled.
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Administrator\Event\Payments\GetPaymentActionsEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\GetShipmentActionsEvent;
use Joomla\CMS\Factory;
use Throwable;

defined('_JEXEC') or die;

class ActionRegistry
{
    /**
     * Get available actions for a payment.
     *
     * @param object $payment Payment record (must have params->type)
     * @param object $order Order record
     *
     * @return array Array of PluginAction objects
     *
     * @since   3.0.0
     */
    public static function getPaymentActions(object $payment, object $order): array
    {
        return self::resolveActions(
            'alfa-payments',
            $payment,
            $order,
            GetPaymentActionsEvent::class,
            'Payment',
        );
    }

    /**
     * Get available actions for a shipment.
     *
     * @param object $shipment Shipment record (must have params->type)
     * @param object $order Order record
     *
     * @return array Array of PluginAction objects
     *
     * @since   3.0.0
     */
    public static function getShipmentActions(object $shipment, object $order): array
    {
        return self::resolveActions(
            'alfa-shipments',
            $shipment,
            $order,
            GetShipmentActionsEvent::class,
            'Shipment',
        );
    }

    /**
     * Unified action resolution — shared by payments and shipments.
     *
     * Steps:
     *   1. Read params->type from the entity
     *   2. Boot the plugin via Joomla's bootPlugin()
     *   3. Verify onGetActions() method exists
     *   4. Dispatch event and collect actions
     *
     * Errors → always shown as warnings (admin must know).
     * Trace  → shown only when JDEBUG is on.
     *
     * @param string $pluginGroup Plugin group ('alfa-payments' or 'alfa-shipments')
     * @param object $entity Payment or shipment record
     * @param object $order Order record
     * @param string $eventClass Fully qualified event class name
     * @param string $label Human label for messages ('Payment' or 'Shipment')
     *
     * @return array Array of PluginAction objects
     *
     * @since   3.0.0
     */
    private static function resolveActions(
        string $pluginGroup,
        object $entity,
        object $order,
        string $eventClass,
        string $label,
    ): array {
        $app = Factory::getApplication();
        $entityId = $entity->id ?? '?';

        try {
            // ── Step 1: Read plugin type ──────────────────────────

            // Safety: params might be a JSON string, null, or an object
            $params = $entity->params ?? null;

            if (is_string($params)) {
                $params = json_decode($params);
            }

            $pluginType = null;

            if (is_object($params)) {
                $pluginType = $params->type ?? null;
            }

            if (empty($pluginType)) {
                self::warn(
                    "{$label} #{$entityId}: Cannot resolve plugin — params->type is empty."
                    . " Check that the {$label} method record has a valid 'type' field."
                    . ' Raw params: ' . self::truncate(print_r($entity->params ?? 'NULL', true)),
                );
                return [];
            }

            self::trace("{$label} #{$entityId}: type = \"{$pluginType}\"");

            // ── Step 2: Boot plugin ───────────────────────────────
            $plugin = $app->bootPlugin($pluginType, $pluginGroup);

            if (!$plugin) {
                self::warn(
                    "{$label} #{$entityId}: Plugin \"{$pluginType}\" ({$pluginGroup}) could not be booted."
                    . ' Check: 1) Plugin installed? 2) Plugin enabled?'
                    . ' 3) services/provider.php correct? 4) Namespace correct?',
                );
                return [];
            }

            self::trace("{$label} #{$entityId}: Plugin booted → " . get_class($plugin));

            // ── Step 3: Verify method ────────────────────────────
            if (!method_exists($plugin, 'onGetActions')) {
                self::warn(
                    "{$label} #{$entityId}: " . get_class($plugin)
                    . ' has no onGetActions() method.'
                    . ' The deployed plugin file may be outdated — re-upload it.',
                );
                return [];
            }

            // ── Step 4: Dispatch event ───────────────────────────
            $eventKey = ($label === 'Payment') ? 'payment' : 'shipment';

            $event = new $eventClass('onGetActions', [
                $eventKey => $entity,
                'order' => $order,
            ]);

            $plugin->onGetActions($event);

            // ── Step 5: Collect actions ──────────────────────────
            $actions = $event->getActions();

            if (empty($actions)) {
                self::trace(
                    "{$label} #{$entityId}: onGetActions() returned 0 actions."
                    . ' Plugin may conditionally exclude actions for current status.',
                );
            } else {
                $ids = array_map(fn ($a) => $a->id, $actions);
                self::trace(
                    "{$label} #{$entityId}: " . count($actions)
                    . ' action(s) → [' . implode(', ', $ids) . ']',
                );
            }

            return $actions;
        } catch (Throwable $e) {
            self::warn(
                "{$label} #{$entityId}: EXCEPTION — " . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
            );

            return [];
        }
    }

    /**
     * Show a warning — ALWAYS visible.
     *
     * Used for actual errors that prevent actions from working.
     * Admin must see these regardless of debug mode.
     *
     * @param string $message Warning message
     *
     *
     * @since   3.0.0
     */
    private static function warn(string $message): void
    {
        try {
            Factory::getApplication()->enqueueMessage(
                '<strong>[ActionRegistry]</strong> ' . htmlspecialchars($message),
                'warning',
            );
        } catch (Throwable $e) {
            // Application not available
        }
    }

    /**
     * Show a trace message — only when JDEBUG is enabled.
     *
     * Used for success-path step-by-step info.
     * Not needed in production.
     *
     * @param string $message Trace message
     *
     *
     * @since   3.0.0
     */
    private static function trace(string $message): void
    {
        if (defined('JDEBUG') && JDEBUG) {
            try {
                Factory::getApplication()->enqueueMessage(
                    '<strong>[ActionRegistry]</strong> ' . htmlspecialchars($message),
                    'info',
                );
            } catch (Throwable $e) {
                // Silently ignore
            }
        }
    }

    /**
     * Truncate a string for safe display in messages.
     *
     * @param string $string Input string
     * @param int $max Maximum length
     *
     *
     * @since   3.0.0
     */
    private static function truncate(string $string, int $max = 120): string
    {
        $string = trim(preg_replace('/\s+/', ' ', $string));

        return (strlen($string) > $max)
            ? substr($string, 0, $max) . '...'
            : $string;
    }
}
