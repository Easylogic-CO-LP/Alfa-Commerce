<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use InvalidArgumentException;
use Joomla\CMS\Log\Log;
use Throwable;

defined('_JEXEC') or die;

/**
 * Which payment methods a member of staff may actually put through.
 *
 * A storefront payment method assumes a buyer with a browser. Some of them do
 * nothing but write a row and wait for the money to arrive by other means;
 * others sign a request and hand the buyer a form to submit; at least one
 * contacts the gateway during placement and creates a live order at the far
 * end. Run that last one from a back office and a real order exists at a real
 * payment provider, for a buyer who was never there to complete it, and no
 * amount of rolling back the database undoes it.
 *
 * So the question "may staff use this method" is answered by the plugin, and
 * the default answer is NO. Failing closed is the whole point: a gateway
 * written before this contract existed, or by somebody who never considered a
 * back office, must not become operable by staff merely because it works for
 * shoppers. Opting in is a statement that somebody thought about it.
 *
 * The shape follows PaymentsPlugin::supportsRecurring(), which has answered a
 * question of exactly this kind since 2.0.0 and is consumed the same way. No
 * method is named here and none ever should be: a policy that lists plugins by
 * name is a policy that is wrong as soon as somebody writes a new one.
 *
 * @since  2.0.0
 */
final class OperatorPaymentPolicy
{
    /**
     * @var array<string, bool> Answers already reached this request.
     * @since  2.0.0
     */
    private static array $answers = [];

    /**
     * May staff place an order paid by this method?
     *
     * Two conditions, and both are about the method as installed rather than
     * about the person: the plugin must be ENABLED, because a disabled gateway
     * is one the merchant has withdrawn and the placement pipeline would
     * otherwise still boot and call it; and it must declare the capability.
     *
     * @param string $type The payment method's plugin element (#__alfa_payments.type).
     *
     *
     * @since  2.0.0
     */
    public static function allows(string $type): bool
    {
        $type = trim($type);

        if ($type === '') {
            return false;
        }

        if (isset(self::$answers[$type])) {
            return self::$answers[$type];
        }

        // The plugin helper is the one availability authority (2.2.0): a plugin disabled, missing, outside the
        // request's view levels or unloadable answers no; one that fails while answering answers no, logged.
        $plugins = AlfaPluginHelper::get();

        try {
            return self::$answers[$type] = (bool) $plugins->invoke($plugins->bootSingle(PluginId::from('alfa-payments', $type)), 'supportsOperatorPlacement');
        } catch (PluginUnavailable|InvalidArgumentException) {
            return self::$answers[$type] = false;
        } catch (Throwable $e) {
            Log::add('Operator placement: the payment plugin ' . $type . ' failed while answering whether staff may place with it: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');

            return self::$answers[$type] = false;
        }
    }

    /**
     * Keep only the methods staff may put through.
     *
     * @param array $methods Offered methods, keyed by id, each carrying ->type.
     *
     * @return array The same shape, minus what staff may not use.
     *
     * @since  2.0.0
     */
    public static function filter(array $methods): array
    {
        return array_filter(
            $methods,
            static fn ($method): bool => self::allows((string) ($method->type ?? '')),
        );
    }

    /**
     * Forget the answers. For harnesses that enable or disable a plugin inside
     * one process.
     *
     *
     * @since  2.0.0
     */
    public static function forget(): void
    {
        self::$answers = [];
    }
}
