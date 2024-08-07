<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Helper;
// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Object\CMSObject;

/**
 * Alfa helper.
 *
 * @since  1.0.1
 */
class AlfaHelper
{
    /**
     * Gets the files attached to an item
     *
     * @param int $pk The item's id
     *
     * @param string $table The table's name
     *
     * @param string $field The field's name
     *
     * @return  array  The files
     */
    public static function getFiles($pk, $table, $field)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query
            ->select($field)
            ->from($table)
            ->where('id = ' . (int)$pk);

        $db->setQuery($query);

        return explode(',', $db->loadResult());
    }

    /**
     * Gets a list of the actions that can be performed.
     *
     * @return  CMSObject
     *
     * @since   1.0.1
     */
    public static function getActions()
    {
        $user = Factory::getApplication()->getIdentity();
        $result = new CMSObject;

        $assetName = 'com_alfa';

        $actions = array(
            'core.admin', 'core.manage', 'core.create', 'core.edit', 'core.edit.own', 'core.edit.state', 'core.delete'
        );

        foreach ($actions as $action) {
            $result->set($action, $user->authorise($action, $assetName));
        }

        return $result;
    }

    /**
     * Build a nested array of anything with depth level ( should contain id and parent_id to work ).
     *
     * @param array $items E.g The list of categories.
     * @param int $parentId The parent ID to start building from. ( Begins with zero so we dont set it )
     * @param int $depth The current depth level ( Auto setted while recursing )
     * @return array The nested array of items with depth level ( e.g the fixed categories with children and depth attached)
     */
    public static function buildNestedArray($items, $parentId = 0, $depth = 0)
    {
        $tree = array();
        foreach ($items as $item) {
            if ($item->parent_id == $parentId) {
                $id = $item->id;
                $item->depth = $depth; // Assign the current depth level
                $item->children = self::buildNestedArray($items, $id, $depth + 1);
                $tree[$id] = $item;
            }
        }
        return $tree;
    }

    public static function iterateNestedArray($tree, $callback, $fullPath = false, $parentNames = '')
    {
        foreach ($tree as $node) {

            // Build the full path or hierarchical representation of category names based on the format
            if ($fullPath) {
                $currentPath = empty($parentNames) ? $node->name : $parentNames . ' / ' . $node->name;
            } else {
                $currentPath = str_repeat('- ', $node->depth) . $node->name;
            }

            // Apply the callback function to the current node with the full path or hierarchical representation
            $callback($node, $currentPath);

            // If the node has children, recursively iterate through them
            if (!empty($node->children)) {
                self::iterateNestedArray($node->children, $callback, $fullPath, $currentPath);
            }
        }
    }
    public static function sort_items($object1, $object2, $property, $order = 'asc', $isNumeric = true)
    {
        if ($isNumeric) {
            $result = ($object1->$property == $object2->$property) ? 0 : (($object1->$property > $object2->$property) ? 1 : -1);
        } else {
            $result = strcasecmp($object1->$property, $object2->$property);
        }
        return ($order == 'asc') ? $result : -$result;
    }

    public static function search($array, $key, $value) {
        $results = array();

        // if it is an array
        if (is_array($array)) {

            // if array has required key and value
            // matched store result
            if (isset($array[$key]) && $array[$key] == $value) {
                $results[] = $array;
            }

            // Iterate for each element in array
            foreach ($array as $subarray) {

                // recur through each element and append result
                $results = array_merge($results,
                    self::search($subarray, $key, $value));
            }
        }

        return $results;
    }

}

