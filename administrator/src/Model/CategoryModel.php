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
	use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
	use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
	use JForm;
	use Joomla\CMS\Factory;
	use Joomla\CMS\Filter\OutputFilter;
	use Joomla\CMS\Language\Text;
	use Joomla\CMS\MVC\Model\AdminModel;
	use Joomla\CMS\Table\Table;
	use Joomla\Database\ParameterType;
	use Joomla\String\StringHelper;

	/**
	 * Category model.
	 *
	 * MULTILINGUAL
	 * ------------
	 * Translatable fields (name, alias, desc) live exclusively in language tables:
	 *   #__alfa_categories_en_gb,  #__alfa_categories_el_gr, …
	 *
	 * The main table (#__alfa_categories) only holds structural columns.
	 * The only exception is the alias column — it lives in both the main table
	 * (for frontend URL routing) and the language tables (per-language slugs).
	 *
	 * Form hydration is handled entirely by the field classes:
	 *   MultilingualTextField    — reads from DB in setup() via XML attributes
	 *   MultilingualEditorField  — same pattern
	 *
	 * Validation is handled entirely by the field classes:
	 *   MultilingualTextField::validate() reads raw POST for the default language.
	 *
	 * The model has zero multilingual knowledge beyond alias resolution and
	 * delegating to MultilingualHelper::saveMultilingualData() on save.
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
		 * @var string Alias to manage history control.
		 *
		 * @since  1.0.1
		 */
		public $typeAlias = 'com_alfa.category';

		/**
		 * @var null Item data.
		 *
		 * @since  1.0.1
		 */
		protected $item = null;

		/**
		 * Method to get the record form.
		 *
		 * @param   array  $data      An optional array of data for the form to interrogate.
		 * @param   bool   $loadData  True if the form is to load its own data (default), false if not.
		 *
		 * @return  JForm|bool  A JForm object on success, false on failure.
		 *
		 * @since   1.0.1
		 */
		public function getForm($data = [], $loadData = true)
		{
			$form = $this->loadForm(
				'com_alfa.category',
				'category',
				[
					'control'   => 'jform',
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
		 * Multilingual field values are loaded by the field classes themselves
		 * (MultilingualTextField / MultilingualEditorField) directly from the DB
		 * in their setup() method — the model does not need to inject flat keys.
		 *
		 * @return  mixed  The data for the form.
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
		 * @param   int  $pk  The id of the primary key.
		 *
		 * @return  mixed  Object on success, false on failure.
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
				$meta_data    = json_decode($item->meta_data ?? '{}');
				$item->robots = $meta_data->robots ?? '';

				$item->allowedUsers = AlfaHelper::getAssocsFromDb(
					$item->id,
					'#__alfa_categories_users',
					'category_id',
					'user_id',
				);

				$item->allowedUserGroups = AlfaHelper::getAssocsFromDb(
					$item->id,
					'#__alfa_categories_usergroups',
					'category_id',
					'usergroup_id',
				);

				$item->medias = MediaHelper::getMediaData(
					origin:  $this->name,
					itemIDs: $item->id,
				);
			}

			$this->item[$pk] = $item;

			return $item;
		}

		/**
		 * Method to save the form data.
		 *
		 * All translatable fields (name, alias, desc) live exclusively in the
		 * language tables and are written by MultilingualHelper::saveMultilingualData().
		 * The main table holds only structural columns — Joomla's Table::bind()
		 * silently ignores any keys that have no matching column.
		 *
		 * @param   array  $data  The form data.
		 *
		 * @return  bool  True on success, false on error.
		 *
		 * @since   1.0.1
		 */
		public function save($data)
		{
		    $app   = Factory::getApplication();
		    $input = $app->getInput();

		    // Use 'raw' filter to preserve HTML content from editor fields.
		    // The default 'array' filter strips HTML tags and wipes editor content.
		    $rawData = $input->post->get('jform', [], 'raw');
		    $data    = array_merge($data, $rawData);

		    $table    = $this->getTable();
		    $pk       = (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
		    $isNew    = $pk <= 0;
		    $parentId = (int) ($data['parent_id'] ?? 0);

		    // Track old parent for cache clearing — alias is no longer in the main
		    // table so we only need to know if the parent changed.
		    $oldParentId = null;

		    if (!$isNew && $table->load($pk)) {
		        $oldParentId = (int) $table->parent_id;
		    }

		    // Cache must be cleared when the category is new or its parent changes.
		    // Alias changes no longer trigger cache clearing here — alias lives in
		    // the language tables and is handled by MultilingualHelper.
		    $needsCacheClearing = $isNew
		        || ($oldParentId !== $parentId);

		    $data['meta_data'] = json_encode(['robots' => $data['robots'] ?? '']);

		    // Fetch uploads separately to avoid overwriting $data from $_POST
		    $newDropped = $input->files->get('jform')['uploads'] ?? [];

		    if (!parent::save($data)) {
		        return false;
		    }

		    $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

		    // MULTILINGUAL: Save per-language translations to language tables.
		    // Each language table stores name, alias, desc etc. for that language.
		    // Alias is treated as a slug — auto-generated from name when blank
		    // and sanitised via OutputFilter inside MultilingualHelper.
		    MultilingualHelper::saveMultilingualData(
		        currentId:         $currentId,
		        primaryColumnName: 'id_category',
		        tableName:         '#__alfa_categories',
		        data:              $data,
		        aliasFields:       ['alias'],
		    );

		    if (!empty($data['media'])) {
		        // Use the default-language alias as the custom file name.
		        $defaultLangTag = MultilingualHelper::getDefaultLanguageTag();

		        MediaHelper::saveMedia(
		            mediaData:      $data['media'],
		            droppedMedia:   $newDropped,
		            itemId:         $currentId,
		            mediaOrigin:    $this->name,
		            customFileName: $data['alias_' . $defaultLangTag] ?? '',
		        );
		    }

		    // VALIDATION: Check for circular references AFTER save.
		    // If detected, reset parent_id to root level and warn the user.
		    if ($this->hasCircularReference($currentId)) {
		        $table->load($currentId);
		        $table->parent_id = 0;
		        $table->store();

		        Factory::getApplication()->enqueueMessage(
		            Text::_('COM_ALFA_WARNING_CATEGORY_CIRCULAR_REFERENCE_FIXED'),
		            'warning',
		        );

		        $needsCacheClearing = true;
		        $parentId           = 0;
		    }

		    AlfaHelper::setAssocsToDb(
		        $currentId,
		        $data['allowedUsers'] ?? [],
		        '#__alfa_categories_users',
		        'category_id',
		        'user_id',
		    );

		    AlfaHelper::setAssocsToDb(
		        $currentId,
		        $data['allowedUserGroups'] ?? [],
		        '#__alfa_categories_usergroups',
		        'category_id',
		        'usergroup_id',
		    );

		    if ($needsCacheClearing) {
		        $this->clearRelatedCache($currentId, $oldParentId, $parentId);
		    }

		    return true;
		}

		/**
		 * Check if a category has circular references in its parent chain.
		 *
		 * Walks up the parent chain from the given category to root (0)
		 * to detect any circular references or invalid parent relationships.
		 *
		 * @param   int  $categoryId  Category ID to check.
		 *
		 * @return  bool  True if circular reference detected, false if valid.
		 *
		 * @since   1.0.1
		 */
		protected function hasCircularReference(int $categoryId): bool
		{
			if ($categoryId <= 0) {
				return false;
			}

			$db            = $this->getDatabase();
			$currentId     = $categoryId;
			$visited       = [];
			$maxIterations = 100;
			$iterations    = 0;

			while ($currentId > 0 && $iterations < $maxIterations) {
				if (isset($visited[$currentId])) {
					return true;
				}

				$visited[$currentId] = true;
				$iterations++;

				$query = $db->getQuery(true)
					->select($db->quoteName('parent_id'))
					->from($db->quoteName('#__alfa_categories'))
					->where($db->quoteName('id') . ' = :currentId')
					->bind(':currentId', $currentId, ParameterType::INTEGER);

				$parentId = (int) $db->setQuery($query)->loadResult();

				if ($parentId === 0) {
					return false;
				}

				if ($currentId === $parentId) {
					return true;
				}

				$currentId = $parentId;
			}

			return $iterations >= $maxIterations;
		}

		/**
		 * Clear cache for affected categories.
		 *
		 * Clears cache when:
		 * - New category is created
		 * - Category parent_id changes
		 * - Category alias changes
		 *
		 * @param   int       $categoryId   Current category ID.
		 * @param   int|null  $oldParentId  Old parent ID (if changed).
		 * @param   int       $newParentId  New parent ID.
		 *
		 * @since   1.0.1
		 */
		protected function clearRelatedCache(int $categoryId, ?int $oldParentId, int $newParentId): void
		{
			CategoryHelper::clearCacheRecursive($categoryId);

			if ($oldParentId !== null && $oldParentId !== $newParentId && $oldParentId > 0) {
				CategoryHelper::clearCache($oldParentId);
			}

			if ($newParentId > 0) {
				CategoryHelper::clearCache($newParentId);
			}
		}

		/**
		 * Method to delete one or more records.
		 *
		 * @param   array  &$pks  Record primary keys.
		 *
		 * @return  bool  True on success.
		 *
		 * @since   1.0.1
		 */
        public function delete(&$pks) {
            $retVal = parent::delete($pks);

            if ($retVal && !empty($pks)) {
                MultilingualHelper::deleteMultilingualData(
                    ids:               $pks,
                    primaryColumnName: 'id_category',
                    tableName:         '#__alfa_categories',
                );
            }

            return $retVal;
        }

		/**
		 * Method to change the published state of one or more records.
		 *
		 * @param   array  &$pks   A list of the primary keys to change.
		 * @param   int    $value  The value of the published state.
		 *
		 * @return  bool  True on success.
		 *
		 * @since   1.0.1
		 */
		public function publish(&$pks, $value = 1)
		{
			$result = parent::publish($pks, $value);

			if ($result) {
				foreach ($pks as $pk) {
					CategoryHelper::clearCacheRecursive((int) $pk);
				}
			}

			return $result;
		}

		/**
		 * Prepare and sanitize the table prior to saving.
		 *
		 * @param   Table  $table  Table Object.
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

			if ($app->get('unicodeslugs') == 1) {
				return OutputFilter::stringUrlUnicodeSlug($alias);
			}

			return OutputFilter::stringURLSafe($alias);
		}

		/**
		 * Method to ensure alias is unique within the same parent category.
		 *
		 * @param   string  $alias     The desired alias.
		 * @param   int     $parentId  The parent category id.
		 * @param   int     $id        The category id (0 for new categories).
		 *
		 * @return  string  The unique alias.
		 *
		 * @since   1.0.1
		 */
		protected function getUniqueAlias($alias, $parentId, $id = 0)
		{
			$db          = $this->getDatabase();
			$maxAttempts = 100;
			$attempts    = 0;

			while ($attempts < $maxAttempts) {
				$attempts++;

				$query = $db->getQuery(true)
					->select($db->quoteName('id'))
					->from($db->quoteName('#__alfa_categories'))
					->where($db->quoteName('alias') . ' = ' . $db->quote($alias))
					->where($db->quoteName('parent_id') . ' = ' . (int) $parentId);

				if ($id > 0) {
					$query->where($db->quoteName('id') . ' != ' . (int) $id);
				}

				$db->setQuery($query);

				if (!$db->loadResult()) {
					return $alias;
				}

				$alias = StringHelper::increment($alias, 'dash');
			}

			return $alias . '-' . time();
		}
	}