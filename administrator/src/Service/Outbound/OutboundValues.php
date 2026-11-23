<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\PricesState;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\PricingIntent;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;

/**
 * The values a batch of changes is told with, read when the batch leaves: one reader for a webhook's body and for a
 * plugin consumer's changes, so both are told the same figures.
 *
 * - stock: the unit's sku and available quantity as the inventory service keeps it on the product or the
 *   combination (exact decimal text); a unit that no longer exists has both null.
 * - price: the unit's sku, its selling price for the shop's default customer set (a guest, no place, the shop's default
 *   currency) at the quantity it opens with, without tax (net) and with it (gross), at the currency's places, priced by
 *   the pricing engine the price index is written from at the moment the batch leaves; null when no price applies; and
 *   prices_state, the state of the unit's own price rows that a price write sends back ({@see PricesState}).
 * - order.created: the order and the status it opened in.
 * - order.status: the order, the status it left and the status it entered.
 *
 * @since  2.2.2
 */
final class OutboundValues
{
    /**
     * The terms of the shop's default pricing context, when read.
     *
     * @var array{set: int, currency: int}|null|false False until read.
     *
     * @since  2.2.2
     */
    private static array|null|false $terms = false;

    /**
     * The values of each change by its delivery row id.
     *
     * @param list<object> $rows The delivery rows (id, topic, subject_id, unit_id, payload, created).
     *
     * @return array<int, array<string, mixed>> Row id => the change as it is told.
     *
     * @since  2.2.2
     */
    public static function for(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $db = OutboundConsumers::db();
        $stockUnits = [];
        $priceUnits = [];
        $orders = [];

        foreach ($rows as $row) {
            match ((string) $row->topic) {
                OutboundTopics::STOCK => $stockUnits[(int) $row->subject_id][(int) $row->unit_id] = true,
                OutboundTopics::PRICE => $priceUnits[(int) $row->subject_id][(int) $row->unit_id] = true,
                default => $orders[(int) $row->subject_id] = true,
            };
        }

        $units = $stockUnits === [] && $priceUnits === [] ? [] : self::units(db: $db, units: array_replace_recursive($stockUnits, $priceUnits));
        $prices = $priceUnits === [] ? [] : self::prices(db: $db, units: $priceUnits);
        $states = $priceUnits === [] ? [] : PricesState::ofUnits(units: $priceUnits);
        $statuses = $orders === [] ? [] : self::orderStatuses(db: $db, orderIds: array_keys($orders));
        $out = [];

        foreach ($rows as $row) {
            $topic = (string) $row->topic;
            $id = (int) $row->id;
            $itemId = (int) $row->subject_id;
            $unitId = (int) $row->unit_id;
            $change = ['id' => $id, 'topic' => $topic, 'occurred_at' => self::utc(at: (string) $row->created)];
            $unit = $units[$itemId . ':' . $unitId] ?? null;

            $out[$id] = match ($topic) {
                OutboundTopics::STOCK => $change + [
                    'item_id' => $itemId,
                    'combination_id' => $unitId,
                    'sku' => $unit['sku'] ?? null,
                    'available_quantity' => $unit['available'] ?? null,
                ],
                OutboundTopics::PRICE => $change + [
                    'item_id' => $itemId,
                    'combination_id' => $unitId,
                    'sku' => $unit['sku'] ?? null,
                    'price' => $unit === null ? null : ($prices[$itemId][$unitId] ?? null),
                    'prices_state' => $states[$itemId . ':' . $unitId] ?? null,
                ],
                OutboundTopics::ORDER_CREATED => $change + [
                    'order_id' => $itemId,
                    'status_id' => $statuses[$itemId] ?? null,
                ],
                default => $change + ['order_id' => $itemId] + self::statusMove(payload: (string) $row->payload),
            };
        }

        return $out;
    }

    /**
     * The terms of the pricing context the shop's default customer reads (a guest, no place, the default currency):
     * its set of pricing groups and its currency class; null while no such context is prepared.
     *
     * @return array{set: int, currency: int}|null
     *
     * @since  2.2.2
     */
    public static function defaultPriceTerms(): ?array
    {
        if (self::$terms !== false) {
            return self::$terms;
        }

        $read = PriceIndexSyncService::readContext(context: self::guestContext());

        if ($read === null) {
            return self::$terms = null;
        }

        $db = OutboundConsumers::db();
        $row = $db->setQuery(
            'SELECT ' . $db->quoteName('group_set_id') . ', ' . $db->quoteName('currency_id') . ' FROM ' . $db->quoteName(PriceIndexSyncService::CONTEXTS_TABLE)
            . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $read['context'],
        )->loadObject();

        return self::$terms = $row === null ? null : ['set' => (int) $row->group_set_id, 'currency' => (int) $row->currency_id];
    }

    /**
     * Forget the default context's terms read in this request (the contexts were rebuilt).
     *
     * @since  2.2.2
     */
    public static function forget(): void
    {
        self::$terms = false;
    }

    /**
     * The shop's address as a consumer is told it: the site's root URL.
     *
     * @since  2.2.2
     */
    public static function shop(): string
    {
        return rtrim(Uri::root(), '/') . '/';
    }

    /**
     * The default customer's pricing context: a guest with the guest's groups, no place, the shop's default currency.
     *
     * @since  2.2.2
     */
    private static function guestContext(): PriceContext
    {
        return PriceContext::forIndex(currencyId: Currency::getDefault()->getId(), placeId: 0, usergroupId: 0)->withCustomer(customer: new User());
    }

    /**
     * Each unit's sku and available quantity, by item:combination; a unit that no longer exists is absent.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<int, array<int, true>> $units Combination ids (0 for the product) by product id.
     *
     * @return array<string, array{sku: string, available: string|null}>
     *
     * @since  2.2.2
     */
    private static function units(DatabaseDriver $db, array $units): array
    {
        $out = [];
        $itemIds = array_map('intval', array_keys($units));
        $combinationIds = [];

        foreach ($units as $itemId => $combinations) {
            foreach (array_keys($combinations) as $combinationId) {
                if ((int) $combinationId > 0) {
                    $combinationIds[] = (int) $combinationId;
                }
            }
        }

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'sku', 'available_quantity']))
                ->from($db->quoteName('#__alfa_items'))
                ->whereIn($db->quoteName('id'), $itemIds),
        )->loadObjectList() ?: [] as $item) {
            $out[(int) $item->id . ':0'] = ['sku' => (string) ($item->sku ?? ''), 'available' => $item->available_quantity === null ? null : (string) $item->available_quantity];
        }

        if ($combinationIds !== []) {
            foreach ($db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'item_id', 'sku', 'available_quantity']))
                    ->from($db->quoteName('#__alfa_item_combinations'))
                    ->whereIn($db->quoteName('id'), $combinationIds),
            )->loadObjectList() ?: [] as $combination) {
                $out[(int) $combination->item_id . ':' . (int) $combination->id] = [
                    'sku' => (string) ($combination->sku ?? ''),
                    'available' => $combination->available_quantity === null ? null : (string) $combination->available_quantity,
                ];
            }
        }

        // A product row stands for its own unit only: one with combinations is told through them.
        foreach ($units as $itemId => $combinations) {
            if (!isset($combinations[0])) {
                unset($out[(int) $itemId . ':0']);
            }
        }

        return $out;
    }

    /**
     * Each unit's selling price for the shop's default customer, priced now by the pricing engine.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<int, array<int, true>> $units Combination ids (0 for the product) by product id.
     *
     * @return array<int, array<int, array{net: string, gross: string, currency: string}|null>> By product, then combination.
     *
     * @since  2.2.2
     */
    private static function prices(DatabaseDriver $db, array $units): array
    {
        $items = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'quantity_min']))
                ->from($db->quoteName('#__alfa_items'))
                ->whereIn($db->quoteName('id'), array_map('intval', array_keys($units))),
        )->loadObjectList('id') ?: [];
        $intent = PricingIntent::catalog();
        $quantities = [];
        $asked = [];

        foreach ($units as $itemId => $combinations) {
            if (!isset($items[$itemId])) {
                continue;
            }

            $quantities[(int) $itemId] = $intent->getQuantityForItem($items[$itemId]);
            $asked[(int) $itemId] = array_values(array_filter(array_map('intval', array_keys($combinations)), static fn (int $id): bool => $id > 0));
        }

        if ($asked === []) {
            return [];
        }

        $results = (new PriceCalculator())->calculateUnits($asked, $quantities, self::guestContext());
        $out = [];

        foreach ($units as $itemId => $combinations) {
            foreach (array_keys($combinations) as $combinationId) {
                $result = $results[(int) $itemId][(int) $combinationId] ?? null;
                $out[(int) $itemId][(int) $combinationId] = $result instanceof PriceResult && $result->isPriced() ? [
                    'net' => PublishedDecimal::money($result->getSubtotalPrice()),
                    'gross' => PublishedDecimal::money($result->getPrice()),
                    'currency' => $result->getPrice()->getCurrency()->getCode(),
                ] : null;
            }
        }

        return $out;
    }

    /**
     * The status each order is in now.
     *
     * @param DatabaseDriver $db The connection.
     * @param int[] $orderIds The orders.
     *
     * @return array<int, int>
     *
     * @since  2.2.2
     */
    private static function orderStatuses(DatabaseDriver $db, array $orderIds): array
    {
        $out = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_order_status']))
                ->from($db->quoteName('#__alfa_orders'))
                ->whereIn($db->quoteName('id'), array_map('intval', $orderIds)),
        )->loadObjectList() ?: [] as $order) {
            $out[(int) $order->id] = (int) $order->id_order_status;
        }

        return $out;
    }

    /**
     * An order.status row's move, from its payload from:to.
     *
     * @param string $payload The row's payload.
     *
     * @return array{from_status_id: int|null, to_status_id: int|null}
     *
     * @since  2.2.2
     */
    private static function statusMove(string $payload): array
    {
        $parts = explode(':', $payload);

        return [
            'from_status_id' => isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : null,
            'to_status_id' => isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null,
        ];
    }

    /**
     * A stored UTC moment as ISO 8601 with its microseconds and Z.
     *
     * @param string $at The stored moment, `Y-m-d H:i:s.u` UTC.
     *
     * @since  2.2.2
     */
    private static function utc(string $at): string
    {
        return (new DateTimeImmutable($at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
