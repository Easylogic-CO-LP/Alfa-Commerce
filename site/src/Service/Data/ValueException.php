<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Data;

use RuntimeException;

defined('_JEXEC') or die;

/**
 * A stored value is not what the caller asked for.
 *
 * ALWAYS LOUD, NEVER A PLAUSIBLE ZERO. Both failures this covers used to be
 * swallowed: a `plugin_data` column holding truncated JSON decoded to null and
 * came back as `[]`, so a carrier read "this parcel was never booked" from a row
 * that recorded a booking; and `(int) $data['installments']` turned the string
 * 'six' into 1 instalment and 6.5 into 6. A value that cannot be converted is a
 * fault to fix, not a number to charge somebody.
 *
 * SHOPPER-FACING CALLERS CATCH THIS. At a checkout boundary a malformed stored
 * value is a validation message ("we could not read the postcode on this
 * address"), not a stack trace; the dispatchers and the placement path turn it
 * into one. Deeper in, where only an operator is watching, it is meant to be
 * seen.
 *
 * @since  2.0.0
 */
class ValueException extends RuntimeException
{
}
