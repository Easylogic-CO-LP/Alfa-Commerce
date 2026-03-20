<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Alfa Commerce
 */

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ItemModel as BaseItemModel;
use Joomla\CMS\Router\Route;
use Joomla\Database\ParameterType;

/**
 * Category model
 *
 * @since  1.0.1
 */
class CategoryModel extends BaseItemModel
{
    public $_item;
    protected $_context = 'com_alfa.category';

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @return void
	 *
	 * @throws \Exception
	 * @since  1.0.1
	 */
	protected function populateState(): void
	{
		$app = Factory::getApplication();

		$id = $app->input->getInt('id', 0);
		$this->setState('category.id', $id);

		parent::populateState();
	}

    /**
     * Get category item
     *
     * @param int|null $pk Category ID
     *
     * @return object|false Category object or false on error
     */
    public function getItem($pk = null)
    {
	    $pk = (!empty($pk)) ? $pk : (int) $this->getState('category.id');

        // Return cached
        if (isset($this->_item[$pk])) {
            return $this->_item[$pk];
        }

        try {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('a.*')
                ->from($db->quoteName('#__alfa_categories', 'a'))
                ->where($db->quoteName('a.id') . ' = :pk')
                ->bind(':pk', $pk, ParameterType::INTEGER);

            $db->setQuery($query);
            $data = $db->loadObject();

            if (!$data) {
                $this->_item[$pk] = false;
                return false;
            }

	        // Add links
	        $data->link = Route::_('index.php?option=com_alfa&view=items&category_id' . (int) $data->id);

	        // Get category media
	        $data->medias = MediaHelper::getMediaData(
		        origin: 'category',
		        itemIDs: (int) $data->id
	        );

            $this->_item[$pk] = $data;

            return $data;
        } catch (Exception $e) {
            if ($e->getCode() == 404) {
                throw $e;
            }

            $this->setError($e);
            $this->_item[$pk] = false;

            return false;
        }
    }
}