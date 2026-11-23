<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\AddToCartCommand;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartResolution;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Cart\CartItemLoader;
use Alfa\Component\Alfa\Site\Service\Cart\CartService;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;

/**
 * Buying an order again: its lines put in the visitor's cart at today's prices and availability, never the order's.
 *
 * Each line goes through the cart's own add (CartService::addCommand()), with the product and the choices the order
 * recorded, so the catalogue, the stock and the product's quantity rules decide as for any add. A line that cannot go
 * in is named with why, and the rest go in:
 * - its product is no longer for sale to this customer (gone, unpublished, out of its dates, hidden from their groups,
 *   or withheld while out of stock): GONE;
 * - its price was set by the shop for that order (a price staff gave the line): PRICED;
 * - it was made to the customer's own choices (a field that took a composition or an upload), which an add cannot
 *   repeat: CHOICES;
 * - one of its options or its combination is no longer offered: OPTIONS;
 * - the cart refused it (no stock now, the product's own rules): NOT_ADDED.
 * A line the stock can only partly fill goes in with what is available, and says so: PARTIAL. Nothing is placed and the
 * order is not touched.
 *
 * @since  2.3.0
 */
final class Reorder
{
    /** The line went in whole. */
    public const ADDED = 'added';

    /** The line went in with less than the order had: what the stock holds now. */
    public const PARTIAL = 'partial';

    /** Its product is no longer for sale to this customer. */
    public const GONE = 'gone';

    /** Its price was set by the shop for that order. */
    public const PRICED = 'priced';

    /** It was made to the customer's own choices, which an add cannot repeat. */
    public const CHOICES = 'choices';

    /** One of its options or its combination is no longer offered. */
    public const OPTIONS = 'options';

    /** The cart refused it. */
    public const NOT_ADDED = 'not_added';

    /**
     * Put an order's lines in the visitor's own cart. Whose order it is, the caller has asked.
     *
     * @param int $orderId The order.
     *
     * @return object[] One per line, in the order's order: name, wanted (the order's quantity), added (what went in),
     *                  outcome (one of the constants above).
     *
     * @since  2.3.0
     */
    public static function order(int $orderId): array
    {
        $lines = self::lines(orderId: $orderId);

        if ($lines === []) {
            return [];
        }

        $cart = CartService::ambient();
        $loader = new CartItemLoader(self::db());
        $units = $cart->summary(CartResolution::Current)->quantity;
        $outcomes = [];

        foreach ($lines as $line) {
            $outcome = (object) ['name' => $line->name, 'wanted' => $line->quantity, 'added' => 0, 'outcome' => self::NOT_ADDED];
            $outcomes[] = $outcome;

            if ($line->priced) {
                $outcome->outcome = self::PRICED;

                continue;
            }

            if ($line->composed) {
                $outcome->outcome = self::CHOICES;

                continue;
            }

            $item = $loader->load($line->itemId, requirePublished: true);

            if ($item === null) {
                $outcome->outcome = self::GONE;

                continue;
            }

            $selection = ['selection' => $line->choices, 'optionValueIds' => $line->choices, 'payloads' => []];
            $offered = VariantHelper::offeredSelection(item: $item, request: $selection);

            if (\count($offered['selection'] + $offered['optionValueIds']) < \count($line->choices)) {
                $outcome->outcome = self::OPTIONS;

                continue;
            }

            $result = $cart->addCommand(command: new AddToCartCommand(itemId: $line->itemId, quantity: $line->quantity, selection: $offered));

            if (!$result->added) {
                continue;
            }

            $outcome->added = max(0, $result->quantity - $units);
            $outcome->outcome = $outcome->added >= $line->quantity ? self::ADDED : self::PARTIAL;
            $units = $result->quantity;
        }

        return $outcomes;
    }

    /**
     * What a customer reads about an order bought again: the lines that went in, and each line that did not or went
     * in partly, with why. One message per kind, in the order's order.
     *
     * @param object[] $outcomes What order() answered.
     *
     * @return array<int, array{0: string, 1: string}> Each: the sentence, and its message type (message or warning).
     *
     * @since  2.3.0
     */
    public static function sentences(array $outcomes): array
    {
        $added = [];
        $partial = false;
        $messages = [];

        foreach ($outcomes as $outcome) {
            // A name is the order's frozen text: it is printed as text in the message queue's HTML.
            $name = htmlspecialchars((string) $outcome->name, ENT_QUOTES, 'UTF-8');

            if ($outcome->outcome === self::ADDED) {
                $added[] = $name;

                continue;
            }

            $partial = $partial || $outcome->outcome === self::PARTIAL;
            $messages[] = [
                $outcome->outcome === self::PARTIAL
                    ? Text::sprintf('COM_ALFA_REORDER_PARTIAL', $name, $outcome->added, $outcome->wanted)
                    : Text::sprintf('COM_ALFA_REORDER_' . strtoupper((string) $outcome->outcome), $name),
                'warning',
            ];
        }

        if ($added !== []) {
            array_unshift($messages, [Text::sprintf('COM_ALFA_REORDER_ADDED', implode(', ', $added)), 'message']);
        } elseif (!$partial) {
            array_unshift($messages, [Text::_('COM_ALFA_REORDER_NOTHING_ADDED'), 'warning']);
        }

        return $messages;
    }

    /**
     * The order's lines as an add needs them: the product, how many, the choices the order recorded (field => value,
     * for the fields that took a value), whether a field took a composition, whether staff set the line's price, and
     * the line's name as the order froze it, in the current language.
     *
     * @param int $orderId The order.
     *
     * @return object[]
     *
     * @since  2.3.0
     */
    private static function lines(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'id_item', 'quantity', 'name', 'custom_unit_price_tax_incl']))
                ->from($db->quoteName('#__alfa_order_items'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId)
                ->order($db->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];

        $fields = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id_order_item', 'id_item_field', 'id_item_field_value', 'payload']))
                ->from($db->quoteName('#__alfa_order_item_fields'))
                ->where($db->quoteName('id_order') . ' = ' . $orderId),
        )->loadObjectList() ?: [];

        $choices = [];
        $composed = [];

        foreach ($fields as $field) {
            $lineId = (int) $field->id_order_item;

            if (trim((string) ($field->payload ?? '')) !== '') {
                $composed[$lineId] = true;
            }

            if ((int) $field->id_item_field > 0 && (int) $field->id_item_field_value > 0) {
                $choices[$lineId][(int) $field->id_item_field] = (int) $field->id_item_field_value;
            }
        }

        return array_map(
            static fn (object $row): object => (object) [
                'itemId' => (int) $row->id_item,
                'quantity' => max(1, (int) $row->quantity),
                'name' => MultilingualHelper::resolveLangMap((string) $row->name),
                'choices' => $choices[(int) $row->id] ?? [],
                'composed' => isset($composed[(int) $row->id]),
                'priced' => $row->custom_unit_price_tax_incl !== null,
            ],
            $rows,
        );
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
