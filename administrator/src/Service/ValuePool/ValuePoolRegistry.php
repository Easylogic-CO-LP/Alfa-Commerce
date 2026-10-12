<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\ValuePool;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Resolves the right {@see ValuePoolProvider} per operand KIND (not per field):
 *
 *   - `<field>.value`  -> the field's value pool (enum). A pool-less field (text /
 *      number) comes back empty, which the builder renders as a free input.
 *   - `<field>.<facet>` -> a computed boolean facet (e.g. `configurator.ready`).
 *
 * One render-time entry point; no per-field wiring, no HTTP. The provider seam means
 * a lazy endpoint can replace the embedded hydration later without the builder
 * changing.
 *
 * @since  1.3.0
 */
final class ValuePoolRegistry
{
    /** The default enumerable provider (a field's value pool). */
    private FieldValuePoolProvider $fieldProvider;

    /**
     * @param FieldValuePoolProvider|null $fieldProvider Injected for tests; defaults otherwise.
     *
     * @since  1.3.0
     */
    public function __construct(?FieldValuePoolProvider $fieldProvider = null)
    {
        $this->fieldProvider = $fieldProvider ?? new FieldValuePoolProvider();
    }

    /**
     * The candidate-value pool for one operand.
     *
     * @param string $operand A dot-address (`size.value`, `configurator.ready`).
     * @param array $context ['field' => $row] / ['fieldId' => int].
     *
     *
     * @since  1.3.0
     */
    public function get(string $operand, array $context = []): ValuePool
    {
        $facet = explode('.', $operand, 2)[1] ?? 'value';

        if ($facet !== 'value') {
            return new ValuePool(
                operand: $operand,
                items: [
                    ['id' => 1, 'label' => Text::_('JYES')],
                    ['id' => 0, 'label' => Text::_('JNO')],
                ],
                domain: 'bool',
            );
        }

        return $this->fieldProvider->get(operand: $operand, context: $context);
    }

    /**
     * Batch the enum `<name>.value` pools for many fields in two queries: the path
     * the builder uses to hydrate every candidate operand at once (no N+1).
     *
     * @param array<int, array{id:int,name:string}> $fields Candidate fields (id + machine name).
     *
     * @return array<string, array> operand => ValuePool::toArray().
     *
     * @since  1.3.0
     */
    public function getMany(array $fields): array
    {
        $byField = $this->fieldProvider->getManyByField(array_column($fields, 'id'));

        $out = [];

        foreach ($fields as $field) {
            $operand = $field['name'] . '.value';

            $out[$operand] = (new ValuePool(
                operand: $operand,
                items: $byField[(int) $field['id']] ?? [],
                domain: 'enum',
            ))->toArray();
        }

        return $out;
    }
}
