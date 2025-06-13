<?php

namespace Alfa\Component\Alfa\Administrator\Field;

// use Joomla\CMS\Factory;
// use Joomla\CMS\Form\Field\ListField;
// use Joomla\CMS\HTML\HTMLHelper;
// use Joomla\CMS\Language\Text;
// use Joomla\CMS\Router\Route;
// use Joomla\Component\Menus\Administrator\Helper\MenusHelper;
// use Joomla\Utilities\ArrayHelper;
// use Joomla\CMS\Table\Table;
// use Joomla\CMS\Component\ComponentHelper;
// phpcs:disable PSR1.Files.SideEffects

\defined('_JEXEC') or die;


use Alfa\Component\Alfa\Administrator\Helper\OrderHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

// use Joomla\CMS\Date\Date;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
// use Joomla\CMS\HTML\HTMLHelper;

class OrderItemsField extends ListField
{

    protected $type = 'orderItems';

    protected $options = [];

	protected $value;

    protected function getOptions()
    {   

        $this->options = parent::getOptions();
	    $inputIdName = $this->getAttribute('input_id','id');

        $app = Factory::getApplication();
        $input = $app->input;


        $orderId = $input->get($inputIdName,0);

//		print_r($orderId);
        // $orderId = $this->form->getValue('id_order',0)

        if($orderId > 0){
            $orderItems = OrderHelper::getOrderItems($orderId);

            foreach ($orderItems as $index=>$item) {

//	            $this->options[] = HTMLHelper::_('select.option', $item->id, $item->name);

                $this->options['item-' . $item->id] =
                        [
                            'value' => $item->id,
                            'text' => $item->name
                        ];
            }
        }

        // unset($this->options[0]);

        return $this->options;

    }

}