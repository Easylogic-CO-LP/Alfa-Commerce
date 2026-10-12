<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;

/**
 * A stored row's figures as a resource publishes them: exact decimal strings by
 * the one rule ({@see PublishedDecimal}), never the column's padded text and never
 * a float.
 *
 * A stored amount of a currency keeps its own digits, never fewer than the
 * currency's scale ("12.40", "0.125"); a rate or a percentage keeps its own
 * digits with no trailing zero ("24", "10"). A figure that is not a number (null
 * included) is left as it is.
 *
 * @since  1.14.0
 */
trait PublishesStoredFigures
{
    /**
     * A stored amount of a currency.
     *
     * @param mixed $value The column's value.
     * @param int $scale The currency's scale.
     *
     * @return mixed The published text, or the value itself when it is not a number.
     *
     * @since  1.14.0
     */
    protected static function storedAmount(mixed $value, int $scale): mixed
    {
        return is_numeric($value) ? PublishedDecimal::unit(value: ExactAmount::ofNumber($value), scale: $scale) : $value;
    }

    /**
     * A stored rate or percentage, or a figure no currency is stated for.
     *
     * @param mixed $value The column's value.
     *
     * @return mixed The published text, or the value itself when it is not a number.
     *
     * @since  1.14.0
     */
    protected static function storedRate(mixed $value): mixed
    {
        return is_numeric($value) ? PublishedDecimal::decimal(value: ExactAmount::ofNumber($value)) : $value;
    }

    /**
     * The scale of the shop's default currency, in which a catalogue rule's amount is stated.
     *
     *
     * @since  1.14.0
     */
    protected static function defaultScale(): int
    {
        return Currency::getDefault()->getDecimalPlaces();
    }
}
