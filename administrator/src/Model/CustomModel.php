<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use JForm;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;

/**
 * Custom model.
 *
 * @since  1.0.1
 */
class CustomModel extends AdminModel
{
    /**
     * @var string The prefix to use with controller messages.
     *
     * @since  1.0.1
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Alias to manage history control
     *
     * @since  1.0.1
     */
    public $typeAlias = 'com_alfa.custom';

    /**
     * @var null Item data
     *
     * @since  1.0.1
     */
    protected $item = null;

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return JForm|bool A \JForm object on success, false on failure
     *
     * @since   1.0.1
     */
    public function getForm($data = [], $loadData = true)
    {
        // Initialise variables.
        $app = Factory::getApplication();

        // Get the form.
        $form = $this->loadForm(
            'com_alfa.custom',
            'custom',
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since   1.0.1
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState('com_alfa.edit.custom.data', []);

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;

            // Support for multiple or not foreign key field: type
            $array = [];

            foreach ((array) $data->type as $value) {
                if (!is_array($value)) {
                    $array[] = $value;
                }
            }
            if (!empty($array)) {
                $data->type = $array;
            }

            // Support for multiple or not foreign key field: required
            $array = [];

            foreach ((array) $data->required as $value) {
                if (!is_array($value)) {
                    $array[] = $value;
                }
            }
            if (!empty($array)) {
                $data->required = $array;
            }
        }

        return $data;
    }

    /**
     * Method to get a single record.
     *
     * @param int $pk The id of the primary key.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            //				if (isset($item->params))
            //				{
            //					$item->params = json_encode($item->params);
            //				}

            // Do any procesing on fields here if needed
        }

        return $item;
    }
}
