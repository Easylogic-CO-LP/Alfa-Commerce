<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
defined('_JEXEC') or die;
?>
<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_alfa&task=cart.placeOrder'); ?>"
      method="POST">

    <div class="row">
        <div class="col-md-6">
            <h4><?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_FORM_CUSTOMER_DETAILS'); ?></h4>

			<?php
			//                echo "<pre>";
			//
			//                foreach($this->form->getFieldsets() as $fieldset){
			//                    $fields = $this->form->getFieldset($fieldset->name);
			//                    print_r($fields);
			////                    exit;
			//                    if(count($fields)){
			//                        foreach($fields as $field) {
			//                            print_r($field);
			//                        }
			//                    }
			//                }
			//                echo "</pre>";
			//                exit;

			// Iterate through field groups.
			foreach ($this->form->getFieldsets() as $fieldset)
			{
				if ($fieldset->name === 'captcha')
					continue;

				// Render all fields of field group.
				$fields = $this->form->getFieldset($fieldset->name);
				if (count($fields))
					foreach ($fields as $field)
						echo $field->renderField();

			}
			?>
			<?php echo $this->form->renderFieldset('captcha'); ?>


        </div>

        <div class="col-md-6">
            <h4><?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_FORM_PAYMENT'); ?></h4>
			<?php echo $this->loadTemplate('select_payment'); ?>

            <h4><?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_FORM_SHIPMENT'); ?></h4>
			<?php echo $this->loadTemplate('select_shipment'); ?>
        </div>

    </div>


    <button type="submit" class="btn btn-primary w-100" data-main_button="1"><?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_BUTTON_PLACE_ORDER'); ?></button>

	<?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
</form>