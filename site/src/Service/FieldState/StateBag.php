<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\FieldState;

defined('_JEXEC') or die;

/**
 * The single source of truth a page render builds once and every consumer reads.
 * It is a flat map of dot-addresses to STABLE PRIMITIVES:
 *
 *   size.value          = 12       (input  - value-pool id)
 *   color.value         = [21, 22] (input  - ids; arrays allowed for multi-select)
 *   configurator.ready  = true     (input  - computed facet)
 *   variant.id          = 145      (derived - written by the Variant Resolver)
 *   variant.price       = 2000     (derived - minor units, only if a rule reads it)
 *
 * Two layers, one bag: fields write INPUT state; the Variant Resolver reads input
 * and writes DERIVED state back here. Consumers (ShowOn, Pricing, Validation) only
 * ever read: the bag carries no labels, no formatted/locale strings, so a consumer
 * cannot accidentally branch on presentation. id->label resolution is a builder /
 * inspector concern, deliberately kept OUT of the bag.
 *
 * The bag is family-agnostic: a form context fills it from that form's fields, a
 * product context fills it from the item's fields plus the resolver's variant facts.
 * Same class, different content.
 *
 * @since  2.0.0
 */
final class StateBag
{
    /**
     * Flat map of `field.facet` => primitive (or array of primitives).
     *
     * @var array<string, scalar|array>
     */
    private array $state = [];

    /**
     * Namespace a producer's slice under a field's machine name and merge it in.
     * `put('size', ['value' => 12])` registers `size.value = 12`.
     *
     * @param string $field The field's stable machine name (the address prefix).
     * @param array $slice Facet => primitive, as returned by {@see ProvidesState::getState()}.
     *
     *
     * @since  2.0.0
     */
    public function put(string $field, array $slice): void
    {
        foreach ($slice as $facet => $value) {
            $this->state[$field . '.' . $facet] = $value;
        }
    }

    /**
     * Convenience for the common case: pull a producer's slice and register it.
     *
     * @param string $field The field's stable machine name.
     * @param ProvidesState $producer The state producer.
     *
     *
     * @since  2.0.0
     */
    public function putFrom(string $field, ProvidesState $producer): void
    {
        $this->put(field: $field, slice: $producer->getState());
    }

    /**
     * Read one address. Returns $default when the address was never set (e.g. a
     * field that produced no value, treated as absent, NOT as empty string).
     *
     * @param string $address A dot-address such as `size.value` or `variant.price`.
     * @param mixed $default Returned when the address is absent.
     *
     * @return mixed The stored primitive (or array), or $default.
     *
     * @since  2.0.0
     */
    public function get(string $address, mixed $default = null): mixed
    {
        return $this->state[$address] ?? $default;
    }

    /**
     * Whether an address has been set at all.
     *
     * @param string $address A dot-address.
     *
     *
     * @since  2.0.0
     */
    public function has(string $address): bool
    {
        return \array_key_exists($address, $this->state);
    }

    /**
     * A read-only snapshot of the whole bag, for serialising to the frontend (so
     * the JS evaluator starts from the same state the PHP evaluator saw) and for
     * inspection. Consumers receive a copy; they cannot mutate the bag.
     *
     * @return array<string, scalar|array>
     *
     * @since  2.0.0
     */
    public function all(): array
    {
        return $this->state;
    }
}
