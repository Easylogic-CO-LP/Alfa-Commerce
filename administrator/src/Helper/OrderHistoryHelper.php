<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Throwable;

\defined('_JEXEC') or die;

/**
 * The order History tab's line for one stored activity entry.
 *
 * PRESENTATION ONLY. The stored row (#__alfa_order_activity_log) and the code
 * that writes it are not changed: an entry is read from its event and the
 * facts its context holds, in the administrator's language. An amount is
 * shown in the order's currency as every other admin amount is
 * (Money::format()), a document by its type's label and its number, a status
 * change by the status names and who made it. Where the context lacks a fact
 * the line needs (an old row, another writer's row, a status or a user that no
 * longer exists), the stored summary is shown as it was written.
 *
 * @since  2.1.0
 */
final class OrderHistoryHelper
{
    /**
     * The line the History tab shows for one entry.
     *
     * @param object $entry A #__alfa_order_activity_log row: event, summary,
     *                      context (JSON), id_employee, employee_name.
     * @param object $order The order on screen: ->currency, its Currency at the
     *                      order's frozen scale, and ->items, its lines (->id,
     *                      ->name in the admin language).
     *
     * @return string Plain text; the caller escapes it.
     *
     * @since  2.1.0
     */
    public static function line(object $entry, object $order): string
    {
        $stored = (string) ($entry->summary ?? '');
        $raw = $entry->context ?? null;
        $context = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!\is_array($context)) {
            return $stored;
        }

        try {
            $line = match ((string) ($entry->event ?? '')) {
                'document.issued' => self::document(context: $context),
                'payment.added' => self::payment(key: 'COM_ALFA_ORDER_HISTORY_PAYMENT_ADDED', context: $context, order: $order),
                'payment.refunded' => self::payment(key: 'COM_ALFA_ORDER_HISTORY_PAYMENT_REFUNDED', context: $context, order: $order),
                'payment.refund_reserved' => self::payment(key: 'COM_ALFA_ORDER_HISTORY_PAYMENT_REFUND_RESERVED', context: $context, order: $order),
                'payment.deleted' => self::payment(key: 'COM_ALFA_ORDER_HISTORY_PAYMENT_DELETED', context: $context, order: $order),
                'payment.charged_back' => self::payment(key: 'COM_ALFA_ORDER_HISTORY_PAYMENT_CHARGED_BACK', context: $context, order: $order, reference: 'id_refunded_payment'),
                'payment.refund_reversed' => self::payment(key: 'COM_ALFA_ORDER_HISTORY_PAYMENT_REFUND_REVERSED', context: $context, order: $order, reference: 'id_reversed_payment'),
                'payment.chargeback_reversed' => self::payment(key: 'COM_ALFA_ORDER_HISTORY_PAYMENT_CHARGEBACK_REVERSED', context: $context, order: $order, reference: 'id_reversed_payment'),
                'shipment.added' => self::shipmentAdded(context: $context, order: $order),
                'shipment.deleted' => self::shipmentDeleted(context: $context, order: $order),
                'order.status_changed' => self::statusChanged(context: $context, entry: $entry),
                'order.transition_refused' => self::statusRefused(context: $context, entry: $entry),
                default => null,
            };
        } catch (Throwable) {
            // A context this reader cannot use is a context without the facts.
            $line = null;
        }

        return $line ?? $stored;
    }

    /**
     * A document issued: its type's label and its number.
     *
     * @param array $context The entry's context.
     *
     * @return string|null Null when the context lacks either.
     *
     * @since  2.1.0
     */
    private static function document(array $context): ?string
    {
        $type = $context['doc_type'] ?? null;
        $number = $context['number'] ?? null;

        if (!\is_string($type) || $type === '' || !(\is_string($number) || \is_int($number)) || (string) $number === '') {
            return null;
        }

        return Text::sprintf('COM_ALFA_ORDER_HISTORY_DOCUMENT_ISSUED', DocumentHelper::typeLabel($type), (string) $number);
    }

    /**
     * A payment row's entry: its amount and its method, and the row it names.
     *
     * @param string $key The language key.
     * @param array $context The entry's context.
     * @param object $order The order on screen.
     * @param string|null $reference The context key of the payment row the entry names, if any.
     *
     * @return string|null Null when the context lacks a fact.
     *
     * @since  2.1.0
     */
    private static function payment(string $key, array $context, object $order, ?string $reference = null): ?string
    {
        $amount = self::money(context: $context, key: 'amount', order: $order);
        $method = self::name(context: $context, key: 'payment_method');

        if ($amount === null || $method === null) {
            return null;
        }

        if ($reference === null) {
            return Text::sprintf($key, $amount, $method);
        }

        $id = self::id(value: $context[$reference] ?? null);

        return $id === null ? null : Text::sprintf($key, $amount, $method, $id);
    }

    /**
     * A shipment added: its charge, its method and what it carries.
     *
     * @param array $context The entry's context.
     * @param object $order The order on screen.
     *
     * @return string|null Null when the context lacks a fact, or names a line the order no longer has.
     *
     * @since  2.1.0
     */
    private static function shipmentAdded(array $context, object $order): ?string
    {
        $cost = self::money(context: $context, key: 'shipping_cost_tax_incl', order: $order);
        $method = self::name(context: $context, key: 'shipment_method_name');
        $assigned = $context['assigned_items'] ?? [];

        if ($cost === null || $method === null || !\is_array($assigned)) {
            return null;
        }

        if ($assigned === []) {
            return Text::sprintf('COM_ALFA_ORDER_HISTORY_SHIPMENT_ADDED', $cost, $method);
        }

        // The writer's own map, [line id => quantity in this parcel]; the
        // names are the lines' frozen names in the admin language.
        $names = [];

        foreach ((array) ($order->items ?? []) as $item) {
            $names[(int) ($item->id ?? 0)] = (string) ($item->name ?? '');
        }

        $parts = [];

        foreach ($assigned as $lineId => $quantity) {
            $quantity = self::id(value: $quantity);

            if (!\is_int($lineId) || ($names[$lineId] ?? '') === '' || $quantity === null) {
                return null;
            }

            $parts[] = $names[$lineId] . ' x' . $quantity;
        }

        return Text::sprintf('COM_ALFA_ORDER_HISTORY_SHIPMENT_ADDED_ITEMS', $cost, $method, implode(', ', $parts));
    }

    /**
     * A shipment deleted: its charge and its method.
     *
     * @param array $context The entry's context.
     * @param object $order The order on screen.
     *
     * @return string|null Null when the context lacks a fact.
     *
     * @since  2.1.0
     */
    private static function shipmentDeleted(array $context, object $order): ?string
    {
        $cost = self::money(context: $context, key: 'shipping_cost_tax_incl', order: $order);
        $method = self::name(context: $context, key: 'shipment_method_name');

        return $cost === null || $method === null ? null : Text::sprintf('COM_ALFA_ORDER_HISTORY_SHIPMENT_DELETED', $cost, $method);
    }

    /**
     * A status change: the two status names, who made it and its note.
     *
     * @param array $context The entry's context.
     * @param object $entry The stored entry.
     *
     * @return string|null Null when the context lacks a fact.
     *
     * @since  2.1.0
     */
    private static function statusChanged(array $context, object $entry): ?string
    {
        $from = self::statusName(id: $context['old_status'] ?? null);
        $to = self::statusName(id: $context['new_status'] ?? null);
        $actor = self::actor(actor: $context['actor'] ?? null, entry: $entry);
        $note = $context['note'] ?? '';

        if ($from === null || $to === null || $actor === null || !\is_string($note)) {
            return null;
        }

        return $note === ''
            ? Text::sprintf('COM_ALFA_ORDER_HISTORY_STATUS_CHANGED', $from, $to, $actor)
            : Text::sprintf('COM_ALFA_ORDER_HISTORY_STATUS_CHANGED_NOTE', $from, $to, $actor, $note);
    }

    /**
     * A status change that was refused: the two status names, who asked and why.
     *
     * @param array $context The entry's context.
     * @param object $entry The stored entry.
     *
     * @return string|null Null when the context lacks a fact.
     *
     * @since  2.1.0
     */
    private static function statusRefused(array $context, object $entry): ?string
    {
        $from = self::statusName(id: $context['from'] ?? null);
        $to = self::statusName(id: $context['to'] ?? null);
        $actor = self::actor(actor: $context['actor'] ?? null, entry: $entry);
        $reason = $context['reason'] ?? null;

        if ($from === null || $to === null || $actor === null || !\is_string($reason) || $reason === '') {
            return null;
        }

        return Text::sprintf('COM_ALFA_ORDER_HISTORY_STATUS_REFUSED', $from, $to, $actor, $reason);
    }

    /**
     * An amount of the context in the order's currency, as admin money is shown.
     *
     * @param array $context The entry's context.
     * @param string $key The amount's key.
     * @param object $order The order on screen.
     *
     * @return string|null Null when there is no number or no currency.
     *
     * @since  2.1.0
     */
    private static function money(array $context, string $key, object $order): ?string
    {
        $currency = $order->currency ?? null;
        $amount = $context[$key] ?? null;

        if (!$currency instanceof Currency || !(\is_int($amount) || \is_float($amount) || (\is_string($amount) && is_numeric($amount)))) {
            return null;
        }

        return Money::of($amount, $currency)->format();
    }

    /**
     * A name the context states; "None" when it states an empty one.
     *
     * @param array $context The entry's context.
     * @param string $key The name's key.
     *
     * @return string|null Null when the context states none.
     *
     * @since  2.1.0
     */
    private static function name(array $context, string $key): ?string
    {
        $name = $context[$key] ?? null;

        if (!\is_string($name)) {
            return null;
        }

        return $name !== '' ? $name : Text::_('COM_ALFA_ORDER_HISTORY_NAME_NONE');
    }

    /**
     * A positive whole number the context states (a row id, a quantity).
     *
     * @param mixed $value The stated value.
     *
     * @return int|null Null when it is not one.
     *
     * @since  2.1.0
     */
    private static function id(mixed $value): ?int
    {
        if (\is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return \is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * An order status by its name in the admin language.
     *
     * @param mixed $id The stated status id.
     *
     * @return string|null Null when the shop no longer has it.
     *
     * @since  2.1.0
     */
    private static function statusName(mixed $id): ?string
    {
        $id = self::id(value: $id);
        $name = $id === null ? '' : (string) (AlfaHelper::getOrderStatuses()[$id]->name ?? '');

        return $name !== '' ? $name : null;
    }

    /**
     * Who made a change, as the writer named it: "plugin:<element>" or "user:<id>".
     *
     * @param mixed $actor The stated actor.
     * @param object $entry The stored entry: its user snapshot names the user when it is the same one.
     *
     * @return string|null Null for another form, or a user who no longer exists.
     *
     * @since  2.1.0
     */
    private static function actor(mixed $actor, object $entry): ?string
    {
        if (!\is_string($actor) || preg_match('/^(plugin|user):(.+)$/', $actor, $m) !== 1) {
            return null;
        }

        if ($m[1] === 'plugin') {
            return Text::sprintf('COM_ALFA_ORDER_HISTORY_ACTOR_PLUGIN', $m[2]);
        }

        if (!ctype_digit($m[2])) {
            return null;
        }

        $userId = (int) $m[2];

        if ($userId === 0) {
            return Text::_('COM_ALFA_ORDER_HISTORY_ACTOR_SYSTEM');
        }

        // The entry's own snapshot when it names the same user, as recorded.
        if ((int) ($entry->id_employee ?? 0) === $userId && (string) ($entry->employee_name ?? '') !== '') {
            return (string) $entry->employee_name;
        }

        return self::userName(userId: $userId);
    }

    /**
     * A user's name, read once per request; null when there is no such user.
     *
     * Read from the users table rather than loaded as a User: loading a user
     * who no longer exists raises a message on the page.
     *
     * @param int $userId The user id.
     *
     * @return string|null The name, or null.
     *
     * @since  2.1.0
     */
    private static function userName(int $userId): ?string
    {
        static $names = [];

        if (!\array_key_exists($userId, $names)) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $name = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('name'))
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('id') . ' = ' . $userId),
            )->loadResult();

            $names[$userId] = \is_string($name) && $name !== '' ? $name : null;
        }

        return $names[$userId];
    }
}
