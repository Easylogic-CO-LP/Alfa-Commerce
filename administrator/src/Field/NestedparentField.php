<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('JPATH_BASE') or die;

use Joomla\CMS\Helper\UserGroupsHelper;
use \Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

/**
 * Supports an HTML select list of categories
 *
 * @since  1.0.1
 */
class NestedparentField extends ListField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  1.0.1
	 */
	protected $type = 'nestedparent';

	/**
	 * Method to get the field options.
	 *
	 * @return  array  The field option objects.
	 *
	 * @since   1.0.1
	 */
	protected function getOptions()
	{
		$options = array();
		$table   = $this->getAttribute('table');

		$db = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true)
			->select('DISTINCT(a.id) AS value, a.title AS text, a.level, a.lft')
			->from($table . ' AS a');


		// Prevent parenting to children of this item.
		if ($id = $this->form->getValue('id'))
		{
			$query->join('LEFT', $db->quoteName($table) . ' AS p ON p.id = ' . (int) $id)
				->where('NOT(a.lft >= p.lft AND a.rgt <= p.rgt)');
		}

		$query->order('a.lft ASC');

		// Get the options.
		$db->setQuery($query);

		try
		{
			$options = $db->loadObjectList();
		}
		catch (\RuntimeException $e)
		{
			throw new \Exception($e->getMessage(), 500);
		}

		// Pad the option text with spaces using depth level as a multiplier.
		for ($i = 0, $n = count($options); $i < $n; $i++)
		{
				$options[$i]->text = str_repeat('- ', $options[$i]->level) . $options[$i]->text;
		}

		// Merge any additional options in the XML definition.
		$options = array_merge(parent::getOptions(), $options);

		return $options;
	}
}
