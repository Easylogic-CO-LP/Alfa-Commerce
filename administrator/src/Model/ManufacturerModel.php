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
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Joomla\String\StringHelper;

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
     * @since   1.0.1
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        $pk = $data['id'] ?? (int) $this->getState($this->getName() . '.id');
        $isNew = $pk <= 0;

        $data['alias'] = $data['alias'] ?: $data['name'];
        $data['alias'] = $this->sanitizeAlias($data['alias']);
        $data['alias'] = $this->getUniqueAlias($data['alias'], $pk);

        $data['meta_data'] = json_encode(['robots' => $data['robots'] ?? '']);

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
                customFileName: $data['alias'],
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
		        itemIDs: $item->id
	        );

            $meta_data = json_decode($item->meta_data ?? '{}');
            $item->robots = $meta_data->robots ?? '';
        }

        return $item;
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
    protected function prepareTable($table)
    {
        $table->modified = Factory::getDate()->toSql();

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        return parent::prepareTable($table);
    }

    /**
     * Method to sanitize the alias.
     *
     * @param string $alias The alias to sanitize.
     *
     * @return string The sanitized alias.
     *
     * @since   1.0.1
     */
    protected function sanitizeAlias($alias)
    {
        $app = Factory::getApplication();

        if ($app->get('unicodeslugs') == 1) {
            return OutputFilter::stringUrlUnicodeSlug($alias);
        }

        return OutputFilter::stringURLSafe($alias);
    }

    /**
     * Method to ensure alias is unique.
     *
     * @param string $alias The desired alias.
     * @param int $id The item id (0 for new items).
     *
     * @return string The unique alias.
     *
     * @since   1.0.1
     */
    protected function getUniqueAlias($alias, $id = 0)
    {
        $db = $this->getDatabase();
        $maxAttempts = 100;
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_manufacturers'))
                ->where($db->quoteName('alias') . ' = ' . $db->quote($alias));

            if ($id > 0) {
                $query->where($db->quoteName('id') . ' != ' . (int) $id);
            }

            $db->setQuery($query);

            if (!$db->loadResult()) {
                return $alias;
            }

            $alias = StringHelper::increment($alias, 'dash');
        }

        // Fallback if max attempts reached
        return $alias . '-' . time();
    }
}
