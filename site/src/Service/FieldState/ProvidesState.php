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
 * A field (and anything else that contributes facts to a page) is, for the rest
 * of the system, just a producer of state. It is never asked WHAT KIND it is
 * (option, variant, configurator, upload, text) nor which FAMILY it belongs to
 * (form fields, item fields): every consumer (ShowOn, Pricing, Validation, the
 * Variant Resolver) reads one shared {@see StateBag}, and producers compose only
 * through that bag; no subsystem calls another.
 *
 * The returned slice holds STABLE PRIMITIVES only: value-pool ids for enumerable
 * choices, plain bool/int/string for computed facts; never labels, translated, or
 * locale-formatted strings. A simple field returns a scalar value; a complex field
 * returns several named facets:
 *
 *   ['value' => 12]                         // a choice: the value-pool id
 *   ['ready' => true, 'areas_filled' => 3]  // a configurator: computed facets
 *   ['value' => [21, 22]]                   // a multi-select: an array of ids
 *
 * The slice is keyed WITHOUT the field prefix; the bag namespaces it under the
 * field's machine name (so a rule addresses `size.value`, `configurator.ready`).
 *
 * @since  2.0.0
 */
interface ProvidesState
{
    /**
     * The producer's current state slice: stable primitives keyed by facet.
     *
     * @return array<string, scalar|array> Facet name => primitive value (or array of primitives).
     *
     * @since  2.0.0
     */
    public function getState(): array;
}
