<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\ValuePool;

use Joomla\CMS\Factory;
use Throwable;

\defined('_JEXEC') or die;

/**
 * The default, enumerable provider: an item field's `value` domain is its value
 * pool. Loads the field's published values (in pool order) and overlays the
 * active-language labels from the per-language aux table: the same shape
 * {@see \Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper} reads at runtime, so the
 * builder offers exactly the ids the engine will compare. Labels are display-only;
 * a value pending translation falls back to '#id'.
 *
 * @since  1.3.0
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
     * @since  1.3.0
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
     * @since  1.3.0
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
            $tag = strtolower(str_replace('-', '_', Factory::getApplication()->getLanguage()->getTag()));

            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select([$db->qn('id_item_field_value'), $db->qn('name')])
                        ->from($db->qn('#__alfa_item_field_values_' . $tag))
                        ->whereIn($db->qn('id_item_field_value'), array_keys($fieldOf)),
                );

                foreach ($db->loadObjectList() ?: [] as $row) {
                    $vid = (int) $row->id_item_field_value;
                    $fid = $fieldOf[$vid] ?? 0;

                    if ((string) $row->name !== '' && isset($byField[$fid][$vid])) {
                        $byField[$fid][$vid]['label'] = (string) $row->name;
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
