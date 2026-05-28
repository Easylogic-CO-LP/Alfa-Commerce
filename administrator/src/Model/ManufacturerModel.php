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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use JForm;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
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
     * @since   1.0.1
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // 'raw' filter preserves editor HTML and the per-language flat keys
        // (name_en_gb, alias_el_gr, …) that the default 'array' filter would strip.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = array_merge($data, $rawData);

        $pk = $data['id'] ?? (int) $this->getState($this->getName() . '.id');
        $isNew = $pk <= 0;

        $data['meta_data'] = json_encode(['robots' => $data['robots'] ?? '']);

        // Fetch uploads separately so $data (with the lang keys) is not clobbered.
        $newDropped = $input->files->get('jform')['uploads'] ?? [];

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

        // MULTILINGUAL: persist per-language translations (name, alias, desc,
        // meta_title, meta_desc). The alias slug is auto-generated, sanitised
        // and made globally unique (manufacturers are not nested → no scope).
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_manufacturer',
            tableName:         '#__alfa_manufacturers',
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_manufacturers'],
        );

        if (!empty($data['media'])) {
            $defaultLangTag = MultilingualHelper::getDefaultLanguageTag();

            MediaHelper::saveMedia(
                mediaData:      $data['media'],
                droppedMedia:   $newDropped,
                itemId:         $currentId,
                mediaOrigin:    $this->name,
                customFileName: $data['alias_' . $defaultLangTag] ?? '',
            );
        }

        return true;
    }

    /**
     * Method to delete one or more records.
     *
     * @param   array  &$pks  An array of record primary keys.
     *
     * @return  bool  True on success.
     *
     * @since   1.0.1
     */
    public function delete(&$pks)
    {
        $result = parent::delete($pks);

        if ($result && !empty($pks)) {
            // MULTILINGUAL: remove the per-language rows for the deleted manufacturers.
            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_manufacturer',
                tableName:         '#__alfa_manufacturers',
            );

            // Remove the manufacturers' media (rows; files when media_full_deletion is on).
            MediaHelper::deleteMediaForItems($pks, 'manufacturer');
        }

        return $result;
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

}
