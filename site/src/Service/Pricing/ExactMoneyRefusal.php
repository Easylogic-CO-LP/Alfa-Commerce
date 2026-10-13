<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\CMS\Language\Text;
use Throwable;

/**
 * Why a typed amount was not read: {@see ExactMoney::of()}'s refusal.
 *
 * It names the figure as it was typed, the currency and the currency's
 * decimal places. Its message is plain English for a log or an API answer;
 * words() is the sentence a staff screen shows, in the administrator's
 * language.
 *
 * @since  2.0.0
 */
final class ExactMoneyRefusal extends InvalidArgumentException
{
    /**
     * @param string $reason ExactMoney::NOT_A_NUMBER, ::NOT_EXACT, ::FINER, ::GROUPED or ::LARGER.
     * @param string $typed The figure as it was typed, or a float's decimal text.
     * @param Currency $currency The currency it was read in.
     * @param Throwable|null $previous What refused it, when not this reader.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly string $reason,
        private readonly string $typed,
        private readonly Currency $currency,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            match ($reason) {
                ExactMoney::FINER => sprintf("'%s' has more decimal places than %s allows (%d).", $typed, $currency->getCode(), $currency->getDecimalPlaces()),
                ExactMoney::LARGER => sprintf("'%s' is larger than an amount of %s holds.", $typed, $currency->getCode()),
                ExactMoney::GROUPED => sprintf("'%s' is written with more decimal places than %s allows (%d).", $typed, $currency->getCode(), $currency->getDecimalPlaces()),
                ExactMoney::NOT_EXACT => 'a number that does not carry its digits exactly (the nearest float prints as ' . $typed . '): send the amount as a string.',
                default => sprintf("'%s' is not an amount.", $typed),
            },
            0,
            $previous,
        );
    }

    /**
     * Why: ExactMoney::NOT_A_NUMBER, ::NOT_EXACT, ::FINER, ::GROUPED or ::LARGER.
     *
     *
     * @since  2.0.0
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The figure as it was typed.
     *
     *
     * @since  2.0.0
     */
    public function typed(): string
    {
        return $this->typed;
    }

    /**
     * The currency it was read in, at the record's scale.
     *
     *
     * @since  2.0.0
     */
    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * The sentence a staff screen shows: the field, the figure, the currency and its decimals.
     *
     * Plain text: the screen escapes it where it is queued.
     *
     * @param string $field The field's label, translated.
     *
     *
     * @since  2.0.0
     */
    public function words(string $field): string
    {
        return Text::sprintf(
            match ($this->reason) {
                ExactMoney::FINER => 'COM_ALFA_MONEY_INPUT_TOO_FINE',
                ExactMoney::GROUPED => 'COM_ALFA_MONEY_INPUT_GROUPED',
                ExactMoney::LARGER => 'COM_ALFA_MONEY_INPUT_TOO_LARGE',
                default => 'COM_ALFA_MONEY_INPUT_NOT_A_NUMBER',
            },
            $field,
            $this->typed,
            $this->currency->getCode(),
            $this->currency->getDecimalPlaces(),
        );
    }
}
