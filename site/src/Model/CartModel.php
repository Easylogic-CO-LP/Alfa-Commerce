<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Site\Helper\CartHelper;
use Joomla\CMS\MVC\Model\FormModel;

/**
 * Alfa model.
 *
 * @since  1.0.1
 */
class CartModel extends FormModel   // Should this extend FormModel? Contact model does do that.
{
    protected $form_name = 'cart';

    /**
     * Return the cart as a CartHelper instance for the given cart id.
     *
     * @param int|null $pk The cart primary key; falsy values resolve the current user's/guest cart.
     *
     * @return CartHelper The cart helper.
     *
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {
        // $app = Factory::getApplication();
        // $user = $app->getIdentity();
        // $input = $app->input;
        $pk = (int) ($pk ?: 0);

        $cart = new CartHelper($pk);

        return $cart;
    }

    /**
     * Load the cart checkout form (control name "cartform") and apply the custom Alfa form fields.
     *
     * @param array $data Data to bind to the form.
     * @param bool $loadData Whether to load the form data from getData().
     *
     * @return \Joomla\CMS\Form\Form|false The prepared form, or false on failure.
     *
     * @since   1.0.1
     */
    public function getForm($data = [], $loadData = true)
    {
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

        $form = $this->loadForm(
            'com_alfa.cart',
            'cart',
            [
                'control' => 'cartform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        //	    $app->setUserState('com_dianemo.cart.data', $data);

        //	    print_r($data);
        //		exit;

        FieldsHelper::prepareForm('cart.form', $form, $data, 'cart');

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
