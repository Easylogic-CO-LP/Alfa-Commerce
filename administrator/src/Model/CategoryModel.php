<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use JForm;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Joomla\Database\ParameterType;

/**
 * Category model.
 *
 * @since  1.0.1
 */
class CategoryModel extends AdminModel
{
    /**
     * @var string The prefix to use with controller messages.
     *
     * @since  1.0.1
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Alias to manage history control
     *
     * @since  1.0.1
     */
    public $typeAlias = 'com_alfa.category';

    /**
     * @var null Item data
     *
     * @since  1.0.1
     */
    protected $item = null;

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return JForm|bool A \JForm object on success, false on failure
     *
     * @since   1.0.1
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.category',
            'category',
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since   1.0.1
     */
    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_alfa.edit.category.data', []);

        if (empty($data)) {
            $data = ($this->item === null ? $this->getItem() : $this->item);
        }

        return $data;
    }

    /**
     * Method to get a single record.
     *
     * @param int $pk The id of the primary key.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {
        $pk = (!empty($pk)) ? $pk : (int) $this->getState($this->getName() . '.id');

        if (isset($this->item[$pk])) {
            return $this->item[$pk];
        }

        if ($item = parent::getItem($pk)) {
            $meta_data = json_decode($item->meta_data ?? '{}');
            $item->robots = $meta_data->robots ?? '';

            $item->allowedUsers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_users', 'category_id', 'user_id');
            $item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_usergroups', 'category_id', 'usergroup_id');

            // get media origin (cat, man, item...)
            $item->medias = MediaHelper::getMediaData(
                origin: $this->name,
                itemIDs: $item->id,
            );
        }

        $this->item[$pk] = $item;

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
     *
     * @since   1.0.1
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // 'raw' filter preserves editor HTML and captures the per-language flat
        // keys (name_en_gb, alias_el_gr, full_desc_en_gb …) that the default
        // 'array' filter would strip. Merge over the validated $data.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = array_merge($data, $rawData);

        $table = $this->getTable();
        $pk = (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
        $isNew = $pk <= 0;
        $parentId = (int) ($data['parent_id'] ?? 0);

        // Alias lives exclusively in the language tables (lang-tables-only), so
        // only the parent change matters for cache clearing here.
        $oldParentId = null;

        if (!$isNew && $table->load($pk)) {
            $oldParentId = (int) $table->parent_id;
        }

        $needsCacheClearing = $isNew || ($oldParentId !== $parentId);

        $data['meta_data'] = json_encode(['robots' => $data['robots'] ?? '']);

        // Fetch uploads separately so $data (with the lang keys) is not clobbered.
        $newDropped = $input->files->get('jform')['uploads'] ?? [];

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

        // MULTILINGUAL: persist per-language translations (name, alias, desc,
        // meta_title, meta_desc) to the language tables. The alias slug is
        // auto-generated, sanitised and made unique within the parent category.
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_category',
            tableName:         '#__alfa_categories',
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_categories'],
            aliasUniqueScope:  MultilingualAliasConfig::SCOPE['#__alfa_categories'] ?? [],
        );

        if (!empty($data['media'])) {
            $defaultLangTag = MultilingualHelper::getDefaultLanguageTag();

            MediaHelper::saveMedia(
                mediaData:      $data['media'],
                droppedMedia:   $newDropped,
                itemId:         $currentId,
                mediaOrigin:    $this->name,
                customFileName: $data['alias_' . $defaultLangTag] ?? '',
            );
        }

        // VALIDATION: Check for circular references AFTER save
        if ($this->hasCircularReference($currentId)) {
            // Fix the circular reference by setting parent_id to 0 (root level)
            $table->load($currentId);
            $table->parent_id = 0;
            $table->store();

            // Enqueue warning message
            Factory::getApplication()->enqueueMessage(
                Text::_('COM_ALFA_WARNING_CATEGORY_CIRCULAR_REFERENCE_FIXED'),
                'warning',
            );

            // Update cache clearing flag since we modified parent_id
            $needsCacheClearing = true;
            $parentId = 0; // Update for cache clearing
        }

        AlfaHelper::setAssocsToDb($currentId, $data['allowedUsers'] ?? [], '#__alfa_categories_users', 'category_id', 'user_id');
        AlfaHelper::setAssocsToDb($currentId, $data['allowedUserGroups'] ?? [], '#__alfa_categories_usergroups', 'category_id', 'usergroup_id');

        // Clear cache if needed
        if ($needsCacheClearing) {
            $this->clearRelatedCache($currentId, $oldParentId, $parentId);
        }

        return true;
    }

    /**
     * Check if a category has circular references in its parent chain
     *
     * Walks up the parent chain from the given category to root (0)
     * to detect any circular references or invalid parent relationships.
     *
     * @param int $categoryId Category ID to check
     *
     * @return bool True if circular reference detected, false if valid
     * @since   1.0.1
     */
    protected function hasCircularReference(int $categoryId): bool
    {
        if ($categoryId <= 0) {
            return false;
        }

        $db = $this->getDatabase();
        $currentId = $categoryId;
        $visited = [];
        $maxIterations = 100; // Safety limit
        $iterations = 0;

        while ($currentId > 0 && $iterations < $maxIterations) {
            // Already visited this ID - circular reference detected!
            if (isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $iterations++;

            // Get parent of current category
            $query = $db->getQuery(true)
                ->select($db->quoteName('parent_id'))
                ->from($db->quoteName('#__alfa_categories'))
                ->where($db->quoteName('id') . ' = :currentId')
                ->bind(':currentId', $currentId, ParameterType::INTEGER);

            $parentId = (int) $db->setQuery($query)->loadResult();

            // Reached root - valid chain
            if ($parentId === 0) {
                return false;
            }

            // Self-reference detected
            if ($currentId === $parentId) {
                return true;
            }

            $currentId = $parentId;
        }

        // Hit max iterations - likely circular reference
        return $iterations >= $maxIterations;
    }

    /**
     * Clear cache for affected categories
     *
     * Clears cache when:
     * - New category is created
     * - Category parent_id changes
     * - Category alias changes
     *
     * @param int $categoryId Current category ID
     * @param int|null $oldParentId Old parent ID (if changed)
     * @param int $newParentId New parent ID
     *
     * @since   1.0.1
     */
    protected function clearRelatedCache(int $categoryId, ?int $oldParentId, int $newParentId): void
    {
        // Clear current category and all descendants (recursive)
        CategoryHelper::clearCacheRecursive($categoryId);

        // If parent changed, clear old parent's cache
        if ($oldParentId !== null && $oldParentId !== $newParentId && $oldParentId > 0) {
            CategoryHelper::clearCache($oldParentId);
        }

        // Clear new parent's cache
        if ($newParentId > 0) {
            CategoryHelper::clearCache($newParentId);
        }

        // Clear all tree caches (affects navigation menus)
        // We do this by clearing the entire category group
        //		CategoryHelper::clearCache();
    }

    /**
     * Method to delete one or more records
     *
     * @param array &$pks Record primary keys
     *
     * @return bool True on success
     * @since   1.0.1
     */
    public function delete(&$pks)
    {
        $result = parent::delete($pks);

        if ($result) {
            // Clear cache for all deleted categories (includes children)
            foreach ($pks as $pk) {
                CategoryHelper::clearCacheRecursive((int) $pk);
            }

            // MULTILINGUAL: remove the per-language rows for the deleted categories.
            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_category',
                tableName:         '#__alfa_categories',
            );

            // Remove the categories' media (rows; files when media_full_deletion is on).
            MediaHelper::deleteMediaForItems($pks, 'category');
        }

        return $result;
    }

    /**
     * Method to change the published state of one or more records
     *
     * @param array &$pks A list of the primary keys to change
     * @param int $value The value of the published state
     *
     * @return bool True on success
     * @since   1.0.1
     */
    public function publish(&$pks, $value = 1)
    {
        $result = parent::publish($pks, $value);

        if ($result) {
            // Clear cache for published/unpublished categories
            // State changes affect visibility (getCategoryPath filters by state = 1)
            foreach ($pks as $pk) {
                CategoryHelper::clearCacheRecursive((int) $pk);
            }
        }

        return $result;
    }

    /**
     * Prepare and sanitize the table prior to saving.
     *
     * @param Table $table Table Object
     *
     *
     * @since   1.0.1
     */
    protected function prepareTable($table): void
    {
        $table->modified = Factory::getDate()->toSql();

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }
    }
}
