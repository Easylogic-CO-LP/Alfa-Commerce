<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\Database\ParameterType;
use Joomla\String\StringHelper;

/**
 * Category model.
 *
 * @since  1.0.1
 */
class CategoryModel extends AdminModel
{
	/**
	 * @var    string  The prefix to use with controller messages.
	 *
	 * @since  1.0.1
	 */
	protected $text_prefix = 'COM_ALFA';

	/**
	 * @var    string  Alias to manage history control
	 *
	 * @since  1.0.1
	 */
	public $typeAlias = 'com_alfa.category';

	/**
	 * @var    null  Item data
	 *
	 * @since  1.0.1
	 */
	protected $item = null;

	/**
	 * Method to get the record form.
	 *
	 * @param   array    $data      An optional array of data for the form to interogate.
	 * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
	 *
	 * @return  \JForm|boolean  A \JForm object on success, false on failure
	 *
	 * @since   1.0.1
	 */
	public function getForm($data = array(), $loadData = true)
	{
		$form = $this->loadForm(
			'com_alfa.category',
			'category',
			array(
				'control' => 'jform',
				'load_data' => $loadData
			)
		);

		if (empty($form))
		{
			return false;
		}

		return $form;
	}

	/**
	 * Method to get the data that should be injected in the form.
	 *
	 * @return  mixed  The data for the form.
	 *
	 * @since   1.0.1
	 */
	protected function loadFormData()
	{
		$data = Factory::getApplication()->getUserState('com_alfa.edit.category.data', array());

		if (empty($data))
		{
			$data = ($this->item === null ? $this->getItem() : $this->item);
		}

		return $data;
	}

	/**
	 * Method to get a single record.
	 *
	 * @param   integer  $pk  The id of the primary key.
	 *
	 * @return  mixed  Object on success, false on failure.
	 *
	 * @since   1.0.1
	 */
	public function getItem($pk = null)
	{
		$pk = (!empty($pk)) ? $pk : (int) $this->getState($this->getName() . '.id');

		if (isset($this->item[$pk]))
		{
			return $this->item[$pk];
		}

		if ($item = parent::getItem($pk))
		{
			$meta_data = json_decode($item->meta_data ?? '{}');
			$item->robots = $meta_data->robots ?? '';

			$item->allowedUsers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_users', 'category_id', 'user_id');
			$item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_usergroups', 'category_id', 'usergroup_id');
		}

		$this->item[$pk] = $item;

		return $item;
	}

	/**
	 * Method to save the form data.
	 *
	 * @param   array  $data  The form data.
	 *
	 * @return  boolean  True on success, False on error.
	 *
	 * @since   1.0.1
	 */
	public function save($data)
	{
		$table = $this->getTable();
		$pk = (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
		$isNew = $pk <= 0;
		$parentId = (int) ($data['parent_id'] ?? 0);

		// Track old values for cache clearing
		$oldParentId = null;
		$oldAlias = null;
		$oldName = null;

		if (!$isNew && $table->load($pk))
		{
			$oldParentId = (int) $table->parent_id;
			$oldAlias = $table->alias;
		}

		$data['alias'] = $data['alias'] ?: $data['name'];
		$data['alias'] = $this->sanitizeAlias($data['alias']);

		$data['alias'] = $this->getUniqueAlias($data['alias'], $parentId, $pk);

		// Determine if cache clearing is needed
		$needsCacheClearing = $isNew ||
			($oldParentId !== $parentId) ||
			($oldAlias !== $data['alias']) ||
			($oldName !== $data['name']);

		$data['meta_data'] = json_encode(['robots' => $data['robots']]);

		if (!parent::save($data))
		{
			return false;
		}

		$currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

		// VALIDATION: Check for circular references AFTER save
		if ($this->hasCircularReference($currentId))
		{
			// Fix the circular reference by setting parent_id to 0 (root level)
			$table->load($currentId);
			$table->parent_id = 0;
			$table->store();

			// Enqueue warning message
			Factory::getApplication()->enqueueMessage(
				Text::_('COM_ALFA_WARNING_CATEGORY_CIRCULAR_REFERENCE_FIXED'),
				'warning'
			);

			// Update cache clearing flag since we modified parent_id
			$needsCacheClearing = true;
			$parentId = 0; // Update for cache clearing
		}

		AlfaHelper::setAssocsToDb($currentId, $data['allowedUsers'] ?? [], '#__alfa_categories_users', 'category_id', 'user_id');
		AlfaHelper::setAssocsToDb($currentId, $data['allowedUserGroups'] ?? [], '#__alfa_categories_usergroups', 'category_id', 'usergroup_id');

		// Clear cache if needed
		if ($needsCacheClearing)
		{
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
	 * @param   int  $categoryId  Category ID to check
	 *
	 * @return  bool  True if circular reference detected, false if valid
	 * @since   1.0.1
	 */
	protected function hasCircularReference(int $categoryId): bool
	{
		if ($categoryId <= 0)
		{
			return false;
		}

		$db = $this->getDatabase();
		$currentId = $categoryId;
		$visited = [];
		$maxIterations = 100; // Safety limit
		$iterations = 0;

		while ($currentId > 0 && $iterations < $maxIterations)
		{
			// Already visited this ID - circular reference detected!
			if (isset($visited[$currentId]))
			{
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
			if ($parentId === 0)
			{
				return false;
			}

			// Self-reference detected
			if ($currentId === $parentId)
			{
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
	 * @param   int       $categoryId    Current category ID
	 * @param   int|null  $oldParentId   Old parent ID (if changed)
	 * @param   int       $newParentId   New parent ID
	 *
	 * @return  void
	 * @since   1.0.1
	 */
	protected function clearRelatedCache(int $categoryId, ?int $oldParentId, int $newParentId): void
	{
		// Clear current category and all descendants (recursive)
		CategoryHelper::clearCacheRecursive($categoryId);

		// If parent changed, clear old parent's cache
		if ($oldParentId !== null && $oldParentId !== $newParentId && $oldParentId > 0)
		{
			CategoryHelper::clearCache($oldParentId);
		}

		// Clear new parent's cache
		if ($newParentId > 0)
		{
			CategoryHelper::clearCache($newParentId);
		}

		// Clear all tree caches (affects navigation menus)
		// We do this by clearing the entire category group
//		CategoryHelper::clearCache();
	}

	/**
	 * Method to delete one or more records
	 *
	 * @param   array  &$pks  Record primary keys
	 *
	 * @return  boolean  True on success
	 * @since   1.0.1
	 */
	public function delete(&$pks)
	{
		$result = parent::delete($pks);

		if ($result)
		{
			// Clear cache for all deleted categories (includes children)
			foreach ($pks as $pk)
			{
				CategoryHelper::clearCacheRecursive((int) $pk);
			}
		}

		return $result;
	}

	/**
	 * Method to change the published state of one or more records
	 *
	 * @param   array    &$pks   A list of the primary keys to change
	 * @param   integer  $value  The value of the published state
	 *
	 * @return  boolean  True on success
	 * @since   1.0.1
	 */
	public function publish(&$pks, $value = 1)
	{
		$result = parent::publish($pks, $value);

		if ($result)
		{
			// Clear cache for published/unpublished categories
			// State changes affect visibility (getCategoryPath filters by state = 1)
			foreach ($pks as $pk)
			{
				CategoryHelper::clearCacheRecursive((int) $pk);
			}
		}

		return $result;
	}

	/**
	 * Prepare and sanitize the table prior to saving.
	 *
	 * @param   Table  $table  Table Object
	 *
	 * @return  void
	 *
	 * @since   1.0.1
	 */
	protected function prepareTable($table): void
	{
		$table->modified = Factory::getDate()->toSql();

		if (empty($table->publish_up))
		{
			$table->publish_up = null;
		}

		if (empty($table->publish_down))
		{
			$table->publish_down = null;
		}
	}

	/**
	 * Sanitize alias based on Joomla configuration.
	 *
	 * @param   string  $alias  The alias to sanitize.
	 *
	 * @return  string  The sanitized alias.
	 *
	 * @since   1.0.1
	 */
	protected function sanitizeAlias($alias)
	{
		$app = Factory::getApplication();

		if ($app->get('unicodeslugs') == 1)
		{
			return OutputFilter::stringUrlUnicodeSlug($alias);
		}

		return OutputFilter::stringURLSafe($alias);
	}

	/**
	 * Method to ensure alias is unique within the same parent category.
	 *
	 * @param   string   $alias     The desired alias.
	 * @param   integer  $parentId  The parent category id.
	 * @param   integer  $id        The category id (0 for new categories).
	 *
	 * @return  string  The unique alias.
	 *
	 * @since   1.0.1
	 */
	protected function getUniqueAlias($alias, $parentId, $id = 0)
	{
		$db = $this->getDatabase();
		$maxAttempts = 100;
		$attempts = 0;

		while ($attempts < $maxAttempts)
		{
			$attempts++;

			$query = $db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__alfa_categories'))
				->where($db->quoteName('alias') . ' = ' . $db->quote($alias))
				->where($db->quoteName('parent_id') . ' = ' . (int) $parentId);

			if ($id > 0)
			{
				$query->where($db->quoteName('id') . ' != ' . (int) $id);
			}

			$db->setQuery($query);

			if (!$db->loadResult())
			{
				return $alias;
			}

			$alias = StringHelper::increment($alias, 'dash');
		}

		return $alias . '-' . time();
	}
}