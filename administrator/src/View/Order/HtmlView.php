<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\View\Order;
// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use \Joomla\CMS\Toolbar\ToolbarHelper;
use \Joomla\CMS\Factory;
use \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Joomla\CMS\Language\Text;

/**
 * View class for a single Order.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
	protected $state;

	protected $order;

	protected $form;

	/**
	 * Display the view
	 *
	 * @param   string  $tpl  Template name
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	public function display($tpl = null)
	{

//        exit;
		$this->state = $this->get('State');
		$this->order  = $this->get('Item');
		$this->form  = $this->get('Form');



		// Check for errors.
		if (count($errors = $this->get('Errors')))
		{
			throw new \Exception(implode("\n", $errors));
		}


        // alfa-payments onAdminOrderView event call. (Used for adding things to the back-end order view)

        $paymentOnAdminOrderViewEventName = "onAdminOrderView";
		$paymentOnAdminOrderViewCustomEventName = "paymentOnAdminOrderView";//to be used in frontend
        $paymentType = $this->order->payment->type;
		$paymentOnAdminOrderViewEventResult = Factory::getApplication()->bootPlugin($paymentType, "alfa-payments")->{$paymentOnAdminOrderViewEventName}($this->order);
        $this->{$paymentOnAdminOrderViewCustomEventName} = $paymentOnAdminOrderViewEventResult;

        $this->addToolbar();
        
		parent::display($tpl);

	}

	/**
	 * Add the page title and toolbar.
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	protected function addToolbar()
	{

//        exit;
		Factory::getApplication()->input->set('hidemainmenu', true);

		$user  = Factory::getApplication()->getIdentity();
		$isNew = ($this->order->id == 0);

		if (isset($this->order->checked_out))
		{
			$checkedOut = !($this->order->checked_out == 0 || $this->order->checked_out == $user->get('id'));
		}
		else
		{
			$checkedOut = false;
		}

		$canDo = AlfaHelper::getActions();

		ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ORDER'), "generic");

		// If not checked out, can save the item.
		if (!$checkedOut && ($canDo->get('core.edit') || ($canDo->get('core.create'))))
		{
			ToolbarHelper::apply('order.apply', 'JTOOLBAR_APPLY');
			ToolbarHelper::save('order.save', 'JTOOLBAR_SAVE');
		}


        //Save as new
//		if (!$checkedOut && ($canDo->get('core.create')))
//		{
//			ToolbarHelper::custom('order.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
//		}

		// If an existing item, can save to a copy.
//		if (!$isNew && $canDo->get('core.create'))
//		{
//			ToolbarHelper::custom('order.save2copy', 'save-copy.png', 'save-copy_f2.png', 'JTOOLBAR_SAVE_AS_COPY', false);
//		}

		

		if (empty($this->order->id))
		{
			ToolbarHelper::cancel('order.cancel', 'JTOOLBAR_CANCEL');
		}
		else
		{
			ToolbarHelper::cancel('order.cancel', 'JTOOLBAR_CLOSE');
		}
	}




}

