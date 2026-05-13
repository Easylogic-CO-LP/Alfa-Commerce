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
	use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
	use Joomla\CMS\MVC\Model\ListModel;

	/**
	 * CategoriesModel
	 *
	 * List model for the Alfa categories admin view.
	 *
	 * MULTILINGUAL
	 * ------------
	 * The query joins the current-language translation table via
	 * MultilingualHelper::addMultilingualJoinToQuery() so that name / alias are
	 * always fetched in the active language.  Items without a translation row
	 * still appear in the list thanks to the LEFT JOIN + COALESCE fallback.
	 *
	 * @since  1.0.1
	 */
	class CategoriesModel extends ListModel
	{
		public function __construct($config = [])
		{
			if (empty($config['filter_fields'])) {
				$config['filter_fields'] = [
					'ordering',    'a.ordering',
					'created_by',  'a.created_by',
					'modified_by', 'a.modified_by',
					'parent_id',   'a.parent_id',
					'id',          'a.id',
					'state',       'a.state',
					'meta_title',  'a.meta_title',
					'meta_desc',   'a.meta_desc',
					// Translatable fields — referenced via lang-table alias in ORDER BY.
					'name',
					'alias',
				];
			}

			parent::__construct($config);
		}

		/**
		 * @param  string $ordering
		 * @param  string $direction
		 */
		protected function populateState($ordering = 'a.id', $direction = 'ASC')
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
		 * Build the SQL query for the categories list.
		 *
		 * Joins the current-language translation table via
		 * MultilingualHelper::addMultilingualJoinToQuery() so name / alias are
		 * always fetched in the active language.
		 */
		protected function getListQuery()
		{
		    $db    = $this->getDatabase();
		    $query = $db->getQuery(true);

		    $query->select($this->getState('list.select', 'DISTINCT a.*'));
		    $query->from('`#__alfa_categories` AS a');

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
		        langTableBase:     '#__alfa_categories',
		        langPrimaryColumn: 'id_category',
		        fields:            ['name', 'alias'],
		    );

		    $published = $this->getState('filter.state');

		    if (is_numeric($published)) {
		        $query->where('a.state = ' . (int) $published);
		    } elseif (empty($published)) {
		        $query->where('(a.state IN (0, 1))');
		    }

		    $search = $this->getState('filter.search');

		    if (!empty($search)) {
		        if (stripos($search, 'id:') === 0) {
		            $query->where('a.id = ' . (int) substr($search, 3));
		        } else {
		            $search = $db->quote('%' . $db->escape($search, true) . '%');
		            $query->where('`name` LIKE ' . $search);
		        }
		    }

		    $query->order('a.parent_id ASC');

		    $orderCol  = $this->state->get('list.ordering', 'a.id');
		    $orderDirn = $this->state->get('list.direction', 'ASC');

		    if ($orderCol && $orderDirn) {
		        $query->order($db->escape($orderCol . ' ' . $orderDirn));
		    }

		    return $query;
		}

		/**
		 * Return the list of categories enriched with hierarchy data
		 * (indentation level, depth indicators) for the nested tree display.
		 */
		public function getItems()
		{
			$items = parent::getItems();

			return AlfaHelper::addHierarchyData($items);
		}
	}
