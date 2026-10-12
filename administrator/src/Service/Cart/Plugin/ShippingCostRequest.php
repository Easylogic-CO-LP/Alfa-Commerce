<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use Alfa\Component\Alfa\Site\Service\Cart\Method;

defined('_JEXEC') or die;

/**
 * What a carrier is asked to price.
 *
 * Everything a shipping plugin needs to quote for this cart, settled before the
 * question is put: the named fields are the answer to the common question, and
 * `$cart` is there for the carrier whose question is not common.
 *
 * THERE IS NO POSTCODE HERE, DELIBERATELY. A postcode is a CARRIER's input, not
 * the cart's: the component neither prices by one nor knows which checkout
 * field would hold one, and a shop delivering to lockers has none at all. It
 * used to be resolved from a hardcoded `zip_code` column and substituted with
 * the string '000000' when absent, so every carrier compared its bands against
 * an address nobody had supplied. A carrier that prices by postcode now names
 * the field in its own settings and reads it off `$cart->delivery()`, where an
 * absent one is null and stays null.
 *
 * ACYCLIC BY CONSTRUCTION. There is no money on this request at all: the goods
 * total lives on the cart, as `$cart->getSubtotal()`, already settled
 * before any plugin was asked anything. That is a getter over prepared data,
 * not a calculation (nothing behind it calls CartComputation), so reading it
 * cannot re-enter this quote. What stays absent is the GRAND total, because it
 * includes the carriage this request exists to decide, and asking for it from
 * inside a shipping quote is a question that answers itself forever.
 *
 * @since  1.10.0
 */
final class ShippingCostRequest
{
    /**
     * @param Method $method The carrier being asked, entire.
     * @param Cart $cart The cart being quoted, entire but for the lines flagged
     *                   unavailable, which nothing ships: what there is to carry is
     *                   `$cart->lines()`, and where it is going is
     *                   the shared method-destination resolver, off `$cart->delivery()`. Neither is
     *                   copied onto this request: a pre-resolved list could be read
     *                   at exactly one moment by exactly one hook, so anything else
     *                   needing the destination had to be handed it or resolve it a
     *                   second way.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly Method $method,
        public readonly Cart $cart,
    ) {
    }
}
