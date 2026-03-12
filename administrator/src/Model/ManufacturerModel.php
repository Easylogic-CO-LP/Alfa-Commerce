<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use JForm;
use Joomla\CMS\Event\Model;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;

/**
 * Manufacturer model.
 *
 * @since  1.0.1
 */
class ManufacturerModel extends AdminModel
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
    public $typeAlias = 'com_alfa.manufacturer';

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
            'com_alfa.manufacturer',
            'manufacturer',
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
    * Method to save the form data.
    *
     * @param array $data The form data.
    *
     * @return bool True on success, False on error.
    *
    * @since   1.6
    */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        $pk = (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));
        $isNew = $pk <= 0;

        if (empty($data['alias'])) {
            if ($app->get('unicodeslugs') == 1) {
                $data['alias'] = OutputFilter::stringUrlUnicodeSlug($data['title']);
            } else {
                $data['alias'] = OutputFilter::stringURLSafe($data['title']);
            }
        } else {
            if ($app->get('unicodeslugs') == 1) {
                $data['alias'] = OutputFilter::stringUrlUnicodeSlug($data['alias']);
            } else {
                $data['alias'] = OutputFilter::stringURLSafe($data['alias']);
            }
        }

        $data = $input->post->get('jform', [], 'array');
        $newDropped = $input->files->get('jform')['uploads'] ?? [];

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

        if (!empty($data['media'])) {
            MediaHelper::saveMedia(
                mediaData:      $data['media'],
                droppedMedia:   $newDropped,
                itemId:         $currentId,
                mediaOrigin:    $this->name,
                customFileName: $data['alias']
            );
        }

        return true;
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
        $data = Factory::getApplication()->getUserState('com_alfa.edit.manufacturer.data', []);

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
     * @param int $pk The id of the primary key.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            if (isset($item->params)) {
                $item->params = json_encode($item->params);
            }

            $item->medias = MediaHelper::getMediaData(
                origin: $this->name,
                itemIDs: $item->id,
            );
            // Do any procesing on fields here if needed
        }

        return $item;
    }

    /**
     * Method to duplicate an Manufacturer
     *
     * @param array &$pks An array of primary key IDs.
     *
     * @return bool True if successful.
     *
     * @throws Exception
     */
    public function duplicate(&$pks)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $dispatcher = $this->getDispatcher();

        // Access checks.
        if (!$user->authorise('core.create', 'com_alfa')) {
            throw new \Exception(Text::_('JERROR_CORE_CREATE_NOT_PERMITTED'));
        }

        $context = $this->option . '.' . $this->name;

        // Include the plugins for the save events.
        PluginHelper::importPlugin($this->events_map['save']);

        $table = $this->getTable();

        foreach ($pks as $pk) {
            if ($table->load($pk, true)) {
                // Reset the id to create a new record.
                $table->id = 0;

                if (!$table->check()) {
                    throw new \Exception($table->getError());
                }

                // Trigger the before save event.
                $beforeSaveEvent = new Model\BeforeSaveEvent($this->event_before_save, [
                    'context' => $context,
                    'subject' => $table,
                    'isNew' => true,
                    'data' => $table,
                ]);

                // Trigger the before save event.
                $result = $dispatcher->dispatch($this->event_before_save, $beforeSaveEvent)->getArgument('result', []);

                if (in_array(false, $result, true) || !$table->store()) {
                    throw new \Exception($table->getError());
                }

                // Trigger the after save event.
                $dispatcher->dispatch($this->event_after_save, new Model\AfterSaveEvent($this->event_after_save, [
                    'context' => $context,
                    'subject' => $table,
                    'isNew' => true,
                    'data' => $table,
                ]));
            } else {
                throw new \Exception($table->getError());
            }
        }

        // Clean cache
        $this->cleanCache();

        return true;
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param Table $table Table Object
     *
     * @return void
     *
     * @since   1.0.1
     */

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param Table $table Table Object
     *
     * @return void
     *
     * @since   1.0.1
     */
    protected function prepareTable($table)
    {
        //		if (empty($table->id))
        //		{
        //			// Set ordering to the last item if not set
        //			if (@$table->ordering === '')
        //			{
        //				$db = $this->getDbo();
        //				$db->setQuery('SELECT MAX(ordering) FROM #__alfa_categories');
        //				$max             = $db->loadResult();
        //				$table->ordering = $max + 1;
        //			}
        //		}

        $table->modified = Factory::getDate()->toSql();

        // if (empty($table->modified)) {

        // }

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        return parent::prepareTable($table);
    }
    // protected function prepareTable($table)
    // {
    // 	jimport('joomla.filter.output');

    // 	if (empty($table->id))
    // 	{
    // 		// Set ordering to the last item if not set
    // 		if (@$table->ordering === '')
    // 		{
    // 			$db = $this->getDbo();
    // 			$db->setQuery('SELECT MAX(ordering) FROM #__alfa_manufacturers');
    // 			$max             = $db->loadResult();
    // 			$table->ordering = $max + 1;
    // 		}
    // 	}
    // }
}
