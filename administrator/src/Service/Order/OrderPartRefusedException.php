<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * A part of an order created in one call that was not written, with the sentence that names the part: a line,
 * the parcel or the payment ({@see OrderCreateParts}). Nothing of the order is written when one is refused.
 *
 * Set as the order model's error: the REST create answers it with its status, 422 for a part sent in a shape it does
 * not take, 409 for a part its writer refused (a product that cannot be sold, a parcel's charge the tax rule refuses,
 * a payment beyond what the order owes), with the plain sentence.
 *
 * @since  2.2.2
 */
class OrderPartRefusedException extends RuntimeException
{
    /**
     * A part sent in a shape the create does not take.
     *
     * @since  2.2.2
     */
    public const MALFORMED = 422;

    /**
     * A part its writer refused.
     *
     * @since  2.2.2
     */
    public const REFUSED = 409;

    /**
     * What a refused create leaves: no order.
     *
     * @since  2.2.2
     */
    public const NOT_CREATED = 'The order was not created.';

    /**
     * What a refused update of an order leaves: the order as it was.
     *
     * @since  2.2.2
     */
    public const NOT_SAVED = 'Nothing was saved.';

    /**
     * The sentence as text, without the HTML escaping the message carries for the back office.
     *
     * @since  2.2.2
     */
    public readonly string $plain;

    /**
     * @param string $part The part as a JSON pointer into the body: /lines/0, /shipment, /payment, or a key of one
     *                     (/lines/0/quantity).
     * @param string $reason Why, as text.
     * @param int $status self::MALFORMED or self::REFUSED.
     * @param string $outcome What the refused write leaves: self::NOT_CREATED for a create, self::NOT_SAVED for an
     *                        update.
     *
     * @since  2.2.2
     */
    public function __construct(
        public readonly string $part,
        public readonly string $reason,
        public readonly int $status = self::REFUSED,
        string $outcome = self::NOT_CREATED,
    ) {
        // One sentence after another: a reason that ends without its full stop is given one.
        $sentence = rtrim($reason);
        $this->plain = $part . ': ' . $sentence . (preg_match('/[.!?]$/u', $sentence) === 1 ? '' : '.') . ' ' . $outcome;
        parent::__construct(htmlspecialchars($this->plain, ENT_QUOTES, 'UTF-8'), $status);
    }
}
