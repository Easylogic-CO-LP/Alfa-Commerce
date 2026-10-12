<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Throwable;

/**
 * The parameter seam of every inventory entry point. Commands and
 * availability questions take ONE context object instead of a growing
 * list of scalars, so richer dimensions (channel, owner, customer
 * grouping, valuation date ...) can arrive later without touching a
 * single signature.
 *
 * @since  1.4.0
 */
final class InventoryContext
{
    /**
     * @param int $userId Acting user (0 = system).
     * @param string $correlationId External chain thread passed in by the
     *                              caller (webhook/import/sync id); carried
     *                              onto every row the command writes.
     * @param string $note Free-text audit note for movements.
     * @param array $metadata Caller extras for event subscribers
     *                        (never read by the kernel itself).
     *
     * @since  1.4.0
     */
    public function __construct(
        public readonly int $userId = 0,
        public readonly string $correlationId = '',
        public readonly string $note = '',
        public readonly array $metadata = [],
    ) {
    }

    /**
     * The context of whoever is acting right now: the signed-in user, or the
     * system (0) when there is no application or nobody is signed in: a CLI
     * run, a scheduled task, a webhook.
     *
     *
     * @since  1.10.0
     */
    public static function ambient(): self
    {
        try {
            $userId = (int) Factory::getApplication()->getIdentity()?->id;
        } catch (Throwable) {
            $userId = 0;
        }

        return new self(userId: $userId);
    }
}
