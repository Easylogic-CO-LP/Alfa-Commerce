<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Model;
// No direct access.
defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use \Joomla\Utilities\ArrayHelper;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Table\Table;
use \Joomla\CMS\MVC\Model\ItemModel;
use \Joomla\CMS\Object\CMSObject;
use \Joomla\CMS\User\UserFactoryInterface;
use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use \Alfa\Component\Alfa\Site\Helper\CartHelper;
use \Joomla\Database\ParameterType;

/**
 * Alfa model.
 *
 * @since  1.0.1
 */
class CartModel extends ItemModel
{

    /**
     * Method to get an object.
     *
     * @param integer $id The id of the object to get.
     *
     * @return  mixed    Object on success, false on failure.
     *
     * @throws Exception
     */
    public function getItem($pk = null)
    {
        // $app = Factory::getApplication();
        // $user = $app->getIdentity();
        // $input = $app->input;
        $pk = (int)($pk ?: 0);

        $cart = new CartHelper($pk);


        return $cart;
       
    }


}