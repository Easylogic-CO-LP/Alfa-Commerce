<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Api;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Refusal;
use RuntimeException;

/**
 * An Idempotency-Key that cannot be claimed ({@see IdempotencyKeys::claim()}): used for another request (key_reused,
 * 422), or held by a request still running (key_busy, 409). The write's transaction rolls back and nothing is applied.
 *
 * @since  2.3.0
 */
final class IdempotencyRefusedException extends RuntimeException
{
    /**
     * @param Refusal $refusal The refusal.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly Refusal $refusal)
    {
        parent::__construct($refusal->sentence, $refusal->status);
    }
}
