<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Documents;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Api\View\PublishesStoredFigures;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Documents view
 *
 * @since  1.9.0
 */
class JsonApiView extends BaseApiView
{
    use PublishesStoredFigures;

    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.9.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'doc_type',
        'status',
        'is_combined',
        'number',
        'number_formatted',
        'issue_date',
        'issue_date_local',
        'id_order',
        'id_shipment',
        'id_corrects',

        // Frozen identities. These are the snapshot as it stood at issue time,
        // NOT a live join; the customer may have changed their details since.
        'buyer_name',
        'buyer_vat_number',
        'buyer_tax_office',
        'buyer_activity',
        'buyer_country',
        'buyer_email',
        'buyer_address',
        'seller_name',
        'seller_vat_number',
        'seller_tax_office',
        'seller_activity',
        'seller_address',

        'currency_code',
        'currency',
        'conversion_rate',
        'total_tax_excl',
        'total_tax',
        'total_tax_incl',

        // Goods movement (delivery notes / combined documents).
        'movement_purpose',
        'carrier_name',
        'vehicle_number',
        'movement_start',
        'ship_from',
        'ship_to',

        'payment_method_name',
        'authority_state',
        'authority_mark',
        'authority_uid',
        'qr_url',
        'note',
        'print_note',
        'voided_at',
        'voided_by',
        'void_reason',
        'series',
        'lines',
        'taxes',
        'created',
        'created_by',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.9.0
     */
    protected $fieldsToRenderList = [
        'id',
        'doc_type',
        'status',
        'number',
        'number_formatted',
        'issue_date_local',
        'id_order',
        'id_shipment',
        'id_corrects',
        'buyer_name',
        'buyer_vat_number',
        'currency_code',
        'total_tax_excl',
        'total_tax',
        'total_tax_incl',
        'authority_state',
        'authority_mark',
        'series_code',
        'created',
    ];

    /**
     * A listed document's totals as the money of its own currency at its frozen
     * scale, as exact decimal text; a document that names no currency states its
     * stored figures as they are. A document read on its own arrives with its
     * money already projected ({@see \Alfa\Component\Alfa\Api\Model\DocumentModel}).
     *
     * @param object $item The document.
     *
     * @return object
     *
     * @since  1.14.0
     */
    protected function prepareItem($item)
    {
        $totals = array_filter(
            ['total_tax_excl', 'total_tax', 'total_tax_incl'],
            static fn (string $field): bool => property_exists($item, $field) && is_numeric($item->$field),
        );

        if ($totals === []) {
            return $item;
        }

        try {
            $currency = DocumentMoney::currencyOf($item);
        } catch (OrderCurrencyException) {
            $currency = null;
        }

        foreach ($totals as $field) {
            $item->$field = $currency === null
                ? self::storedRate(value: $item->$field)
                : PublishedDecimal::money(Money::of(self::storedRate(value: $item->$field), $currency));
        }

        return $item;
    }
}
