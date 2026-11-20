<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Joomla\CMS\MVC\Model\ListModel;

/**
 * One part of an order (its payments, its shipments) as a list: the rows the order read gives under that name, in its
 * own projection ({@see OrderModel::getItem()}), so GET v1/alfa/orders/:id and GET v1/alfa/orders/:id/<part> answer the
 * same values. The order is the state's filter.order_id; the page is the request's page[offset] and page[limit].
 *
 * @since  2.2.2
 */
abstract class OrderPartsModel extends ListModel
{
    /**
     * The order read's name for the part: payments or shipments.
     *
     * @since  2.2.2
     */
    protected const PART = '';

    /**
     * Every row of the part, read once.
     *
     * @var list<object>|null
     *
     * @since  2.2.2
     */
    private ?array $rows = null;

    /**
     * The page of the part's rows.
     *
     * @return list<object>
     *
     * @since  2.2.2
     */
    public function getItems()
    {
        $limit = (int) $this->getState('list.limit');

        return \array_slice($this->rows(), (int) $this->getStart(), $limit > 0 ? $limit : null);
    }

    /**
     * How many rows the part has.
     *
     * @since  2.2.2
     */
    public function getTotal()
    {
        return \count($this->rows());
    }

    /**
     * The fields the order read gives a row of the part; id alone when the part has no row (nothing is rendered, and
     * Joomla's list view asks for at least one name).
     *
     * @return non-empty-list<string>
     *
     * @since  2.2.2
     */
    public function fields(): array
    {
        return array_keys((array) ($this->rows()[0] ?? [])) ?: ['id'];
    }

    /**
     * The part's rows as the order read gives them; an order that names no currency is read as that read answers it.
     *
     * @return list<object>
     *
     * @since  2.2.2
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $orderId = (int) $this->getState('filter.order_id');
        /** @var OrderModel $model */
        $model = $this->getMVCFactory()->createModel('Order', 'Api', ['ignore_request' => true]);

        try {
            $order = $model->getItem($orderId);
        } catch (OrderCurrencyException $e) {
            $order = $model->getItemWithoutCurrency(orderId: $e->orderId());
        }

        $part = \is_object($order) ? (array) ($order->{static::PART} ?? []) : [];

        return $this->rows = array_values(array_map(static fn (array $row): object => (object) $row, $part));
    }
}
