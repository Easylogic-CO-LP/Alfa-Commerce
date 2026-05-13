<?php
	/**
	 * @version    CVS: 1.0.1
	 * @package    Com_Alfa
	 * @author     Agamemnon Fakas <info@easylogic.gr>
	 * @copyright  2024 Easylogic CO LP
	 * @license    GNU General Public License version 2 or later; see LICENSE.txt
	 */

	namespace Alfa\Component\Alfa\Administrator\Model;

	defined('_JEXEC') or die;

	use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
	use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
	use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
	use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
	use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
	use Joomla\CMS\Factory;
	use Joomla\CMS\Filter\OutputFilter;
	use Joomla\CMS\Language\Text;
	use Joomla\CMS\Log\Log;
	use Joomla\CMS\MVC\Model\AdminModel;
	use Joomla\CMS\Table\Table;
	use Joomla\String\StringHelper;
	use stdClass;
	use Throwable;

	/**
	 * ItemModel
	 *
	 * Admin model for a single Alfa item.
	 *
	 * ALIAS
	 * -----
	 * Alias is a translatable field.  Each language table stores its own alias,
	 * which MultilingualHelper handles automatically via $aliasFields = ['alias'].
	 * The main #__alfa_items.alias column stores the default-language alias as a
	 * universal fallback for any code that queries the main table directly.
	 * It is resolved from the default-language alias_* form field in save().
	 *
	 * PRICE INDEX SYNC
	 * ----------------
	 * The price index (#__alfa_items_price_index) must stay in sync whenever
	 * published state or pricing data changes.  Three hooks are in place:
	 *
	 *   save()    — re-indexes after ALL associated data is committed.
	 *               Call order inside save() is critical — syncPriceIndex() is LAST.
	 *
	 *   publish() — state = 1: syncPriceIndex()   — item enters the filter.
	 *               state ≠ 1: removePriceIndex() — item leaves the filter immediately.
	 *
	 *   delete()  — removePriceIndex() as an explicit safety net.
	 *               (FK ON DELETE CASCADE handles hard deletes automatically.)
	 *
	 * Sync failures are always non-fatal: logged as warnings, never block the operation.
	 *
	 * LOGGING
	 * -------
	 * Format:  [ItemModel::methodName] Human-readable message
	 * Written to  logs/com_alfa.php  under category  "com_alfa".
	 *
	 * @since  1.0.1
	 */
	class ItemModel extends AdminModel
	{
		/** @var string  Alias used by the history / version-control system. */
		public $typeAlias = 'com_alfa.item';

		/** @var string  Form name, also used as the form XML filename. */
		protected $formName = 'item';

		/** @var array|null  Cached item instances keyed by PK. */
		protected $item = null;

		/** @var bool  Disable the default copy/move batch operation. */
		protected $batch_copymove = false;

		/** @var array  Map of batch field names to their handler methods. */
		protected $batch_commands = [
			'category_id'     => 'batchCategory',
			'manufacturer_id' => 'batchManufacturer',
			'user_id'         => 'batchUser',
			'usergroup_id'    => 'batchUserGroup',
		];

		// =========================================================================
		//  Batch operations
		// =========================================================================

//		protected function batchUser($value, $pks, $contexts): bool
//		{
//			$app = Factory::getApplication();
//
//			if (sizeof($value) == 1 && $value[0] == '') {
//				$app->enqueueMessage(Text::_('COM_ALFA_USERS_NOT_CHANGED'), 'info');
//				return true;
//			}
//
//			foreach ($pks as $id) {
//				AlfaHelper::setAllowedUsers($id, $value, '#__alfa_items_users', 'item_id', 'user_id');
//			}
//
//			$app->enqueueMessage(Text::_('COM_ALFA_USERS_SET_SUCCESSFULLY'), 'info');
//			return true;
//		}

//		protected function batchUserGroup($value, $pks, $contexts): bool
//		{
//			$app = Factory::getApplication();
//
//			if (sizeof($value) == 1 && $value[0] == '') {
//				$app->enqueueMessage(Text::_('COM_ALFA_USERGROUP_NOT_CHANGED'), 'info');
//				return true;
//			}
//
//			foreach ($pks as $id) {
//				AlfaHelper::setAllowedUserGroups($id, $value, '#__alfa_items_usergroups', 'item_id', 'usergroup_id');
//			}
//
//			$app->enqueueMessage(Text::_('COM_ALFA_USERGROUP_SET_SUCCESSFULLY'), 'info');
//			return true;
//		}

		protected function batchManufacturer($value, $pks, $contexts): bool
		{
			$app = Factory::getApplication();

			if (sizeof($value) == 1 && $value[0] == '') {
				$app->enqueueMessage(Text::_('COM_ALFA_MANUFACTURERS_NOT_CHANGED'), 'info');
				return true;
			}

			foreach ($pks as $id) {
				AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');
			}

			$app->enqueueMessage(Text::_('COM_ALFA_MANUFACTURERS_SET_SUCCESSFULLY'), 'info');
			return true;
		}

		protected function batchCategory($value, $pks, $contexts): bool
		{
			$app = Factory::getApplication();

			if (sizeof($value) == 1 && $value[0] == '') {
				$app->enqueueMessage(Text::_('COM_ALFA_CATEGORIES_NOT_CHANGED'), 'info');
				return true;
			}

			$affectedCategories = [];

			foreach ($pks as $id) {
				$oldCats            = AlfaHelper::getAssocsFromDb($id, '#__alfa_items_categories', 'item_id', 'category_id');
				$affectedCategories = array_merge($affectedCategories, array_map('intval', $oldCats));
				AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_categories', 'item_id', 'category_id');
			}

			foreach (array_unique(array_merge($affectedCategories, array_map('intval', $value))) as $catId) {
				if ($catId > 0) {
					CategoryHelper::clearCache($catId);
				}
			}

			$app->enqueueMessage(Text::_('COM_ALFA_CATEGORIES_SET_SUCCESSFULLY'), 'info');
			return true;
		}

		// =========================================================================
		//  Form
		// =========================================================================

		public function getForm($data = [], $loadData = true)
		{
			$form = $this->loadForm(
				name:    'com_alfa.' . $this->formName,
				source:  $this->formName,
				options: ['control' => 'jform', 'load_data' => $loadData],
			);

			return $form ?: false;
		}

		/**
		 * Return the data to inject into the edit form.
		 *
		 * Checks the session first (re-populates after a validation failure),
		 * then falls back to getItem().
		 */
		protected function loadFormData(): mixed
		{
			$data = Factory::getApplication()->getUserState('com_alfa.edit.item.data', []);

			if (empty($data)) {
				$data = ($this->item === null ? $this->getItem() : $this->item);
			}

			return $data;
		}

		// =========================================================================
		//  CRUD
		// =========================================================================

		/**
		 * Return a single item record enriched with all its related data.
		 *
		 * Multilingual translations are flattened into the item object so that
		 * MultilingualTextField fields are pre-populated in the edit form.
		 * Example added properties:  $item->name_en_gb,  $item->alias_el_gr, …
		 *
		 * @param  int|null $pk
		 *
		 * @return object|false
		 */
		public function getItem($pk = null): object|false
		{
			$pk = (!empty($pk)) ? $pk : (int) $this->getState($this->getName() . '.id');

			if (isset($this->item[$pk])) {
				return $this->item[$pk];
			}

			if (!$item = parent::getItem($pk)) {
				return false;
			}

			$metaData     = json_decode($item->meta_data ?? '{}');
			$item->robots = $metaData->robots ?? '';

			$item->prices = $this->getPrices(id: (int) $item->id);

			$item->categories        = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_categories',    'item_id', 'category_id');
			$item->manufacturers     = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');
			$item->allowedUsers      = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_users',         'item_id', 'user_id');
			$item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_usergroups',    'item_id', 'usergroup_id');

			$item->medias = MediaHelper::getMediaData(
				origin:  $this->name,
				itemIDs: $item->id,
			);

			$this->item[$pk] = $item;

			return $item;
		}

		/**
		 * Save the form data.
		 *
		 * Call order is important — the price-index sync reads everything committed
		 * by the earlier steps and must therefore run last.
		 *
		 *   Step 1: parent::save()         — writes the #__alfa_items row.
		 *   Step 2: saveMultilingualData() — writes per-language translation rows
		 *                                    (including per-language aliases).
		 *   Step 3: setPrices()            — writes price rows.
		 *   Step 4: setAssocsToDb() × 4   — writes category / manufacturer / user assocs.
		 *   Step 5: syncPriceIndex()       — rebuilds the price-filter index.
		 *
		 * @param  array $data  Form data.
		 *
		 * @return bool
		 */
		public function save($data): bool
		{
			$app   = Factory::getApplication();
			$input = $app->getInput();

			$rawData = $input->post->get('jform', [], 'array');
			$data    = array_merge($data, $rawData);

			$pk = $data['id'] ?? (int) $this->getState($this->getName() . '.id');

//			$defaultLangTag = MultilingualHelper::getDefaultLanguageTag();
//			$data['alias']  = $this->resolveItemAlias(
//				raw:    $data['alias_' . $defaultLangTag] ?? $data['alias'] ?? '',
//				source: $data['name_'  . $defaultLangTag] ?? $data['name']  ?? '',
//				pk:     $pk,
//			);
//
//			$data['sku']    = $data['sku_' . $defaultLangTag] ?? $data['sku'] ?? '';
//			$data['name']   = $data['name_' . $defaultLangTag] ?? $data['name'] ?? '';
			$data['stock_low_message'] = 'Low stock!';
			$data['stock_zero_message'] = 'No stock!';

			// --- Dimension / weight guards -----------------------------------
			foreach (['width', 'height', 'depth', 'weight'] as $dimension) {
				if (($data[$dimension] ?? 0) < 0) {
					$data[$dimension] = 0;
				}
			}

			// --- Meta data ---------------------------------------------------
			$data['meta_data'] = json_encode(['robots' => $data['robots'] ?? '']);

			$newDropped = $input->files->get('jform')['uploads'] ?? [];

			// Step 1: save the main row (includes the resolved main-table alias).
			if (!parent::save($data)) {
				return false;
			}

			$currentId = $pk > 0 ? $pk : (int) $this->getState($this->getName() . '.id');

			// MULTILINGUAL: Save per-language translations to language tables.
			// Each language table stores name, alias, desc etc. for that language.
			// Alias is treated as a slug — auto-generated from name when blank
			// and sanitised via OutputFilter inside MultilingualHelper.
			MultilingualHelper::saveMultilingualData(
				currentId:         $currentId,
				primaryColumnName: 'id_item',
				tableName:         '#__alfa_items',
				data:              $data,
				aliasFields:       ['alias'],
			);

			// --- Media -------------------------------------------------------
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

			// --- Category default handling -----------------------------------
			$categories        = $data['categories'] ?? [];
			$categoryIdDefault = $data['id_category_default'] ?? ($categories[0] ?? null);

			if ($categoryIdDefault !== null && !in_array($categoryIdDefault, $categories)) {
				$categories[] = $categoryIdDefault;
			}

			// Step 3: save prices.
			$this->setPrices(productId: $currentId, prices: $data['prices'] ?? []);

			// Step 4: save associations.
			$oldCategories = AlfaHelper::getAssocsFromDb($currentId, '#__alfa_items_categories', 'item_id', 'category_id');
			$newCategories = $categories;

			AlfaHelper::setAssocsToDb($currentId, $newCategories,                '#__alfa_items_categories',    'item_id', 'category_id');
			AlfaHelper::setAssocsToDb($currentId, $data['manufacturers']     ?? [], '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');
			AlfaHelper::setAssocsToDb($currentId, $data['allowedUsers']      ?? [], '#__alfa_items_users',         'item_id', 'user_id');
			AlfaHelper::setAssocsToDb($currentId, $data['allowedUserGroups'] ?? [], '#__alfa_items_usergroups',    'item_id', 'usergroup_id');

			// Clear cache for every affected category (old + new).
			$allAffected = array_unique(array_merge(
				array_map('intval', $oldCategories),
				array_map('intval', $newCategories),
			));
			foreach ($allAffected as $catId) {
				if ($catId > 0) {
					CategoryHelper::clearCache($catId);
				}
			}

			// Step 5: rebuild price index — must be last.
			$this->syncPriceIndex(itemId: $currentId, callerMethod: __METHOD__);

			return true;
		}

		/**
		 * Change the published state of one or more items.
		 *
		 * Keeps the price-filter index in sync:
		 *   state = 1  → syncPriceIndex()   — item enters the filter.
		 *   state ≠ 1  → removePriceIndex() — item leaves the filter immediately.
		 *
		 * @param  array $pks
		 * @param  int   $value  1=publish, 0=unpublish, -2=trash.
		 *
		 * @return bool
		 */
		public function publish(&$pks, $value = 1): bool
		{
			if (!parent::publish($pks, $value)) {
				return false;
			}

			foreach ($pks as $pk) {
				$pk = (int) $pk;
				if ($pk <= 0) {
					continue;
				}

				if ((int) $value === 1) {
					$this->syncPriceIndex(itemId: $pk, callerMethod: __METHOD__);
				} else {
					$this->removePriceIndex(itemId: $pk, callerMethod: __METHOD__);
				}
			}

			return true;
		}

		/**
		 * Delete one or more item records.
		 *
		 * Removes price-index rows as an explicit safety net
		 * (FK ON DELETE CASCADE covers hard deletes, but not trash / soft-delete).
		 *
		 * @param  array $pks
		 *
		 * @return bool
		 */
        public function delete(&$pks) {
            $retVal = parent::delete($pks);

            if ($retVal && !empty($pks)) {
                MultilingualHelper::deleteMultilingualData(
                    ids:               $pks,
                    primaryColumnName: 'id_item',
                    tableName:         '#__alfa_items',
                );
            }

            return $retVal;
        }
		// =========================================================================
		//  Prices
		// =========================================================================

		/**
		 * Return all price rows for an item.
		 *
		 * @param  int $id  Item PK.
		 *
		 * @return array
		 */
		public function getPrices(int $id): array
		{
			if ($id <= 0) {
				return [];
			}

			$db = $this->getDatabase();

			return $db->setQuery(
				$db->getQuery(true)
					->select('*')
					->from('#__alfa_items_prices')
					->where('item_id = ' . $db->quote($id))
			)->loadAssocList();
		}

		/**
		 * Persist price rows for an item.
		 *
		 * Rows in $prices but not in the DB are inserted.
		 * Rows in both are updated ($updateNulls = true so nullable fields can be cleared).
		 * Rows in the DB but absent from $prices are deleted.
		 *
		 * @param  int   $productId
		 * @param  array $prices  Associative price records from the form.
		 *
		 * @return bool
		 */
		public function setPrices(int $productId, array $prices): bool
		{
			if ($productId <= 0) {
				return false;
			}

			$db = $this->getDatabase();

			$existingPriceIds = $db->setQuery(
				$db->getQuery(true)
					->select('id')
					->from('#__alfa_items_prices')
					->where('item_id = ' . $productId)
			)->loadColumn();

			$incomingIds = array_filter(
				array_map(static fn($p) => (int) ($p['id'] ?? 0), $prices),
				static fn(int $id): bool => $id > 0,
			);

			$idsToDelete = array_diff($existingPriceIds, $incomingIds);

			if (!empty($idsToDelete)) {
				$db->setQuery(
					$db->getQuery(true)
						->delete('#__alfa_items_prices')
						->whereIn('id', $idsToDelete)
				)->execute();
			}

			foreach ($prices as $price) {
				$row                  = new stdClass();
				$row->id              = (int)   ($price['id']              ?? 0);
				$row->item_id         = $productId;
				$row->value           = (float) ($price['value']           ?? 0.0);
				$row->country_id      = (int)   ($price['country_id']      ?? 0);
				$row->usergroup_id    = (int)   ($price['usergroup_id']    ?? 0);
				$row->user_id         = (int)   ($price['user_id']         ?? 0);
				$row->currency_id     = (int)   ($price['currency_id']     ?? 0);
				$row->modify          = (int)   ($price['modify']          ?? 0);
				$row->modify_function = $price['modify_function']          ?? null;
				$row->modify_type     = $price['modify_type']              ?? null;
				$row->publish_up      = !empty($price['publish_up'])       ? Factory::getDate($price['publish_up'])->toSql()   : null;
				$row->publish_down    = !empty($price['publish_down'])     ? Factory::getDate($price['publish_down'])->toSql() : null;
				$row->quantity_start  = !empty($price['quantity_start'])   ? (int) $price['quantity_start'] : null;
				$row->quantity_end    = !empty($price['quantity_end'])     ? (int) $price['quantity_end']   : null;

				if ($row->id > 0 && in_array($row->id, $existingPriceIds)) {
					$db->updateObject('#__alfa_items_prices', $row, 'id', true);
				} else {
					unset($row->id);
					$db->insertObject('#__alfa_items_prices', $row);
				}
			}

			return true;
		}

		// =========================================================================
		//  Table preparation
		// =========================================================================

		protected function prepareTable($table): void
		{
			$user = $this->getCurrentUser();

			$table->stock         = ($table->stock > 0)         ? $table->stock         : null;
			$table->stock_low     = ($table->stock_low > 0)     ? $table->stock_low     : null;
			$table->quantity_min  = ($table->quantity_min > 0)  ? $table->quantity_min  : 1;
			$table->quantity_step = ($table->quantity_step > 0) ? $table->quantity_step : 1;
			$table->quantity_max  = ($table->quantity_max > 0)  ? $table->quantity_max  : null;

			if ($table->id == 0 && empty($table->created_by)) {
				$table->created_by = $user->id;
			}

			$table->modified     = Factory::getDate()->toSql();
			$table->modified_by  = $user->id;
			$table->publish_up   = $table->publish_up   ?: null;
			$table->publish_down = $table->publish_down ?: null;

			parent::prepareTable($table);
		}

		// =========================================================================
		//  Alias helpers
		// =========================================================================

		/**
		 * Resolve the final alias for the main #__alfa_items row.
		 *
		 * The main table's alias column is the default-language alias used as a
		 * universal fallback.  Per-language aliases are handled by MultilingualHelper.
		 *
		 * Steps:
		 *   1. Fall back to $source (default-language name) when $raw is blank.
		 *   2. Sanitise through Joomla's OutputFilter.
		 *   3. Ensure uniqueness by incrementing on conflict.
		 *
		 * @param  string $raw     Alias from the default-language form field (may be empty).
		 * @param  string $source  Fallback: typically the default-language item name.
		 * @param  int    $pk      Current item PK (0 for new records).
		 *
		 * @return string  A sanitised, unique alias.
		 */
		protected function resolveItemAlias(string $raw, string $source, int $pk): string
		{
			$alias = $this->sanitiseAlias(alias: $raw ?: $source);

			return $this->ensureUniqueAlias(alias: $alias, pk: $pk);
		}

		/**
		 * Sanitise an alias string through Joomla's OutputFilter.
		 *
		 * Respects the global unicodeslugs configuration:
		 *   1 → allow unicode characters.
		 *   0 → ASCII-safe only.
		 */
		protected function sanitiseAlias(string $alias): string
		{
			$app = Factory::getApplication();

			return $app->get('unicodeslugs') == 1
				? OutputFilter::stringUrlUnicodeSlug($alias)
				: OutputFilter::stringURLSafe($alias);
		}

		/**
		 * Ensure an alias is unique within #__alfa_items.
		 *
		 * Increments with a dash suffix on conflict (e.g. "my-item" → "my-item-2")
		 * up to 100 attempts.  A timestamp suffix is the absolute last resort.
		 *
		 * @param  string $alias  Already-sanitised alias to check.
		 * @param  int    $pk     Item PK excluded from the duplicate check (0 = new record).
		 *
		 * @return string  A unique alias.
		 */
		protected function ensureUniqueAlias(string $alias, int $pk = 0): string
		{
			$db          = $this->getDatabase();
			$maxAttempts = 100;

			for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {

				$query = $db->getQuery(true)
					->select($db->quoteName('id'))
					->from($db->quoteName('#__alfa_items'))
					->where($db->quoteName('alias') . ' = ' . $db->quote($alias));

				if ($pk > 0) {
					$query->where($db->quoteName('id') . ' != ' . $pk);
				}

				if (!$db->setQuery($query)->loadResult()) {
					return $alias;
				}

				$alias = StringHelper::increment($alias, 'dash');
			}

			return $alias . '-' . time();
		}

		// =========================================================================
		//  Price index — private sync helpers
		// =========================================================================

		/**
		 * Re-build the price-filter index rows for a single item.
		 * Non-fatal: failures are logged as warnings but never block the caller.
		 *
		 * @param  int    $itemId
		 * @param  string $callerMethod  Pass __METHOD__ for grep-friendly log lines.
		 */
		private function syncPriceIndex(int $itemId, string $callerMethod): void
		{
			try {
				(new PriceIndexSyncService())->syncItem($itemId);
			} catch (Throwable $e) {
				Log::add(
					entry:     '[' . $callerMethod . '] Price index sync failed for item ' . $itemId . ': ' . $e->getMessage(),
					priority: Log::WARNING,
					category: 'com_alfa',
				);
			}
		}

		/**
		 * Remove price-filter index rows for a single item.
		 * Non-fatal: failures are logged as warnings but never block the caller.
		 *
		 * @param  int    $itemId
		 * @param  string $callerMethod  Pass __METHOD__ for grep-friendly log lines.
		 */
		private function removePriceIndex(int $itemId, string $callerMethod): void
		{
			try {
				(new PriceIndexSyncService())->deleteForItem($itemId);
			} catch (Throwable $e) {
				Log::add(
					entry:     '[' . $callerMethod . '] Price index delete failed for item ' . $itemId . ': ' . $e->getMessage(),
					priority: Log::WARNING,
					category: 'com_alfa',
				);
			}
		}
	}
