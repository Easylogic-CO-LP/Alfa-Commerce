<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

/**
 * The changes the shop tells a consumer of: a unit's available stock, a unit's selling price, an order placed, an
 * order's status. A unit is a product without combinations, or one combination of a product.
 *
 * Stock and price are merged: a unit waits once per consumer and topic, and the figure sent is the one the shop holds
 * when the notification leaves. Order changes are each told, in the order they happened.
 *
 * @since  2.2.2
 */
final class OutboundTopics
{
    /** @since 2.2.2 */
    public const STOCK = 'stock';

    /** @since 2.2.2 */
    public const PRICE = 'price';

    /** @since 2.2.2 */
    public const ORDER_CREATED = 'order.created';

    /** @since 2.2.2 */
    public const ORDER_STATUS = 'order.status';

    /**
     * The topic of a test sent from the Webhooks screen: never queued, never told by a change.
     *
     * @since 2.2.2
     */
    public const PING = 'ping';

    /**
     * Every topic a consumer may take, in the order the screens list them.
     *
     * @var string[]
     *
     * @since 2.2.2
     */
    public const ALL = [self::STOCK, self::PRICE, self::ORDER_CREATED, self::ORDER_STATUS];

    /**
     * The topics whose waiting row is one a unit (the value is read when the row is sent).
     *
     * @var string[]
     *
     * @since 2.2.2
     */
    public const MERGED = [self::STOCK, self::PRICE];

    /**
     * Whether a topic is one of the shop's.
     *
     * @param string $topic The topic.
     *
     * @since  2.2.2
     */
    public static function known(string $topic): bool
    {
        return \in_array($topic, self::ALL, true);
    }

    /**
     * A stored comma list of topics as the known topics it names, in the order of ALL.
     *
     * @param string|array<int, mixed> $topics The stored list, or a list of topics.
     *
     * @return string[]
     *
     * @since  2.2.2
     */
    public static function parse(string|array $topics): array
    {
        $given = \is_array($topics) ? array_map('strval', $topics) : explode(',', $topics);
        $given = array_map('trim', $given);

        return array_values(array_filter(self::ALL, static fn (string $topic): bool => \in_array($topic, $given, true)));
    }

    /**
     * The language key of a topic's name for staff.
     *
     * @param string $topic The topic.
     *
     * @since  2.2.2
     */
    public static function label(string $topic): string
    {
        return 'COM_ALFA_OUTBOUND_TOPIC_' . strtoupper(str_replace('.', '_', $topic));
    }
}
