<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\ValuePool;

\defined('_JEXEC') or die;

/**
 * Source of truth for an operand's candidate values. It knows data domains, not UI
 * and not the engine: given an operand (and a little context, e.g. the resolved
 * field row), it returns the {@see ValuePool} the builder hydrates from. Providers
 * are registered per operand-KIND (the field value pool, a boolean facet, a free
 * number/text), never per individual field.
 *
 * Today the builder calls this once per render and embeds the result; the same
 * interface backs a lazy HTTP endpoint later, if operand/pool size ever demands it,
 * without the builder changing.
 *
 * @since  1.3.0
 */
interface ValuePoolProvider
{
    /**
     * The candidate-value domain for one operand.
     *
     * @param string $operand A dot-address such as `size.value` or `configurator.ready`.
     * @param array $context Optional resolution hints (e.g. ['field' => $fieldRow]).
     *
     *
     * @since  1.3.0
     */
    public function get(string $operand, array $context = []): ValuePool;
}
