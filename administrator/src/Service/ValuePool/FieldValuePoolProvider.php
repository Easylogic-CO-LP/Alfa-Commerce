<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\ValuePool;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Throwable;

\defined('_JEXEC') or die;

/**
 * The default, enumerable provider: an item field's `value` domain is its value
 * pool. Loads the field's published values (in pool order) and overlays the
 * active-language labels from the per-language aux table, else the default
 * language's: the same shape
 * {@see \Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper} reads at runtime, so the
 * builder offers exactly the ids the engine will compare. Labels are display-only;
 * a value named in neither falls back to '#id'.
 *
 * @since  2.0.0
 */
final class FieldValuePoolProvider implements ValuePoolProvider
{
    /**
     * The enumerable pool for `<field>.value`.
     *
     * @param string $operand The dot-address (its prefix is the field machine name).
     * @param array $context Resolution hints; ['field' => $row] or ['fieldId' => int].
     *
     * @return ValuePool An 'enum' pool (empty when the field or its values are absent).
     *
     * @since  2.0.0
     */
    public function get(string $operand, array $context = []): ValuePool
    {
        $fieldId = (int) ($context['fieldId'] ?? ($context['field']->id ?? 0));

        if ($fieldId <= 0) {
            return new ValuePool(operand: $operand, items: [], domain: 'enum');
        }

        $byField = $this->getManyByField([$fieldId]);

        return new ValuePool(operand: $operand, items: $byField[$fieldId] ?? [], domain: 'enum');
    }

    /**
     * Batch-load value pools for several fields in TWO queries total (values, then
     * labels), grouped by field id; avoids the N+1 a per-field call would cause
     * when the builder hydrates every candidate operand at once.
     *
     * @param int[] $fieldIds Item-field ids.
     *
     * @return array<int, array<int, array{id:int,label:string}>> field id => ordered [{id,label}].
     *
     * @since  2.0.0
     */
    public function getManyByField(array $fieldIds): array
    {
        $fieldIds = array_values(array_unique(array_map('intval', array_filter($fieldIds))));

        if (!$fieldIds) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->qn('id'), $db->qn('item_field_id')])
                ->from($db->qn('#__alfa_item_field_values'))
                ->whereIn($db->qn('item_field_id'), $fieldIds)
                ->where($db->qn('state') . ' = 1')
                ->order([$db->qn('item_field_id') . ' ASC', $db->qn('ordering') . ' ASC']),
        );

        $byField = [];
        $fieldOf = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $vid = (int) $row->id;
            $fid = (int) $row->item_field_id;

            $byField[$fid][$vid] = ['id' => $vid, 'label' => '#' . $vid];
            $fieldOf[$vid] = $fid;
        }

        if ($fieldOf) {
            try {
                // One query: each value's name in the current language, else the default's.
                $names = MultilingualHelper::getRecordsByIds(
                    db:                $db,
                    ids:               array_keys($fieldOf),
                    table:             '#__alfa_item_field_values',
                    langTableBase:     '#__alfa_item_field_values',
                    langPrimaryColumn: 'id_item_field_value',
                );

                foreach ($names as $vid => $row) {
                    $vid = (int) $vid;
                    $fid = $fieldOf[$vid] ?? 0;
                    $name = (string) ($row['name'] ?? '');

                    if ($name !== '' && isset($byField[$fid][$vid])) {
                        $byField[$fid][$vid]['label'] = $name;
                    }
                }
            } catch (Throwable $e) {
                // Per-language aux table pending resync; keep the '#id' fallbacks.
            }
        }

        // Drop the vid keys, keep pool order.
        return array_map('array_values', $byField);
    }
}
