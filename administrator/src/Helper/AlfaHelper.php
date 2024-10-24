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
     * Build a nested array of anything with depth level ( should contain id and parent_id to work ).
     *
     * @param array $items E.g The list of categories.
     * @param int $parentId The parent ID to start building from. ( Begins with zero so we dont set it )
     * @param int $depth The current depth level ( Auto setted while recursing )
     * @return array The nested array of items with depth level ( e.g the fixed categories with children and depth attached)
     */
    public static function buildNestedArray($items, $childrenField = 'children', $parentId = 0, $depth = 0)
    {
        $tree = array();
        foreach ($items as $item) {
            if ($item->parent_id == $parentId) {
                $id = $item->id;
                $item->depth = $depth; // Assign the current depth level
                $item->{$childrenField} = self::buildNestedArray($items, $id, $depth + 1);
                $tree[$id] = $item;
            }
        }
        return $tree;
    }

    public static function sort_items($object1, $object2, $property, $order = 'asc')
    {
        if (is_numeric($object1->$property) && is_numeric($object2->$property)) {
            $result = ($object1->$property == $object2->$property) ? 0 : (($object1->$property > $object2->$property) ? 1 : -1);
        } else {
            $result = strcasecmp($object1->$property, $object2->$property);
        }
        return (strtolower($order) == 'asc') ? $result : -$result;
    }

    public static function sort_nested_items(&$items, $property = 'name', $order = 'asc', $childrenField = 'children')
    {
        // Sort the current level of items
        usort($items, function ($option1, $option2) use ($property, $order) {
            return self::sort_items($option1, $option2, $property, $order);
        });

        // Recursively sort the children
        foreach ($items as &$item) {
            if (isset($item->{$childrenField}) && is_array($item->{$childrenField})) {
                self::sort_nested_items($item->{$childrenField}, $property, $order);
            }
        }
    }


    public static function flatten_nested_items($items, $pathField = 'name', $pathSeparator = '/', $childrenField = 'children', $parentPath = '')
    {
        $flatArray = [];

        foreach ($items as $item) {
            // Clone the item to avoid modifying the original structure
            $flattenedItem = clone $item;

            // If pathField is provided, build the path
            if ($pathField) {
                // If parentPath is empty, avoid adding a leading separator
                $currentPath = $parentPath ? $parentPath . $pathSeparator . $item->{$pathField} : $item->{$pathField};

                // Add the current path to the flattened item
                $flattenedItem->path = $currentPath;
            }

            // Remove the children property to avoid recursion issues
            unset($flattenedItem->{$childrenField});

            // Add the item without children to the flat array
            $flatArray[] = $flattenedItem;

            // If the item has children, recursively flatten them, passing the current path as the parent path
            if (isset($item->{$childrenField}) && is_array($item->{$childrenField})) {
                $flatArray = array_merge($flatArray, self::flatten_nested_items($item->{$childrenField}, $pathField, $pathSeparator, $childrenField, $currentPath));
            }
        }

        return $flatArray;
    }


    public static function addHierarchyData($items, $pathField = 'name', $pathSeparator = '/', $parentPath = '', $parentId = 0, $depth = 0)
    {
        $hierarchyData = array();
        foreach ($items as $item) {
            if ($item->parent_id == $parentId) {
                $item->depth = $depth; // Assign the current depth level

                if ($pathField && property_exists($item, $pathField)) {
                    // If parentPath is empty, avoid adding a leading separator
                    $currentPath = $parentPath ? $parentPath . $pathSeparator . $item->{$pathField} : $item->{$pathField};
                    // Add the current path to the flattened item
                    $item->path = $currentPath;
                } else {
                    // Handle cases where the property does not exist or $pathField is invalid
                    $item->path = $parentPath; // or set some default value
                }

                $hierarchyData[] = $item; // Add the item to the array
                // Recursively add next items to the array
                $hierarchyData = array_merge($hierarchyData, self::addHierarchyData($items, $pathField, $pathSeparator, $currentPath, $item->id, $depth + 1));

            }
        }
        return $hierarchyData;
    }


    /**
     * Saves the assocs data to to the database.
     *
     * @param $fieldId integer id of the field
     * @param $userGroupArray
     * @param $table
     * @param $field
     * @return void
     */
    public static function setAssocsToDb($mainFieldId, $data, $table, $mainField, $dataField, $assignZeroIdIfDataEmpty = false)
    {

        if (intval($mainFieldId) <= 0 || empty($table) || empty($mainFieldId) || empty($dataField)) {
            return false;
        }


        $db = Factory::getContainer()->get('DatabaseDriver');
        // save users per category on categories_users
        $query = $db->getQuery(true);
        $query->delete($db->quoteName($table))->where($db->quoteName($mainField) . ' = ' . $mainFieldId);
        $db->setQuery($query);
        $db->execute();

        if (empty($data) && $assignZeroIdIfDataEmpty) {
            $data[0] = 0;
        }

        foreach ($data as $curr) {
            $query = $db->getQuery(true);
            $query->insert($db->quoteName($table))
                ->set($db->quoteName($mainField) . ' = ' . $mainFieldId)
                ->set($db->quoteName($dataField) . ' = ' . intval($curr));
            $db->setQuery($query);
            $db->execute();
        }
    }

    /**
     * Retrieves all the assocs data from the database. Reusable for all forms that have allowed user groups as a field.
     *
     * @param $fieldId
     * @param $table
     * @param $field
     * @return mixed
     */
    public static function getAssocsFromDb($mainFieldId, $table, $mainField, $dataField)
    {

        if (intval($mainFieldId) <= 0 || empty($table) || empty($mainField) || empty($dataField)) {
            return [];
        }

        // load selected categories for item
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query
            ->select($db->quoteName($dataField))
            ->from($db->quoteName($table))
            ->where($db->quoteName($mainField) . ' = ' . intval($mainFieldId));

        $db->setQuery($query);

        return $db->loadColumn();
    }

    public function getOrderStatuses()
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from($db->quoteName('#__alfa_orders_statuses'));

        $db->setQuery($query);
        $row = $db->loadObjectList('id');

        return $row;
    }





    //    FUNCTION TO BE DELETED
    /* public static function iterateNestedArray($tree, $callback, $fullPath = false, $parentNames = '')
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
       }*/

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

}
