<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Checkout;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Utilities\IpHelper;

defined('_JEXEC') or die;

/**
 * The order the checkout's own pages show this visitor, and where that came from (2.2.1).
 *
 * The process page and the completed page's arrival act on the order the session names: the process page asks the
 * order's gateway for its payment page, the arrival sends the visitor to the order's keyed address. A gateway's
 * browser return writes that order id, and a return is a request anyone can send, so the id alone proves nothing. The
 * session therefore keeps, beside the id, how it got there:
 *
 * - placed: this session placed the order (the storefront's placement writes it);
 * - return: a gateway authenticated a browser return for the order (PaymentsPlugin::authenticatedReturnFor(), in any
 *   session, a fresh one included: a hosted payment page outlives the PHP session).
 *
 * An order id with neither mark is refused by both pages: the visitor goes to the cart with one neutral message, the
 * same whether the order exists or not, and nothing of the order is shown. The mark is written and cleared only with
 * the id: a new placement or another authenticated return replaces both, the arrival and a refusal clear both.
 *
 * @since  2.2.1
 */
final class SessionOrder
{
    /** The session's order id (user state). */
    public const ID = 'com_alfa.order_id';

    /** Its origin, beside it (user state): "<order id>:<origin>". */
    public const ORIGIN = 'com_alfa.order_origin';

    /** The order was placed in this session. */
    public const PLACED = 'placed';

    /** A gateway authenticated a browser return for the order. */
    public const AUTHENTICATED_RETURN = 'return';

    /**
     * This session placed the order: the storefront's placement, the buyer's own flow.
     *
     * @param CMSApplicationInterface $app The storefront request.
     * @param int $orderId The order just placed.
     *
     * @since  2.2.1
     */
    public static function placed(CMSApplicationInterface $app, int $orderId): void
    {
        self::point($app, $orderId, self::PLACED);
    }

    /**
     * A gateway authenticated a browser return for the order. Idempotent: the same order again changes nothing (an
     * order placed in this session keeps that mark); another order replaces the id and its mark.
     *
     * @param CMSApplicationInterface $app The storefront request.
     * @param int $orderId The order the authenticated return names, 1 or more.
     *
     * @since  2.2.1
     */
    public static function authenticatedReturn(CMSApplicationInterface $app, int $orderId): void
    {
        if (self::id($app) === $orderId && self::markedOrder($app) === $orderId) {
            return;
        }

        self::point($app, $orderId, self::AUTHENTICATED_RETURN);
    }

    /**
     * The session's order id, 0 when it names none.
     *
     * @param CMSApplicationInterface $app The storefront request.
     *
     * @since  2.2.1
     */
    public static function id(CMSApplicationInterface $app): int
    {
        return (int) $app->getUserState(self::ID);
    }

    /**
     * Whether the session may show this order: it names it with a mark of its origin.
     *
     * @param CMSApplicationInterface $app The storefront request.
     * @param int $orderId The session's order id.
     *
     * @since  2.2.1
     */
    public static function isEntitled(CMSApplicationInterface $app, int $orderId): bool
    {
        return $orderId > 0 && self::markedOrder($app) === $orderId;
    }

    /**
     * Forget the session's order: the id and its mark.
     *
     * @param CMSApplicationInterface $app The storefront request.
     *
     * @since  2.2.1
     */
    public static function clear(CMSApplicationInterface $app): void
    {
        $app->setUserState(self::ID, null);
        $app->setUserState(self::ORIGIN, null);
    }

    /**
     * Refuse the session's order on one of the checkout's pages: one log line, the id and its mark forgotten, the
     * neutral message queued. The caller sends the visitor to the cart.
     *
     * @param CMSApplicationInterface $app The storefront request.
     * @param int $orderId The order the session names.
     * @param string $page Which page refused it, for the log.
     *
     * @since  2.2.1
     */
    public static function refuse(CMSApplicationInterface $app, int $orderId, string $page): void
    {
        $cart = CartService::ambient()->canHaveCart() ? 'yes' : 'no';

        AlfaLog::register();
        Log::add(
            'Order pages: the ' . $page . ' page refused order ' . $orderId . ', which this session neither placed nor had a'
            . ' gateway authenticate a return for; client ' . IpHelper::getIp() . ', a cart (account or cart key): ' . $cart . '.',
            Log::WARNING,
            'com_alfa.orders',
        );

        self::clear($app);
        $app->enqueueMessage(Text::_('COM_ALFA_ORDER_SESSION_REFUSED'), 'notice');
    }

    /**
     * Write the id and its mark together.
     *
     * @param CMSApplicationInterface $app The storefront request.
     * @param int $orderId The order.
     * @param string $origin PLACED or AUTHENTICATED_RETURN.
     *
     * @since  2.2.1
     */
    private static function point(CMSApplicationInterface $app, int $orderId, string $origin): void
    {
        $app->setUserState(self::ID, $orderId);
        $app->setUserState(self::ORIGIN, $orderId . ':' . $origin);
    }

    /**
     * The order the session's mark names, 0 when there is no mark or it names no origin this class writes.
     *
     * @param CMSApplicationInterface $app The storefront request.
     *
     * @since  2.2.1
     */
    private static function markedOrder(CMSApplicationInterface $app): int
    {
        $mark = $app->getUserState(self::ORIGIN);

        if (!\is_string($mark) || !preg_match('/^([1-9][0-9]*):(' . self::PLACED . '|' . self::AUTHENTICATED_RETURN . ')$/', $mark, $found)) {
            return 0;
        }

        return (int) $found[1];
    }
}
