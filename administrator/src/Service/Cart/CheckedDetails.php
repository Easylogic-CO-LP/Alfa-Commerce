<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

/**
 * What the customer-details check handed back.
 *
 * Two things come out of running typed details through the checkout form,
 * and a caller needs both: whether the merchant's rules accept them, and the
 * values the form MADE of them. The second is not the input. Filtering is the
 * step that turns a telephone number into E.164 or a numeric field into a
 * number, and the storefront has always stored the filtered copy its model
 * returns; a caller that validated and then kept what it was given would
 * store a different shape from the same form.
 *
 * @since  2.0.0
 */
final class CheckedDetails
{
    /**
     * @param array $delivery The delivery details as the form filtered them.
     * @param array $billing The invoice details as the form filtered them, or [].
     * @param array $failures Why the details were refused, as
     *                        `[['code' => language key, 'params' => []], ...]`;
     *                        empty when they were not.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly array $delivery,
        public readonly array $billing,
        public readonly array $failures,
    ) {
    }

    /**
     * Whether the details may be placed with.
     *
     *
     * @since  2.0.0
     */
    public function valid(): bool
    {
        return $this->failures === [];
    }

    /**
     * Details that could not be checked at all: refused for that reason and
     * handed back as given, since there is no filtered copy to prefer.
     *
     * @param array $delivery The delivery details as given.
     * @param array $billing The invoice details as given.
     *
     *
     * @since  2.0.0
     */
    public static function uncheckable(array $delivery, array $billing): self
    {
        return new self(
            delivery: $delivery,
            billing: $billing,
            failures: [['code' => 'COM_ALFA_ORDER_PLACE_DETAILS_UNCHECKABLE', 'params' => []]],
        );
    }
}
