<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;
// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Joomla\CMS\Table\Table;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\MVC\Model\AdminModel;
use \Joomla\CMS\Filter\OutputFilter;
use \Joomla\CMS\Event\Model;

/**
 * Category model.
 *
 * @since  1.0.1
 */
class CategoryModel extends AdminModel
{
    /**
     * @var    string  The prefix to use with controller messages.
     *
     * @since  1.0.1
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var    string  Alias to manage history control
     *
     * @since  1.0.1
     */
    public $typeAlias = 'com_alfa.category';

    /**
     * @var    null  Item data
     *
     * @since  1.0.1
     */
    protected $item = null;


    /**
     * Returns a reference to the a Table object, always creating it.
     *
     * @param string $type The table type to instantiate
     * @param string $prefix A prefix for the table class name. Optional.
     * @param array $config Configuration array for model. Optional.
     *
     * @return  Table    A database object
     *
     * @since   1.0.1
     */
    public function getTable($type = 'Category', $prefix = 'Administrator', $config = array())
    {
        return parent::getTable($type, $prefix, $config);
    }

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param boolean $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return  \JForm|boolean  A \JForm object on success, false on failure
     *
     * @since   1.0.1
     */
    public function getForm($data = array(), $loadData = true)
    {
        // Initialise variables.
        $app = Factory::getApplication();

        // Get the form.
        $form = $this->loadForm(
            'com_alfa.category',
            'category',
            array(
                'control' => 'jform',
                'load_data' => $loadData
            )
        );


        if (empty($form)) {
            return false;
        }

        return $form;
    }


    /**
     * Method to get the data that should be injected in the form.
     *
     * @return  mixed  The data for the form.
     *
     * @since   1.0.1
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState('com_alfa.edit.category.data', array());

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;

        }

        return $data;
    }

    /**
     * Method to get a single record.
     *
     * @param integer $pk The id of the primary key.
     *
     * @return  mixed    Object on success, false on failure.
     *
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {

        if ($item = parent::getItem($pk)) {
            if (isset($item->params)) {
                $item->params = json_encode($item->params);
            }

            // Do any procesing on fields here if needed
            $item->allowedUsers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_users', 'category_id','user_id');
            $item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_categories_usergroups', 'category_id','usergroup_id');
        }

        return $item;

    }

    /**
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return  boolean  True on success, False on error.
     *
     * @since   1.6
     */
    public function save($data)
    {

        $app = Factory::getApplication();
        
        $data['alias'] = $data['alias'] ?: $data['name'];

        if ($app->get('unicodeslugs') == 1){
            $data['alias'] = OutputFilter::stringUrlUnicodeSlug($data['alias']);
        } else {
            $data['alias'] = OutputFilter::stringURLSafe($data['alias']);
        }

        if (!parent::save($data))return false;

        $currentId = 0;
        if($data['id']>0){ //not a new
            $currentId = intval($data['id']);
        }else{ // is new
            $currentId = intval($this->getState($this->getName().'.id'));//get the id from setted joomla state
        }

        AlfaHelper::setAssocsToDb($currentId, $data['allowedUsers'], '#__alfa_categories_users', 'category_id','user_id');
        AlfaHelper::setAssocsToDb($currentId, $data['allowedUserGroups'], '#__alfa_categories_usergroups', 'category_id','usergroup_id');

        return true;

    }


    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param Table $table Table Object
     *
     * @return  void
     *
     * @since   1.0.1
     */
    protected function prepareTable($table)
    {

        $table->modified = Factory::getDate()->toSql();

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        $table->version++;

        return parent::prepareTable($table);
    }
}