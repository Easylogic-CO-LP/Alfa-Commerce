<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Resolves the distinct (field, value) pairs an item carries across all sources.
 *
 * This is the ONLY place that knows where item-field values come from. Today
 * that is three association tables (combinations, options, informational)
 * unioned and joined to the value pool to recover each value's owning field
 * (`#__alfa_item_field_values.item_field_id`). A future source (e.g. bundles)
 * is added here alone; the filter-index projection, facets, and the catalog
 * query never change.
 *
 * The collector only *materializes* relationships. It applies no business
 * filtering beyond `state = 1` on the value itself; which values an item
 * matches is enforced once, by the catalog query's EXISTS, never here.
 *
 * @since  1.3.0
 */
class ItemFieldValueCollector
{
    /** @var DatabaseInterface */
    private $db;

    /**
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     *
     * @since  1.3.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Return the unique (field, value) references for one item.
     *
     * Combination values are reached through `#__alfa_item_combinations` (which
     * carries the item id); option and informational values store the item id
     * directly. The UNION de-duplicates a value shared by several sources, and
     * `DISTINCT` collapses the final (field, value) pairs.
     *
     * @param int $itemId The item primary key.
     *
     * @return ItemFieldValueReference[]
     * @since  1.3.0
     */
    public function collect(int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $db = $this->db;
        $id = (int) $itemId;

        $optionValues = $db->quoteName('#__alfa_item_option_values');
        $infoValues = $db->quoteName('#__alfa_item_info_values');
        $combinationValues = $db->quoteName('#__alfa_item_combination_values');
        $combinations = $db->quoteName('#__alfa_item_combinations');
        $valuePool = $db->quoteName('#__alfa_item_field_values');

        // field_id is recovered from the value's owning field; the 3 association
        // tables only store value_id (combinations indirectly, via their item).
        $sql = "SELECT DISTINCT v.item_field_id AS field_id, v.id AS value_id
                FROM (
                    SELECT value_id FROM {$optionValues} WHERE item_id = {$id}
                    UNION
                    SELECT value_id FROM {$infoValues} WHERE item_id = {$id}
                    UNION
                    SELECT cv.value_id
                    FROM {$combinationValues} AS cv
                    INNER JOIN {$combinations} AS c ON c.id = cv.combination_id
                    WHERE c.item_id = {$id}
                ) AS src
                INNER JOIN {$valuePool} AS v ON v.id = src.value_id
                WHERE v.state = 1";

        $rows = $db->setQuery($sql)->loadObjectList() ?: [];

        $references = [];

        foreach ($rows as $row) {
            $references[] = new ItemFieldValueReference(
                fieldId: (int) $row->field_id,
                valueId: (int) $row->value_id,
            );
        }

        return $references;
    }
}
