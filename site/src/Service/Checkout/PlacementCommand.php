<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Checkout;

defined('_JEXEC') or die;

/**
 * Everything placing an order needs, and nothing about how it was asked for.
 *
 * Placement already took the customer's details as arguments; what it still
 * reached into the request for was three integers: which payment method, which
 * shipping method, and whether to spend the customer's credit. Three integers
 * were the whole reason an order could only be placed by a browser.
 *
 * TWO OF THEM WERE READ TWICE. The payment and shipping ids were read once in
 * placeOrder() and again inside validateOrderPrerequisites(), from the same
 * request, so they could not disagree, but only because nothing between the
 * two reads could change the request. Here they are read once and carried, and
 * the "could not disagree" becomes "cannot".
 *
 * THREE MORE THINGS WERE NOT INTEGERS BUT WERE STILL THE REQUEST'S: the language
 * the order is written in, the address it came from, and how it was placed. Left
 * ambient, an order taken over the phone is written in the OPERATOR's language,
 * stamped with the OPERATOR's IP, and labelled as having come from the
 * storefront. All three default to exactly what they did before, so a shopper's
 * checkout is unchanged; supplying them is how any other caller tells the truth.
 *
 * THE DETAILS ARE ALREADY VALIDATED when they arrive. Whoever builds this
 * command is responsible for that: the storefront controller runs the form's
 * own rules and passes the filtered result. This object does not validate; it
 * carries. What it does guarantee is SHAPE: ids are integers, buckets are
 * arrays, and the credit flag is a boolean rather than whatever a request had.
 *
 * @since  2.0.0
 */
final class PlacementCommand
{
    /**
     * A shopper checking out.
     *
     * @since  2.0.0
     */
    public const SOURCE_STOREFRONT = 'storefront';

    /**
     * Someone placing the order for them: over the phone, at a counter.
     *
     * @since  2.0.0
     */
    public const SOURCE_ADMIN = 'admin';

    /**
     * A subscription coming round again.
     *
     * @since  2.0.0
     */
    public const SOURCE_RENEWAL = 'renewal';

    /**
     * A machine.
     *
     * @since  2.0.0
     */
    public const SOURCE_API = 'api';

    /**
     * @param array $delivery The delivery details, already validated and
     *                        filtered, keyed by field name.
     * @param array $billing The invoice details; empty means "same as
     *                       delivery", which is the ordinary case.
     * @param int $paymentMethodId The chosen payment method.
     * @param int $shipmentMethodId The chosen shipping method.
     * @param bool $useCredit The customer asked to spend their credit
     *                        balance on this order. Ignored for a guest,
     *                        who has no credit account to spend from.
     * @param string|null $languageTag The language the ORDER is in: the one its
     *                                 confirmation and documents are written in.
     *                                 Null takes the running application's, which
     *                                 is right for a shopper and wrong for anyone
     *                                 placing an order on their behalf.
     * @param string|null $ipAddress Where the order came from. Null takes the
     *                               request's, which for a back-office or
     *                               scheduled placement records the operator or
     *                               the server rather than the customer.
     * @param string $source How the order was placed. The vocabulary the
     *                       column documents is
     *                       storefront | admin | renewal | api.
     *                       NOT validated here, deliberately: the column
     *                       is documentation, and a caller inventing a
     *                       word is a caller with a reason.
     * @param int $initialStatusId The status the order should LAND in when it
     *                             is created. 0 (the default) means the store's
     *                             configured is_initial status, so a shopper's
     *                             checkout is unchanged. An operator placing an
     *                             order may name a different published status;
     *                             it is validated and committed atomically by
     *                             OrderCreator, which downgrades an unknown one
     *                             to the initial status and refuses one that
     *                             requires payment a new order cannot have.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly array $delivery,
        public readonly array $billing = [],
        public readonly int $paymentMethodId = 0,
        public readonly int $shipmentMethodId = 0,
        public readonly bool $useCredit = false,
        public readonly ?string $languageTag = null,
        public readonly ?string $ipAddress = null,
        public readonly string $source = self::SOURCE_STOREFRONT,
        public readonly int $initialStatusId = 0,
    ) {
    }
}
