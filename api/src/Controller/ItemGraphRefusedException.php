<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\MVC\Controller\Exception\Save;

/**
 * A product's write with its graph refused, nothing saved: the refusals the answer names, each with its JSON pointer.
 * A refused save, so a caller that does not answer the refusals one by one answers it as Joomla answers a refused save.
 *
 * @since  2.3.0
 */
final class ItemGraphRefusedException extends Save
{
    /**
     * @param list<Refusal> $refusals The refusals.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct(implode(' ', array_map(static fn (Refusal $refusal): string => $refusal->placed(), $refusals)), $refusals[0]->status ?? Refusal::INVALID);
    }
}
