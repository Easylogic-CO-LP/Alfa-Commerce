<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * The orders of the customer logged in, newest first, a page at a time: the orders of their account (id_user) that
 * are not in the trash. Never by e-mail or name, so a guest's orders under the same e-mail are not theirs here; a
 * guest opens one order through its e-mail's link.
 *
 * Each row is what the list prints: the order's id, the number the customer knows it by, when it was placed, its
 * status by the name a customer reads (OrderStatusHelper::customerName()) and its total in its own currency.
 *
 * @since  2.3.0
 */
class OrdersModel extends ListModel
{
    /**
     * The customer and the page: the customer logged in, the site's list limit, the page the request names.
     *
     * @param string|null $ordering Unused: the list is always newest first.
     * @param string|null $direction Unused.
     *
     * @since  2.3.0
     */
    protected function populateState($ordering = null, $direction = null)
    {
        $app = Factory::getApplication();
        $params = $app->getParams('com_alfa');

        $this->setState('params', $params);
        $this->setState('customer.id', (int) $app->getIdentity()->id);
        $this->setState('list.limit', max(1, (int) $app->get('list_limit', 20)));
        $this->setState('list.start', max(0, RequestScalar::int($app->getInput(), 'limitstart', 0)));
    }

    /**
     * The store id: the customer is part of it, so two customers never share a cached list.
     *
     * @param string $id A prefix for the store id.
     *
     * @since  2.3.0
     */
    protected function getStoreId($id = '')
    {
        return parent::getStoreId($id . ':' . (int) $this->getState('customer.id'));
    }

    /**
     * The customer's orders, newest first; none at all when no customer is logged in.
     *
     * @since  2.3.0
     */
    protected function getListQuery(): QueryInterface
    {
        $db = $this->getDatabase();
        $customerId = (int) $this->getState('customer.id');

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'created', 'id_order_status']))
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('state') . ' != -2')
            ->order([$db->quoteName('created') . ' DESC', $db->quoteName('id') . ' DESC']);

        if ($customerId <= 0) {
            return $query->where('1 = 0');
        }

        return $query->where($db->quoteName('id_user') . ' = :customer')
            ->bind(':customer', $customerId, ParameterType::INTEGER);
    }

    /**
     * The page's rows as the list prints them.
     *
     * @return object[] Each: id, number, created, status, total (Money).
     *
     * @since  2.3.0
     */
    public function getItems()
    {
        $rows = parent::getItems();

        if (!\is_array($rows)) {
            return [];
        }

        return array_map(
            static fn (object $row): object => (object) [
                'id' => (int) $row->id,
                'number' => EmailService::orderNumber(order: $row),
                'created' => (string) $row->created,
                'status' => OrderStatusHelper::customerName(statusId: (int) $row->id_order_status),
                'total' => OrderTotalHelper::getOrderTotal(orderId: (int) $row->id),
            ],
            $rows,
        );
    }
}
