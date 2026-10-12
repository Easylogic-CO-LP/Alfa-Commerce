<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin\Traits;

use Alfa\Component\Alfa\Site\Service\Order\Order;
use Joomla\CMS\Factory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The order a plugin is acting on, and the language to speak to its buyer in.
 *
 * Shared by the payments and shipments families because the questions are
 * identical: an order is an order, and the buyer's language does not depend
 * on whether money or a parcel is moving. A trait rather than the base Plugin
 * class: the item-fields and search families have no order, and a helper
 * everything inherits is a helper nobody can reason about.
 *
 * @since  1.10.0
 */
trait HasOrderContextTrait
{
    /**
     * The order, fully loaded through the admin model.
     *
     * The admin Order model, not a hand-rolled query: it is what assembles the
     * items, addresses, totals and per-row currency a plugin then reads, and a
     * second definition of "an order" is how the two drift apart.
     *
     * THE SAME TYPE THE EVENTS HAND OVER. A webhook has no event to read the
     * order from (it arrives with an id and nothing else), so this is how a
     * plugin gets one, and it must not be a DIFFERENT KIND of order from the
     * one `$event->getOrder()` gives: two shapes behind one name is how a
     * gateway ends up written against whichever it was tested with.
     *
     * @param int $orderId Order PK.
     *
     * @return Order|null NULL when the id names nothing.
     *
     * @since  1.10.0
     */
    protected function getOrder(int $orderId): ?Order
    {
        if ($orderId <= 0) {
            return null;
        }

        $order = Factory::getApplication()->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel('Order', 'Administrator', ['ignore_request' => true])
            ->getItem($orderId);

        return is_object($order) && !empty($order->id) ? Order::of($order) : null;
    }

    /**
     * The language tag this order's buyer should be addressed in.
     *
     * The ORDER's language, not the admin's and not whatever the storefront is
     * showing: a webhook, a carrier callback or a cron-driven retry runs with
     * no customer session at all, and the buyer still deserves their own
     * language on a payment page or a tracking email. Falls back to the site
     * language when the order names none.
     *
     * Returns the full tag ('el-GR'). Gateways and carriers accept different
     * shapes (two-letter ISO, full tag, their own whitelist), so narrowing is
     * the plugin's job:
     *
     *     $code = strtolower(substr($this->orderLanguage($order), 0, 2));
     *
     * @param Order|int $order The order, or its PK.
     *
     * @return string Full language tag.
     *
     * @since  1.10.0
     */
    protected function orderLanguage(Order|int $order): string
    {
        $idLanguage = $order instanceof Order ? $order->languageId() : 0;

        $db = Factory::getContainer()->get('DatabaseDriver');

        if ($idLanguage <= 0 && is_int($order) && $order > 0) {
            $query = $db->getQuery(true)
                ->select($db->quoteName('id_language'))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . (int) $order);
            $db->setQuery($query);

            $idLanguage = (int) $db->loadResult();
        }

        if ($idLanguage > 0) {
            $query = $db->getQuery(true)
                ->select($db->quoteName('lang_code'))
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('lang_id') . ' = ' . $idLanguage);
            $db->setQuery($query);

            $tag = (string) ($db->loadResult() ?? '');

            if ($tag !== '') {
                return $tag;
            }
        }

        return (string) Factory::getApplication()->getLanguage()->getTag();
    }
}
