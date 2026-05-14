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

	use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
	use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
	use Joomla\CMS\MVC\Model\ListModel;

	/**
	 * ItemsModel
	 *
	 * List model for the Alfa items admin view.
	 *
	 * MULTILINGUAL
	 * ------------
	 * The query joins the current-language translation table so that the name
	 * column displayed and searched in the list view is always in the active
	 * language.  When the active language differs from the site default, a second
	 * LEFT JOIN provides a COALESCE fallback so items without a translation row
	 * still appear in the list.
	 *
	 * @since  1.0.1
	 */
	class ItemsModel extends ListModel
	{
		public function __construct($config = [], ?MVCFactoryInterface $factory = null)
		{
			if (empty($config['filter_fields'])) {
				$config['filter_fields'] = [
					'state',        'a.state',
					'ordering',     'a.ordering',
					'created_by',   'a.created_by',
					'modified_by',  'a.modified_by',
					'id',           'a.id',
					'sku',          'a.sku',
					'gtin',         'a.gtin',
					'mpn',          'a.mpn',
					'stock',        'a.stock',
					'stock_action', 'a.stock_action',
					'manage_stock', 'a.manage_stock',
					// Translatable fields — referenced via the lang-table alias in ORDER BY.
					'name',
					'alias',
					'short_desc',
					'full_desc',
					'meta_title',
					'meta_desc',
				];
			}

			parent::__construct($config, $factory);
		}

		/**
		 * @param  string $ordering   Default ordering column.
		 * @param  string $direction  Default ordering direction.
		 */
		protected function populateState($ordering = 'a.id', $direction = 'DESC')
		{
			parent::populateState($ordering, $direction);
		}

		protected function getStoreId($id = '')
		{
			$id .= ':' . $this->getState('filter.search');
			$id .= ':' . $this->getState('filter.state');

			return parent::getStoreId($id);
		}

		/**
		 * Build the SQL query for the items list.
		 *
		 * Joins the current-language translation table via
		 * MultilingualHelper::addMultilingualJoinToQuery() so name / alias are
		 * always fetched in the active language.
		 */
		protected function getListQuery()
		{
			$db    = $this->getDatabase();
			$query = $db->getQuery(true);

			$query->select(
				$this->getState('list.select', 'DISTINCT a.*'),
			);
			$query->from('`#__alfa_items` AS a');

			// User joins for the checked-out indicator and audit columns.
			$query->select('uc.name AS uEditor');
			$query->join('LEFT', '#__users AS uc ON uc.id = a.checked_out');

			$query->select('`created_by`.name AS `created_by`');
			$query->join('LEFT', '#__users AS `created_by` ON `created_by`.id = a.`created_by`');

			$query->select('`modified_by`.name AS `modified_by`');
			$query->join('LEFT', '#__users AS `modified_by` ON `modified_by`.id = a.`modified_by`');

			MultilingualHelper::addMultilingualJoinToQuery(
				query:             $query,
				mainAlias:         'a',
				mainPrimaryColumn: 'id',
				langTableBase:     '#__alfa_items',
				langPrimaryColumn: 'id_item',
				fields:            ['name', 'alias', 'short_desc', 'full_desc', 'stock_low_message', 'stock_zero_message'],
			);

			// Filter by published state.
			$published = $this->getState('filter.state');

			if (is_numeric($published)) {
				$query->where('a.state = ' . (int) $published);
			} elseif (empty($published)) {
				$query->where('(a.state IN (0, 1))');
			}

			// Filter by search — always search the current-language name column.
			$search = $this->getState('filter.search');

			if (!empty($search)) {
				if (stripos($search, 'id:') === 0) {
					$query->where('a.id = ' . (int) substr($search, 3));
				} else {
					$search = $db->quote('%' . $db->escape($search, true) . '%');
					$query->where('`name` LIKE ' . $search);
				}
			}

			// Ordering.
			$orderCol  = $this->state->get('list.ordering', 'a.id');
			$orderDirn = $this->state->get('list.direction', 'DESC');

			if ($orderCol && $orderDirn) {
				$query->order($db->escape($orderCol . ' ' . $orderDirn));
			}

			return $query;
		}

		public function getItems()
		{
			return parent::getItems();
		}
	}
