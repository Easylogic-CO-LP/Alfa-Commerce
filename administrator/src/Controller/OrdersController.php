<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

/**
 * Orders list controller class.
 *
 * @since  1.0.1
 */
class OrdersController extends AdminController
{
    /**
     * Proxy for getModel.
     *
     * @param string $name Optional. Model name
     * @param string $prefix Optional. Class prefix
     * @param array $config Optional. Configuration array for model
     *
     * @return object The Model
     *
     * @since   1.0.1
     */
    public function getModel($name = 'Order', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Method to clone existing Orders
     *
     * @return void
     *
     * @throws Exception
     */
    // public function duplicate()
    // {
    // 	// Check for request forgeries
    // 	$this->checkToken();

    // 	// Get id(s)
    // 	$pks = $this->input->post->get('cid', array(), 'array');

    // 	try
    // 	{
    // 		if (empty($pks))
    // 		{
    // 			throw new \Exception(Text::_('COM_ALFA_NO_ELEMENT_SELECTED'));
    // 		}

    // 		ArrayHelper::toInteger($pks);
    // 		$model = $this->getModel();
    // 		$model->duplicate($pks);
    // 		$this->setMessage(Text::_('COM_ALFA_ITEMS_SUCCESS_DUPLICATED'));
    // 	}
    // 	catch (\Exception $e)
    // 	{
    // 		Factory::getApplication()->enqueueMessage($e->getMessage(), 'warning');
    // 	}

    // 	$this->setRedirect('index.php?option=com_alfa&view=orders');
    // }
}
