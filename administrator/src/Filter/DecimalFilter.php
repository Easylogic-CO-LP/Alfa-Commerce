<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Filter;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use InvalidArgumentException;

/**
 * A typed amount, as the exact decimal text its DECIMAL(24,8) column is handed.
 *
 * Named on a form field as a callback filter
 * (filter="Alfa\Component\Alfa\Administrator\Filter\DecimalFilter::text").
 * Joomla's "float" filter turns the typed text into a PHP float, and a database
 * driver prints a float with fourteen significant digits, so 1,000,000.00000001
 * would be stored as 1,000,000. This keeps the figure as its digits.
 *
 * A filter answers a value and refuses nothing: a value that is not a number,
 * or is finer or larger than the column holds, is passed on untouched, and the
 * field's validate="decimal" ({@see \Alfa\Component\Alfa\Administrator\Rule\DecimalRule})
 * refuses it by name, so the column never rounds or cuts it. A number sent as
 * a JSON number arrives as a float and is read at the digits Money reads one at.
 *
 * @since  1.14.0
 */
final class DecimalFilter
{
    /**
     * The filter.
     *
     * @param mixed $value The posted value.
     *
     * @return mixed The column's text, or the value untouched.
     *
     * @since  1.14.0
     */
    public static function text(mixed $value): mixed
    {
        $typed = \is_string($value) ? trim($value) : $value;

        if (!\is_int($typed) && !\is_float($typed) && !(\is_string($typed) && is_numeric($typed))) {
            return $value;
        }

        try {
            return StoredDecimal::of(ExactAmount::ofNumber($typed));
        } catch (InvalidArgumentException) {
            return $value;
        }
    }
}
