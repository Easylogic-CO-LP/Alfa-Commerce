<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Model\ItemModel;
use Joomla\Database\ParameterType;

/**
 * A single sales document, with everything frozen onto it.
 *
 * There is no administrator DocumentModel (the back office reads documents
 * through the list model and the renderer), so this is the only singular
 * document read in the component.
 *
 * Everything here is a SNAPSHOT taken at issue time and is legally immutable:
 * the buyer and seller identities, the per-line prices, the per-rate VAT
 * summary and the formatted number. Nothing is recomputed on read, and
 * nothing may be written back.
 *
 * @since  2.0.0
 */
class DocumentModel extends ItemModel
{
    use ProjectsMoney;

    /**
     * Load the document with its series, lines and frozen VAT summary.
     *
     * @param int|null $pk The document id.
     *
     * @return object
     *
     * @throws ResourceNotFound When no such document exists.
     *
     * @since  2.0.0
     */
    public function getItem($pk = null)
    {
        $pk = (int) ($pk ?: $this->getState('document.id'));

        if ($pk <= 0) {
            throw new ResourceNotFound('Document not found', 404);
        }

        $db = $this->getDatabase();

        $document = $db->setQuery(
            $db->getQuery(true)
                ->select('d.*')
                ->select(
                    [
                        $db->quoteName('s.code', 'series_code'),
                        $db->quoteName('s.title', 'series_title'),
                        $db->quoteName('s.prefix', 'series_prefix'),
                        $db->quoteName('s.branch_code', 'series_branch_code'),
                    ],
                )
                ->from($db->quoteName('#__alfa_documents', 'd'))
                ->join(
                    'LEFT',
                    $db->quoteName('#__alfa_document_series', 's'),
                    $db->quoteName('s.id') . ' = ' . $db->quoteName('d.id_series'),
                )
                ->where($db->quoteName('d.id') . ' = :id')
                ->bind(':id', $pk, ParameterType::INTEGER),
        )->loadObject();

        if (!$document) {
            throw new ResourceNotFound('Document not found', 404);
        }

        $currency = $this->documentCurrency($document);

        $document->currency = $this->currencyBlock($currency);
        $document->series = [
            'id' => (int) $document->id_series,
            'code' => $document->series_code,
            'title' => $document->series_title,
            'prefix' => $document->series_prefix,
            'branch_code' => $document->series_branch_code,
        ];

        foreach (['series_code', 'series_title', 'series_prefix', 'series_branch_code'] as $key) {
            unset($document->$key);
        }

        foreach (['total_tax_excl', 'total_tax', 'total_tax_incl', 'total_discount_tax_excl'] as $key) {
            if (property_exists($document, $key)) {
                $document->$key = $this->money($document->$key, $currency);
            }
        }

        if (property_exists($document, 'conversion_rate')) {
            $document->conversion_rate = self::decimal(value: $document->conversion_rate);
        }

        // The address snapshots are stored as JSON; hand them back as objects
        // rather than making every client re-decode a string.
        foreach (['buyer_address', 'ship_from', 'ship_to', 'seller_extra'] as $key) {
            if (isset($document->$key) && \is_string($document->$key) && $document->$key !== '') {
                $decoded = json_decode($document->$key, true);

                if (\is_array($decoded)) {
                    $document->$key = $decoded;
                }
            }
        }

        $document->lines = $this->projectLines($pk, $currency);
        $document->taxes = $this->projectTaxes($pk, $currency);

        return $document;
    }

    /**
     * The document's own lines, in the order they were frozen.
     *
     * @param int $documentId The document id.
     * @param Currency|null $currency The document's currency; null when it names none.
     *
     *
     * @since  2.0.0
     */
    private function projectLines(int $documentId, ?Currency $currency): array
    {
        $db = $this->getDatabase();

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_document_items'))
                ->where($db->quoteName('id_document') . ' = :id')
                ->order($db->quoteName('sort_order') . ' ASC, ' . $db->quoteName('id') . ' ASC')
                ->bind(':id', $documentId, ParameterType::INTEGER),
        )->loadObjectList() ?: [];

        $money = [
            'discount_tax_excl',
            'total_tax_excl',
            'total_tax_incl',
            'tax_amount',
        ];
        // Stored units that stand on their own, as data: the pre-discount unit
        // and the agreed custom rate the line was frozen with.
        $rates = [
            'original_unit_price_tax_excl',
            'custom_unit_price_tax_incl',
        ];

        foreach ($rows as $row) {
            // The line's units by the one unit rule's fixed Auto form, from its
            // own quantity and frozen totals, read before they become money
            // objects; the agreed rate is passed beside the gross unit only.
            $units = [
                'unit_price_tax_excl' => $this->unit(
                    value: $row->unit_price_tax_excl ?? null,
                    lineTotal: $row->total_tax_excl ?? null,
                    quantity: $row->quantity ?? 0,
                    rate: null,
                    currency: $currency,
                ),
                'unit_price_tax_incl' => $this->unit(
                    value: $row->unit_price_tax_incl ?? null,
                    lineTotal: $row->total_tax_incl ?? null,
                    quantity: $row->quantity ?? 0,
                    rate: $row->custom_unit_price_tax_incl ?? null,
                    currency: $currency,
                ),
            ];

            foreach ($units as $key => $projected) {
                if (property_exists($row, $key)) {
                    $row->$key = $projected;
                }
            }

            foreach ($money as $key) {
                if (property_exists($row, $key)) {
                    $row->$key = $this->money($row->$key, $currency);
                }
            }

            foreach ($rates as $key) {
                if (property_exists($row, $key)) {
                    $row->$key = $this->rate(value: $row->$key, currency: $currency);
                }
            }

            if (property_exists($row, 'tax_rate')) {
                $row->tax_rate = self::decimal(value: $row->tax_rate);
            }
        }

        return $rows;
    }

    /**
     * The frozen per-rate VAT summary.
     *
     * This is what the tax authority sees, and it is deliberately NOT derived
     * from the lines on read; it is the breakdown as computed at issue time.
     *
     * @param int $documentId The document id.
     * @param Currency|null $currency The document's currency; null when it names none.
     *
     *
     * @since  2.0.0
     */
    private function projectTaxes(int $documentId, ?Currency $currency): array
    {
        $db = $this->getDatabase();

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_document_taxes'))
                ->where($db->quoteName('id_document') . ' = :id')
                ->order($db->quoteName('tax_rate') . ' ASC')
                ->bind(':id', $documentId, ParameterType::INTEGER),
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            foreach (['base_tax_excl', 'tax_amount'] as $key) {
                if (property_exists($row, $key)) {
                    $row->$key = $this->money($row->$key, $currency);
                }
            }

            if (property_exists($row, 'tax_rate')) {
                $row->tax_rate = self::decimal(value: $row->tax_rate);
            }
        }

        return $rows;
    }

    /**
     * The currency the document was frozen in, at the scale it froze.
     *
     * The one answer every reader of a document gives
     * ({@see DocumentMoney::currencyOf()}): what its own header froze, never
     * another currency in its place. Null when the document names no currency
     * and neither does its order: a document is immutable history and is
     * still answered, with its stored numbers and the notice where formatted
     * money would be.
     *
     * @param object $document The document header.
     *
     *
     * @since  2.0.0
     */
    private function documentCurrency(object $document): ?Currency
    {
        try {
            return DocumentMoney::currencyOf($document);
        } catch (OrderCurrencyException) {
            return null;
        }
    }
}
