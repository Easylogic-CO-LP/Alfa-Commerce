<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

/**
 * Where an order came from, the order's source: the storefront's checkout (storefront), the order screen (admin), a
 * subscription's renewal (renewal), or a client of the REST API, which names its channel itself (a marketplace's
 * connector writes, for example, base) and is api when it names none. Written once, when the order is created.
 *
 * @api READ ONLY: the source a REST API client may give an order (lower-case letters, digits, _ and -, 1 to 32
 *      characters, none of core's own) and the one an order of the REST API takes when it gives none.
 *
 * @since  2.2.2
 */
final class OrderSource
{
    /**
     * An order the REST API creates with no source of its client's.
     *
     * @since  2.2.2
     */
    public const API = 'api';

    /**
     * The sources core writes for its own doors; a client may not take them.
     *
     * @since  2.2.2
     */
    public const CORE = ['storefront', 'admin', 'renewal'];

    /**
     * A source a client may give: lower-case letters, digits, underscore and hyphen, 1 to 32 characters.
     *
     * @since  2.2.2
     */
    public const PATTERN = '/^[a-z0-9_-]{1,32}$/';

    /**
     * The source of an order a REST API client creates: the one it sends, in its shape and none of core's own, or api
     * when it sends none.
     *
     * @param mixed $value The value sent; null or '' for none.
     *
     * @throws OrderPartRefusedException When it is not in its shape, or is one of core's own (MALFORMED).
     *
     * @since  2.2.2
     */
    public static function ofClient(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::API;
        }

        if (!\is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new OrderPartRefusedException('/source', 'must be lower-case letters, digits, _ and -, 1 to 32 characters (the channel the order comes from, for example base); without it the order is api.', OrderPartRefusedException::MALFORMED);
        }

        if (\in_array($value, self::CORE, true)) {
            throw new OrderPartRefusedException('/source', '"' . $value . '" is the source core writes for its own orders (' . implode(', ', self::CORE) . '); name the channel the order comes from, or send none for api.', OrderPartRefusedException::MALFORMED);
        }

        return $value;
    }
}
