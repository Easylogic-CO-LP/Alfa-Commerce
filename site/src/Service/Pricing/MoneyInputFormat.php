<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * How a written amount is spelled, and nothing else.
 *
 * A currency says what monetary value can exist: its scale, its rounding, its
 * identity. This says only how an external string writes one, so an import can
 * read "1,234.56" into a currency the merchant displays as "1.234,56" without
 * the value, the scale or the currency moving. It deliberately carries no
 * scale and no rounding mode: there is no way to express one here, so no
 * caller can smuggle one past the Currency.
 *
 * @since  1.10.0
 */
final class MoneyInputFormat
{
    /**
     * @param string $decimalSeparator The one character that introduces the fraction.
     * @param string $thousandSeparator The one character that groups the integer part, or '' for a syntax that does not group.
     *
     * @since  1.10.0
     */
    private function __construct(
        private readonly string $decimalSeparator,
        private readonly string $thousandSeparator,
    ) {
    }

    /**
     * Declare an input syntax explicitly: the integration/import case.
     *
     * @param string $decimalSeparator The character the feed uses for decimals.
     * @param string $thousandSeparator The character the feed groups with, or '' when it does not group.
     *
     * @return self The syntax.
     *
     * @throws InvalidArgumentException When the two characters cannot describe a syntax.
     *
     * @since  1.10.0
     */
    public static function of(string $decimalSeparator, string $thousandSeparator = ''): self
    {
        self::assertSeparator($decimalSeparator, 'decimal');

        if ($thousandSeparator !== '') {
            self::assertSeparator($thousandSeparator, 'thousand');
        }

        if ($thousandSeparator === $decimalSeparator) {
            throw new InvalidArgumentException(
                'A decimal separator and a thousand separator cannot be the same character.',
            );
        }

        return new self(
            decimalSeparator: $decimalSeparator,
            thousandSeparator: $thousandSeparator,
        );
    }

    /**
     * The default syntax: the one the merchant configured for this currency.
     *
     * A currency whose separators cannot describe a syntax is a configuration
     * error, not an invitation to guess: CurrencyTable::check() validates only
     * the code and the scale, so the refusal has to happen here.
     *
     * @param Currency $currency The currency whose configuration is the syntax.
     *
     * @return self The syntax.
     *
     * @throws InvalidArgumentException When this currency's separators cannot describe one.
     *
     * @since  1.10.0
     */
    public static function fromCurrency(Currency $currency): self
    {
        try {
            return self::of(
                decimalSeparator: $currency->getDecimalSeparator(),
                thousandSeparator: $currency->getThousandSeparator(),
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                sprintf('Currency %s has no usable input syntax: %s', $currency->getCode(), $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * The character that introduces the fraction.
     *
     * @return string The decimal separator.
     *
     * @since  1.10.0
     */
    public function getDecimalSeparator(): string
    {
        return $this->decimalSeparator;
    }

    /**
     * The character that groups the integer part, or '' when the syntax does not group.
     *
     * @return string The grouping separator.
     *
     * @since  1.10.0
     */
    public function getThousandSeparator(): string
    {
        return $this->thousandSeparator;
    }

    /**
     * One character, and not one that already means something in a number.
     *
     * @param string $character The candidate.
     * @param string $role 'decimal' or 'thousand', for the message.
     *
     *
     * @throws InvalidArgumentException When it cannot serve as a separator.
     *
     * @since  1.10.0
     */
    private static function assertSeparator(string $character, string $role): void
    {
        if (mb_strlen($character) !== 1 || preg_match('/[\p{Nd}\-]/u', $character) === 1) {
            throw new InvalidArgumentException(sprintf(
                'A %s separator must be one character that is neither a digit nor a minus sign, not %s.',
                $role,
                var_export($character, true),
            ));
        }
    }
}
