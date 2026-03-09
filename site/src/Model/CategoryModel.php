<?php

/**
 * @package    Com_Alfa
 */

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Exception;
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
     * Get category item
     *
     * @param int|null $pk Category ID
     *
     * @return object|false Category object or false on error
     */
    public function getItem($pk = null)
    {
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

            // Add link
            $data->link = Route::_('index.php?option=com_alfa&view=items&category_id=' . (int) $data->id);

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
