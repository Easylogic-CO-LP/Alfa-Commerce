<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * The currency an order names, or a refusal.
 *
 * An order states its currency when it is created and keeps it for life.
 * Every amount on the order, on its payments, its shipments and its documents
 * is money in that currency, at the scale the order froze. This class is the
 * one reader of that fact: every service that needs an order's currency asks
 * here, so no two of them can answer differently about the same order.
 *
 * An order whose currency is empty, or names no currency row, has no money
 * that can be priced, paid or invoiced, and nothing here puts another currency
 * in its place. A command asks {@see self::of()} before it writes anything and
 * is refused with {@see OrderCurrencyException}; a screen or a list asks
 * {@see self::find()} and shows {@see self::notice()} where money would be.
 * An order id that has no row is refused the same way, as an order that was
 * not found.
 *
 * The shop's default currency is read for an order in one situation only: the
 * currency an order that does not exist yet will take
 * ({@see self::forNewOrder()}).
 *
 * @since  2.0.0
 */
final class OrderCurrency
{
    /**
     * Language key of the sentence a refused command shows.
     *
     * @since  2.0.0
     */
    public const REFUSAL_KEY = 'COM_ALFA_ORDER_CURRENCY_MISSING';

    /**
     * Language key of the words a screen or a list shows in place of money.
     *
     * @since  2.0.0
     */
    public const NOTICE_KEY = 'COM_ALFA_ORDER_CURRENCY_NONE';

    /**
     * Language key of the sentence shown when the order itself has no row.
     *
     * @since  2.0.0
     */
    public const NOT_FOUND_KEY = 'COM_ALFA_ORDER_NOT_FOUND';

    /**
     * Orders whose refusal this request has already written to the log.
     *
     * @var array<int, true>
     *
     * @since  2.0.0
     */
    private static array $logged = [];

    /**
     * The order's currency, at the scale the order froze.
     *
     * @param int $orderId Order PK.
     *
     *
     * @throws OrderCurrencyException When the order does not exist, names no
     *                                currency, or names one that has no row.
     *
     * @since  2.0.0
     */
    public static function of(int $orderId): Currency
    {
        $row = self::row(orderId: $orderId);
        $resolved = self::resolve(orderId: $orderId, row: $row);

        if ($resolved instanceof Currency) {
            return $resolved;
        }

        throw self::refuse(orderId: $orderId, detail: $resolved['detail'], previous: $resolved['cause'], orderExists: $row !== null);
    }

    /**
     * The same answer for a caller that already holds the order's row.
     *
     * A list resolves a page of orders from the rows it loaded; reading each
     * one again here would cost a statement per row.
     *
     * @param int $orderId Order PK.
     * @param mixed $currencyId The row's id_currency, as stored.
     * @param mixed $scale The row's currency_scale, as stored.
     *
     *
     * @throws OrderCurrencyException When the row names no currency, or one
     *                                that has no row.
     *
     * @since  2.0.0
     */
    public static function ofRow(int $orderId, mixed $currencyId, mixed $scale): Currency
    {
        $resolved = self::resolve(
            orderId: $orderId,
            row:     (object) ['id_currency' => $currencyId, 'currency_scale' => $scale],
        );

        if ($resolved instanceof Currency) {
            return $resolved;
        }

        throw self::refuse(orderId: $orderId, detail: $resolved['detail'], previous: $resolved['cause'], orderExists: true);
    }

    /**
     * The order's currency for a screen or a list: null when it names none.
     *
     * Null is never a reason to show money another way. The caller shows
     * {@see self::notice()} in its place.
     *
     * @param int $orderId Order PK.
     *
     *
     * @since  2.0.0
     */
    public static function find(int $orderId): ?Currency
    {
        $resolved = self::resolve(orderId: $orderId, row: self::row(orderId: $orderId));

        return $resolved instanceof Currency ? $resolved : null;
    }

    /**
     * {@see self::find()} for a caller that already holds the order's row.
     *
     * @param int $orderId Order PK.
     * @param mixed $currencyId The row's id_currency, as stored.
     * @param mixed $scale The row's currency_scale, as stored.
     *
     *
     * @since  2.0.0
     */
    public static function findRow(int $orderId, mixed $currencyId, mixed $scale): ?Currency
    {
        $resolved = self::resolve(
            orderId: $orderId,
            row:     (object) ['id_currency' => $currencyId, 'currency_scale' => $scale],
        );

        return $resolved instanceof Currency ? $resolved : null;
    }

    /**
     * The id of the currency row the order names.
     *
     * What a payment or a shipment row of the order is stamped with.
     *
     * @param int $orderId Order PK.
     *
     *
     * @throws OrderCurrencyException As {@see self::of()}.
     *
     * @since  2.0.0
     */
    public static function idOf(int $orderId): int
    {
        return self::of(orderId: $orderId)->getId();
    }

    /**
     * The currency an order that does not exist yet will take: the shop's
     * default.
     *
     * For the new-order form and for the figures of an order with no row.
     * Never an answer about an order that exists.
     *
     *
     * @since  2.0.0
     */
    public static function forNewOrder(): Currency
    {
        return Currency::getDefault();
    }

    /**
     * The sentence a refused command shows, in the reader's language.
     *
     * Plain text: the screen that prints it escapes it.
     *
     *
     * @since  2.0.0
     */
    public static function refusal(): string
    {
        return self::text(key: self::REFUSAL_KEY);
    }

    /**
     * The words a screen or a list shows in place of money.
     *
     * Plain text: the screen that prints it escapes it.
     *
     *
     * @since  2.0.0
     */
    public static function notice(): string
    {
        return self::text(key: self::NOTICE_KEY);
    }

    /**
     * The sentence shown when the order asked about has no row.
     *
     * Plain text: the screen that prints it escapes it.
     *
     *
     * @since  2.0.0
     */
    public static function notFound(): string
    {
        return self::text(key: self::NOT_FOUND_KEY);
    }

    /**
     * The order's currency columns, or null when the order has no row.
     *
     * @param int $orderId Order PK.
     *
     * @return object|null {id_currency, currency_scale}
     *
     * @since  2.0.0
     */
    private static function row(int $orderId): ?object
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id_currency'), $db->quoteName('currency_scale')])
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $orderId),
        );

        return $db->loadObject() ?: null;
    }

    /**
     * The currency a row names at its frozen scale, or why it names none.
     *
     * A row with no stored scale froze none, and the currency speaks the scale
     * it is configured with. A stored scale outside the supported range cannot
     * be read as one and is treated the same way.
     *
     * @param int $orderId Order PK, for the reason.
     * @param object|null $row {id_currency, currency_scale}, or null when
     *                         the order has no row.
     *
     * @return Currency|array{detail: string, cause: Throwable|null}
     *
     * @since  2.0.0
     */
    private static function resolve(int $orderId, ?object $row): Currency|array
    {
        if ($row === null) {
            return ['detail' => 'order #' . $orderId . ' does not exist', 'cause' => null];
        }

        $currencyId = (int) ($row->id_currency ?? 0);

        if ($currencyId <= 0) {
            return [
                'detail' => 'order #' . $orderId . ' stores id_currency ' . var_export($row->id_currency ?? null, true),
                'cause' => null,
            ];
        }

        try {
            $currency = Currency::loadById($currencyId);
        } catch (Throwable $e) {
            return [
                'detail' => 'order #' . $orderId . ' stores id_currency ' . $currencyId . ', which cannot be read: ' . $e->getMessage(),
                'cause' => $e,
            ];
        }

        $scale = $row->currency_scale ?? null;

        if ($scale === null || $scale === '') {
            return $currency;
        }

        try {
            return $currency->atScale((int) $scale);
        } catch (Throwable $e) {
            return $currency;
        }
    }

    /**
     * The refusal for an order, with its reason written to the log once per
     * request.
     *
     * @param int $orderId Order PK.
     * @param string $detail Why no currency can be answered.
     * @param Throwable|null $previous What failed, if a read did.
     * @param bool $orderExists False when the order has no row.
     *
     *
     * @since  2.0.0
     */
    private static function refuse(int $orderId, string $detail, ?Throwable $previous, bool $orderExists): OrderCurrencyException
    {
        if (!isset(self::$logged[$orderId])) {
            self::$logged[$orderId] = true;

            try {
                Log::add(
                    entry:    'OrderCurrency: ' . $detail . ($orderExists
                                  ? '. The order names no currency: it is refused for pricing, payment, shipment,'
                                    . ' documents and status changes until it is repaired.'
                                  : ': no currency can be answered for it.'),
                    priority: Log::ERROR,
                    category: 'com_alfa.orders',
                );
            } catch (Throwable $e) {
                // The refusal stands whether or not it could be logged.
            }
        }

        return new OrderCurrencyException(orderId: $orderId, detail: $detail, previous: $previous, orderExists: $orderExists);
    }

    /**
     * A sentence of the component's back-office language file.
     *
     * The storefront and the API do not load that file on their own.
     *
     * @param string $key Language key.
     *
     *
     * @since  2.0.0
     */
    private static function text(string $key): string
    {
        try {
            Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
        } catch (Throwable $e) {
            // No application to load a language into: the key is all there is.
        }

        return Text::_($key);
    }
}
