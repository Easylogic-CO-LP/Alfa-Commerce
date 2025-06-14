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

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use \Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormBehaviorTrait;
use Joomla\CMS\MVC\Model\FormModel;
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
class CartModel extends FormModel   // Should this extend FormModel? Contact model does do that.
{

    protected $form_name = "cart";

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

    public function getForm($data = [], $loadData = true){


//	    $data = array(
//		    "com_alfa" => array(
//			    "first-name" => "awdawdw",
//			    "food-23" => "aadawwad",
//			    "tilefono" => "123123123",
//			    "notes" => "adasdasdsa"
//		    ),
//		    "4310931fc4262195a88437a18d8c6cbd" => "",
//		    "a9eaede0ecca708872626e5fe15e3f12" => 0
//	    );


//	    Factory::getApplication()->setUserState('com_dianemo.cart.data', ['first-name'=>'blabla']);

        $form = $this->loadForm('com_alfa.cart', 'cart',
            array(
                'control' => 'cartform',
                'load_data' => $loadData
            ));

        if(empty($form)){
            return false;
        }

//	    $app->setUserState('com_dianemo.cart.data', $data);


//	    print_r($data);
//		exit;

        FieldsHelper::prepareForm('com_alfa.cart', $form, $data);

//        echo '<pre>';
//        echo htmlspecialchars($form->getXml()->saveXML());
//        echo '</pre>';
//        exit;

//        echo "<pre>";
//        print_r($form);
//        echo "</pre>";
//        exit;

//        foreach($form->getFieldsets() as $fieldset){
//            echo "<pre>";
//            print_r($fieldset);
//            echo "</pre>";
//        }
//
//        exit;

        return $form;
    }




}
