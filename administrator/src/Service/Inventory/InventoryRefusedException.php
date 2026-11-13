<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use RuntimeException;
use Throwable;

/**
 * A stock movement refused on purpose by a listener of a stock before-event (onInventoryBeforeReserve,
 * onInventoryBeforeCommit, onInventoryBeforeRelease): a warehouse that says no.
 *
 * The listener throws it with the sentence staff are to read. A movement owed after a status change or a document
 * (InventoryEffectOutbox) is then refused, not failed: it is not tried again, its order's or document's notice names
 * the refusal, and staff decide: an order's by changing its status again, a document's by running its task in
 * Tools > Tasks again, once the listener allows the movement (2.2.1). Any other exception a listener throws is a
 * failure, tried again with the task engine's waits.
 *
 * @since  2.2.1
 */
final class InventoryRefusedException extends RuntimeException
{
    /**
     * The most characters of a listener's sentence a notice or a log line carries.
     *
     * @since  2.2.1
     */
    private const SENTENCE_LIMIT = 300;

    /**
     * @param string $message The refusal, as staff are to read it.
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
     * @param Throwable $failure What a stock command threw.
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
     * The listener's sentence as a notice may carry it: its words only, no markup, on one line, cut to a bounded
     * length.
     *
     * @since  2.2.1
     */
    public function sentence(): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->getMessage())));

        return rtrim(mb_substr($text, 0, self::SENTENCE_LIMIT));
    }
}
