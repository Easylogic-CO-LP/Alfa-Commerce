<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

/**
 * What enqueue() answers. A refusal that depends on the data of the moment is a value here, never an exception, so a
 * caller's own save is never rolled back by it: OTHER_DATA (a task with the same once() key waits or runs with other
 * data; $id names it) and TOO_FAR (a notBefore() more than 365 days ahead; nothing written, $id 0).
 *
 * @api ANSWERS: the row written or joined, or why nothing was written.
 *
 * @since  2.3.0
 */
final class Enqueued
{
    /**
     * Refused: a task with the same once() key waits or runs with other data.
     *
     * @since  2.3.0
     */
    public const OTHER_DATA = 'other_data';

    /**
     * Refused: the moment given to notBefore() is more than 365 days ahead.
     *
     * @since  2.3.0
     */
    public const TOO_FAR = 'too_far';

    /**
     * @param int $id The row written or joined; the waiting one when refused for its data; 0 when none.
     * @param bool $joined True when a waiting or running one was joined.
     * @param bool $refused True when nothing was written (see $reason).
     * @param string $reason '' or self::OTHER_DATA or self::TOO_FAR.
     *
     * @internal Made by core's enqueue only.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly int $id,
        public readonly bool $joined = false,
        public readonly bool $refused = false,
        public readonly string $reason = '',
    ) {
    }
}
