<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Refusal;
use RuntimeException;
use Throwable;

/**
 * A product's save met another write of the same rows at that moment (a deadlock, or a lock wait that ran out,
 * {@see \Alfa\Component\Alfa\Administrator\Helper\MysqlError::busy()}): the save's transaction rolled back whole, and its
 * caller says the refusal's sentence. The same save sent again a few seconds later finds the rows free; the REST API
 * answers it 409 with Retry-After.
 *
 * @since  2.3.0
 */
final class ItemSaveBusyException extends RuntimeException
{
    /**
     * The seconds the REST API's Retry-After asks a client to wait before it sends the save again.
     *
     * @since  2.3.0
     */
    public const RETRY_AFTER = 2;

    /**
     * @param Refusal $refusal The refusal, busy, 409.
     * @param Throwable|null $previous The database's failure.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly Refusal $refusal, ?Throwable $previous = null)
    {
        parent::__construct($refusal->sentence, $refusal->status, $previous);
    }
}
