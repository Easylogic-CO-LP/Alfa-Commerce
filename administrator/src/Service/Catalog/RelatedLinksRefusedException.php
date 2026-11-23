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

/**
 * A product save refused for its related links ({@see RelatedItems::refusals()}), nothing saved: the model's error,
 * whose message is every refusal's sentence (what the item screen shows) and whose refusals point at their rows
 * (what the REST API answers, 422 with related[n]).
 *
 * @since  2.3.0
 */
final class RelatedLinksRefusedException extends RuntimeException
{
    /**
     * @param list<Refusal> $refusals The refusals, each pointing at its row.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct(implode(' ', array_map(static fn (Refusal $refusal): string => $refusal->sentence, $refusals)), Refusal::INVALID);
    }

    /**
     * The refusals as an API answer reads them: each led by its row, related[n].
     *
     * @since  2.3.0
     */
    public function placed(): string
    {
        return implode(' ', array_map(static fn (Refusal $refusal): string => $refusal->placed(), $this->refusals));
    }
}
