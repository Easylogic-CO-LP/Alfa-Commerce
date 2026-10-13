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
 * The candidate-value domain of one operand, as the builder needs it: a list of
 * {id, label} pairs the admin picks from (label shown, id stored) plus the domain
 * kind that drives which value control renders. Labels are display-only and live in
 * the admin's current language; only the id is ever stored on a rule.
 *
 * Domains: 'enum' (a value pool: select/multi), 'bool' (0/1: a computed facet),
 * 'number' / 'text' (no candidate list: a free input).
 *
 * @since  2.0.0
 */
final class ValuePool
{
    /**
     * @param string $operand The dot-address this pool serves (e.g. `size.value`).
     * @param array<int, array{id: int|string, label: string}> $items Candidate values (empty for number/text).
     * @param string $domain enum | bool | number | text.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $operand,
        public array $items,
        public string $domain,
    ) {
    }

    /**
     * The shape embedded in the builder's data-config and consumed by the JS store.
     *
     * @return array{operand: string, domain: string, items: array}
     *
     * @since  2.0.0
     */
    public function toArray(): array
    {
        return [
            'operand' => $this->operand,
            'domain' => $this->domain,
            'items' => $this->items,
        ];
    }
}
