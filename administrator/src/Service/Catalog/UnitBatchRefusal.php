<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * One unit of a batch refused while it was written ({@see UnitBatch}): its transaction is rolled back, and the unit is
 * answered with the status and the sentence; the other units go on.
 *
 * @since  2.3.0
 */
final class UnitBatchRefusal extends RuntimeException
{
    /**
     * @param int $status The HTTP status the refusal would have alone.
     * @param string $title Why, and what to send.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly int $status, string $title)
    {
        parent::__construct($title);
    }
}
