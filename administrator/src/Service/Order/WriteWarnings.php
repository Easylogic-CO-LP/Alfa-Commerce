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
 * What an order write changed or left out on its own, said to the caller (2.2.2): the REST answer of the write carries
 * them as meta.warnings, each {code, detail, pointer}.
 *
 * A write that stands but did not do exactly what was sent notes it here: a second parcel's charge set to zero because
 * the order already charges shipping, a parcel's quantity cut to what the order still has to send, a detail field the
 * shop's display rules hide, or a detail key the section has no field for (neither saved). The notes live for the
 * request; the order view reads them when it answers. The back office tells its operator through its own messages,
 * so nothing here is shown there.
 *
 * code    a fixed name for the kind of change, never reworded: shipping_charged_once, quantity_cut, hidden_by_rule,
 *         not_a_field.
 * detail  the sentence, in the request's language.
 * pointer where in the request body the value was (a JSON pointer, "/alfa_form_fields/vatnumber"); '' for none.
 *
 * @since  2.2.2
 */
final class WriteWarnings
{
    /**
     * A second open parcel's charge was set to zero: the order already charges shipping on a parcel.
     *
     * @since  2.2.2
     */
    public const SHIPPING_CHARGED_ONCE = 'shipping_charged_once';

    /**
     * A parcel's quantity of a line was cut to what the order still has to send of it.
     *
     * @since  2.2.2
     */
    public const QUANTITY_CUT = 'quantity_cut';

    /**
     * A detail field was not saved: the shop's display rule hides it for the values sent.
     *
     * @since  2.2.2
     */
    public const HIDDEN_BY_RULE = 'hidden_by_rule';

    /**
     * A detail key was not saved: the section has no such field on this shop.
     *
     * @since  2.2.2
     */
    public const NOT_A_FIELD = 'not_a_field';

    /**
     * The notes of this request, in the order they were made.
     *
     * @var list<array{code: string, detail: string, pointer: string}>
     *
     * @since  2.2.2
     */
    private static array $notes = [];

    /**
     * Note one change a write made on its own.
     *
     * @param string $code One of the constants of this class.
     * @param string $detail The sentence.
     * @param string $pointer The JSON pointer into the request body, '' for none.
     *
     * @since  2.2.2
     */
    public static function note(string $code, string $detail, string $pointer = ''): void
    {
        self::$notes[] = ['code' => $code, 'detail' => $detail, 'pointer' => $pointer];
    }

    /**
     * The notes of this request, in the order they were made.
     *
     * @return list<array{code: string, detail: string, pointer: string}>
     *
     * @since  2.2.2
     */
    public static function all(): array
    {
        return self::$notes;
    }
}
