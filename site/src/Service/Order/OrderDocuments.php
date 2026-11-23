<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentToken;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseQuery;
use Joomla\Database\ParameterType;

/**
 * The sales documents a customer reads and downloads for one of their orders: its invoices and receipts (the sales
 * types) and its credit notes (the credit types), the current version of each only (a document another one replaced is
 * left out), a voided one shown as voided. Never a delivery note or any other type, never a draft (drafts are not
 * documents).
 *
 * WHO MAY DOWNLOAD ONE. The same rule as an order's page and its downloads: the document's link carries a token
 * (DocumentToken, an HMAC of the document's id keyed with the order's secret, never the secret itself); a guest's order
 * (no account, or an account that no longer exists) needs the token alone; a customer account's order needs the token
 * and that customer logged in. Never an order in the trash. Never by e-mail or name.
 *
 * @since  2.3.0
 */
final class OrderDocuments
{
    /** The visitor may download the document. */
    public const GRANTED = 'granted';

    /** The document is of a customer account's order, and nobody is logged in. */
    public const LOGIN = 'login';

    /** Not available to this visitor: no such document, not one a customer reads, a wrong token, another's order. */
    public const NOT_AVAILABLE = 'not_available';

    /**
     * The documents of an order its customer reads, in the order they were issued.
     *
     * @param object $order The order's row (id, secure_key).
     *
     * @return object[] Each: id, type (its name in the current language), number, date (the legal date, Y-m-d), voided,
     *                  url (the download's address, its token included).
     *
     * @since  2.3.0
     */
    public static function of(object $order): array
    {
        $orderId = (int) ($order->id ?? 0);
        $types = self::types();

        if ($orderId <= 0 || $types === []) {
            return [];
        }

        $db = self::db();
        $rows = $db->setQuery(
            self::readable(db: $db)
                ->select($db->quoteName(['d.id', 'd.doc_type', 'd.status', 'd.number_formatted', 'd.issue_date_local']))
                ->where($db->quoteName('d.id_order') . ' = ' . $orderId)
                ->order([$db->quoteName('d.issue_date') . ' ASC', $db->quoteName('d.id') . ' ASC']),
        )->loadObjectList() ?: [];

        return array_map(
            static fn (object $row): object => (object) [
                'id' => (int) $row->id,
                'type' => DocumentHelper::typeLabel(docType: (string) $row->doc_type),
                'number' => (string) ($row->number_formatted ?? ''),
                'date' => (string) ($row->issue_date_local ?? ''),
                'voided' => (int) $row->status === DocumentHelper::STATUS_VOIDED,
                'url' => Route::_('index.php?option=com_alfa&task=order.document&doc=' . (int) $row->id
                    . '&token=' . DocumentToken::for((int) $row->id, (string) ($order->secure_key ?? ''))),
            ],
            $rows,
        );
    }

    /**
     * Whether a visitor may download a document, as its link asks it, with the document's row when they may.
     *
     * @param int $documentId The document the link names.
     * @param string $token The token the link carries.
     * @param int $viewerId The logged-in user, 0 for a guest.
     *
     * @return array{0: string, 1: object|null} One of GRANTED, LOGIN, NOT_AVAILABLE, and the document's row
     *                                          (GRANTED only).
     *
     * @since  2.3.0
     */
    public static function open(int $documentId, string $token, int $viewerId): array
    {
        $types = self::types();

        if ($documentId <= 0 || $types === []) {
            return [self::NOT_AVAILABLE, null];
        }

        $db = self::db();
        $document = $db->setQuery(
            self::readable(db: $db)
                ->select('d.*')
                ->select([$db->quoteName('o.secure_key'), $db->quoteName('u.id', 'account')])
                ->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('o.id_user'))
                ->where($db->quoteName('d.id') . ' = :id')
                ->bind(':id', $documentId, ParameterType::INTEGER),
        )->loadObject();

        if (!\is_object($document) || !DocumentToken::verify($documentId, (string) ($document->secure_key ?? ''), $token)) {
            return [self::NOT_AVAILABLE, null];
        }

        // An order whose account no longer exists is judged as a guest order: its token alone.
        $owner = (int) ($document->account ?? 0);

        if ($owner > 0 && $viewerId === 0) {
            return [self::LOGIN, null];
        }

        if ($owner > 0 && $viewerId !== $owner) {
            return [self::NOT_AVAILABLE, null];
        }

        unset($document->secure_key, $document->account);

        return [self::GRANTED, $document];
    }

    /**
     * The documents a customer reads, as a query to complete: of an order not in the trash, of a sales or a credit
     * type, not replaced by another document.
     *
     * @param DatabaseDriver $db The database.
     *
     * @since  2.3.0
     */
    private static function readable(DatabaseDriver $db): DatabaseQuery
    {
        $replacing = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__alfa_documents', 'r'))
            ->where($db->quoteName('r.id_replaces') . ' = ' . $db->quoteName('d.id'));

        return $db->getQuery(true)
            ->from($db->quoteName('#__alfa_documents', 'd'))
            ->join('INNER', $db->quoteName('#__alfa_orders', 'o'), $db->quoteName('o.id') . ' = ' . $db->quoteName('d.id_order'))
            ->where($db->quoteName('o.state') . ' != -2')
            ->whereIn($db->quoteName('d.doc_type'), self::types(), ParameterType::STRING)
            ->where('NOT EXISTS (' . $replacing . ')');
    }

    /**
     * The types a customer reads: the sales and the credit types, those no longer offered included (their documents
     * stay the customer's).
     *
     * @return string[]
     *
     * @since  2.3.0
     */
    private static function types(): array
    {
        return array_values(array_unique([...DocumentHelper::salesTypes(), ...DocumentHelper::creditTypes()]));
    }

    /**
     * The database.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
