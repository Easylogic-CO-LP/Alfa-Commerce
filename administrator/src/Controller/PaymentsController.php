<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Utilities\ArrayHelper;

/**
 * Payments list controller class.
 *
 * @since  1.0.1
 */
class PaymentsController extends AdminController
{
    /**
     * Method to clone existing Payments
     *
     * @return  void
     *
     * @throws  Exception
     */
    public function duplicate()
    {
        // Check for request forgeries
        $this->checkToken();

        // Get id(s)
        $pks = $this->input->post->get('cid', [], 'array');

        try {
            if (empty($pks)) {
                throw new \Exception(Text::_('COM_ALFA_NO_ELEMENT_SELECTED'));
            }

            ArrayHelper::toInteger($pks);
            $model = $this->getModel();
            $model->duplicate($pks);
            $this->setMessage(Text::_('COM_ALFA_ITEMS_SUCCESS_DUPLICATED'));
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'warning');
        }

        $this->setRedirect('index.php?option=com_alfa&view=payments');
    }

    /**
     * Proxy for getModel.
     *
     * @param   string  $name    Optional. Model name
     * @param   string  $prefix  Optional. Class prefix
     * @param   array   $config  Optional. Configuration array for model
     *
     * @return  object	The Model
     *
     * @since   1.0.1
     */
    public function getModel($name = 'Payment', $prefix = 'Administrator', $config = [])
    {
        return parent::getModel($name, $prefix, ['ignore_request' => true]);
    }



    /**
     * Method to save the submitted ordering values for records via AJAX.
     *
     * @return  void
     *
     * @since   1.0.1
     *
     * @throws  Exception
     */
    public function saveOrderAjax()
    {
        // Get the input
        $pks   = $this->input->post->get('cid', [], 'array');
        $order = $this->input->post->get('order', [], 'array');

        // Sanitize the input
        ArrayHelper::toInteger($pks);
        ArrayHelper::toInteger($order);

        // Get the model
        $model = $this->getModel();

        // Save the ordering
        $return = $model->saveorder($pks, $order);

        if ($return) {
            echo "1";
        }

        // Close the application
        Factory::getApplication()->close();
    }
}
