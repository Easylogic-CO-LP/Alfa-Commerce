<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Orders;

\defined('_JEXEC') or die;

use RuntimeException;
use Throwable;

/**
 * A placement refused on purpose by a listener of onOrderPlaceValidate, thrown with the sentence the shopper is to read
 * (2.2.1): what {@see OrderPlaceValidateEvent::reject()} says, from a listener that would rather throw. The shopper reads
 * the sentence, as a rejection's; any other exception a listener throws is reported as a check that could not run, its
 * words kept in the log. The sentence is required: one that is empty once its markup is stripped has nothing for the
 * shopper to read, and the refusal is then reported as a check that could not run, as any other throw.
 *
 * @since  2.2.1
 */
final class OrderPlaceRefusedException extends RuntimeException
{
    /**
     * The most characters of the sentence the shopper is shown.
     *
     * @since  2.2.1
     */
    private const SENTENCE_LIMIT = 300;

    /**
     * @param string $message The refusal, as the shopper is to read it.
     * @param Throwable|null $previous What caused it, for the log.
     *
     * @since  2.2.1
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The refusal in a failure's chain, when the failure is one or wraps one.
     *
     * @param Throwable $failure What a listener threw.
     *
     * @since  2.2.1
     */
    public static function in(Throwable $failure): ?self
    {
        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e;
            }
        }

        return null;
    }

    /**
     * The sentence as the shopper is shown it: its words only, no markup, on one line, cut to a bounded length.
     *
     * @since  2.2.1
     */
    public function sentence(): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->getMessage())));

        return rtrim(mb_substr($text, 0, self::SENTENCE_LIMIT));
    }
}
