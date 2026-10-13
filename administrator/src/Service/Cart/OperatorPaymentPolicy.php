<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
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

        if (!self::isEnabled($type)) {
            return self::$answers[$type] = false;
        }

        try {
            $plugin = Factory::getApplication()->bootPlugin($type, 'alfa-payments');
        } catch (Throwable $e) {
            return self::$answers[$type] = false;
        }

        if ($plugin === null || !method_exists($plugin, 'supportsOperatorPlacement')) {
            return self::$answers[$type] = false;
        }

        return self::$answers[$type] = (bool) $plugin->supportsOperatorPlacement();
    }

    /**
     * Is the plugin behind this method enabled?
     *
     * Asked separately because bootPlugin() does not ask it. It boots by folder
     * and element and hands back an instance whether or not the merchant has
     * the plugin switched on, so every caller that cares has to check for
     * itself. This one cares: a withdrawn gateway must not be reachable from
     * the back office, and the storefront's own behaviour is left exactly as it
     * is rather than being repaired from here.
     *
     * @param string $type The plugin element.
     *
     *
     * @since  2.0.0
     */
    public static function isEnabled(string $type): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('alfa-payments'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':element', $type);

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (Throwable $e) {
            // A question that cannot be answered is answered NO.
            return false;
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
