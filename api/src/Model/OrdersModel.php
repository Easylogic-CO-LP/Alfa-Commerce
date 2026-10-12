<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Administrator\Model\OrdersModel as AdminOrdersModel;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderFacts;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;

/**
 * API Orders list: the admin list with money rendered the same way the single
 * order renders it.
 *
 * The admin grid keeps each order's rows and its resolved Currency on the row
 * (`_items`, `_shipments`, `_discounts`, `_contributions`, `_payments`,
 * `_currency`) and shows its totals as plain floats. Here every figure is
 * published from the same rows as an exact decimal string: the totals are the
 * exact sums of the stored rows, the payment facts the Money they were derived
 * from, the two percentages their two-place text.
 *
 * @since  1.9.0
 */
class OrdersModel extends AdminOrdersModel
{
    use ProjectsMoney;

    /**
     * The list's money fields and the exact total each one is.
     *
     * @var array<string, string>
     *
     * @since  1.14.0
     */
    private const TOTALS = [
        'order_total' => 'grand_total_tax_incl',
        'order_total_tax_excl' => 'grand_total_tax_excl',
        'items_total' => 'items_tax_incl',
        'items_total_excl' => 'items_tax_excl',
        'shipping_total' => 'shipping_tax_incl',
        'shipping_total_excl' => 'shipping_tax_excl',
        'discount_total' => 'discount_tax_incl',
        'discount_total_excl' => 'discount_tax_excl',
        'contributions_total' => 'contributions_tax_incl',
    ];

    /**
     * The list's payment facts that are money, by the name OrderFacts gives them.
     *
     * @var array<string, string>
     *
     * @since  1.14.0
     */
    private const FACTS = [
        'total_paid_real' => 'paid_real',
        'total_refunded_real' => 'refunded_real',
        'still_held' => 'still_held',
        'balance' => 'balance',
        'excess' => 'excess',
    ];

    /**
     * Get the orders with their money as the single order renders it.
     *
     * @return mixed An array of rows, or false on failure.
     *
     * @since  1.9.0
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (!\is_array($items)) {
            return $items;
        }

        foreach ($items as $item) {
            if (!\is_object($item)) {
                continue;
            }

            $currency = ($item->_currency ?? null) instanceof Currency ? $item->_currency : null;

            // The same formula over the same rows the grid summed, as exact texts.
            $totals = OrderTotalHelper::exactFromArrays(
                items:         (array) ($item->_items ?? []),
                shipments:     OrderTotalHelper::activeShipments((array) ($item->_shipments ?? [])),
                discounts:     (array) ($item->_discounts ?? []),
                contributions: (array) ($item->_contributions ?? []),
            );
            $facts = $currency === null
                ? null
                : OrderFacts::fromRows(Money::of($totals->grand_total_tax_incl, $currency), (array) ($item->_payments ?? []), $currency);

            $item->currency = $this->currencyBlock($currency);

            foreach (self::TOTALS as $field => $total) {
                $item->$field = $this->money($totals->$total, $currency);
            }

            foreach (self::FACTS as $field => $fact) {
                $item->$field = $facts === null ? null : $this->money($facts->money->$fact, $currency);
            }

            $item->payment_percentage = $facts === null ? null : PublishedDecimal::percent($facts->payment_percentage);
            // The status and the flag from the same exact facts as the money beside them.
            $item->payment_status = $facts?->payment_status;
            $item->overpaid = $facts?->overpaid;
            $item->fulfillment_percentage = isset($item->fulfillment_percentage) ? PublishedDecimal::percent((float) $item->fulfillment_percentage) : null;
        }

        return $items;
    }
}
