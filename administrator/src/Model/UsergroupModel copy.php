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

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use SimpleXMLElement;

/**
 * Usergroup model.
 *
 * @since  1.0.1
 */
class UsergroupModel extends AdminModel
{
    /**
     * @var string The prefix to use with controller messages.
     * @since  1.0.1
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Alias to manage history control.
     * @since  1.0.1
     */
    public $typeAlias = 'com_alfa.usergroup';

    /**
     * @var object|null Cached item data.
     * @since  1.0.1
     */
    protected $item = null;

    /**
     * Map of config.xml fieldset name => DB column / form group name.
     *
     * To expose a new fieldset from config.xml at the usergroup level,
     * simply add an entry here — no other changes required.
     *
     * @var array<string, string>
     * @since  1.0.1
     */
    private const OVERRIDE_FIELDSETS = [
        'prices' => 'prices_display',
        // 'discounts' => 'discount_settings',
    ];

    /**
     * Field types that are purely cosmetic / UI-only and carry no storable value.
     *
     * @var string[]
     * @since  1.0.1
     */
    private const SKIP_FIELD_TYPES = ['note'];

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    /**
     * Method to get the record form.
     *
     * Loads the base usergroup form, then dynamically injects all fieldsets
     * defined in OVERRIDE_FIELDSETS from config.xml and re-binds their data.
     *
     * @param array $data An optional array of data for the form to interrogate.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return \Joomla\CMS\Form\Form|bool A Form object on success, false on failure.
     * @since   1.0.1
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.usergroup',
            'usergroup',
            ['control' => 'jform', 'load_data' => $loadData],
        );

        if (empty($form)) {
            return false;
        }

        // Inject config.xml fieldsets into the form as named field groups.
        $this->injectConfigFieldsets($form);

        // Re-bind stored group values to the newly injected fields.
        // This is necessary because loadForm() calls bind() before our
        // fields are added, so they would otherwise render empty.
        if ($loadData) {
            $data = $this->loadFormData();

            foreach (self::OVERRIDE_FIELDSETS as $groupName) {
                $values = is_object($data)
                    ? ($data->$groupName ?? [])
                    : ($data[$groupName] ?? []);

                if (!empty($values) && is_array($values)) {
                    $form->bind([$groupName => $values]);
                }
            }
        }

        return $form;
    }

    /**
     * Parse config.xml, extract all fieldsets defined in OVERRIDE_FIELDSETS,
     * flatten their nested sub-fieldsets, strip incompatible attributes (showon),
     * and inject the result into the form under the mapped field group name.
     *
     *
     * @since   1.0.1
     */
    protected function injectConfigFieldsets(\Joomla\CMS\Form\Form $form): void
    {
        $configPath = JPATH_ADMINISTRATOR . '/components/com_alfa/config.xml';

        if (!file_exists($configPath)) {
            return;
        }

        $configXml = simplexml_load_file($configPath);

        if (!$configXml) {
            return;
        }

        foreach (self::OVERRIDE_FIELDSETS as $fieldsetName => $groupName) {
            $matched = $configXml->xpath("//fieldset[@name='{$fieldsetName}']");

            if (empty($matched)) {
                continue;
            }

            $sourceFieldset = $matched[0];
            $fieldsetLabel = (string) ($sourceFieldset['label'] ?? strtoupper($fieldsetName));

            // Build a self-contained form XML fragment.
            // Fields are flattened into a single fieldset regardless of how
            // deeply nested the sub-fieldsets are in config.xml.
            $xmlStr = '<?xml version="1.0" encoding="utf-8"?><form>'
                . '<fields name="' . $this->esc($groupName) . '">'
                . '<fieldset'
                . ' name="' . $this->esc($fieldsetName) . '"'
                . ' label="' . $this->esc($fieldsetLabel) . '"'
                . '>';

            // .//field catches direct children AND fields inside nested sub-fieldsets.
            foreach ($sourceFieldset->xpath('.//field') as $field) {
                $type = strtolower((string) $field['type']);
                $name = (string) $field['name'];

                if (($type !== 'spacer' && empty($name)) || in_array($type, self::SKIP_FIELD_TYPES, true)) {
                    continue;
                }

                $xmlStr .= $this->buildFieldXml($field);
            }

            $xmlStr .= '</fieldset></fields></form>';

            $form->load($xmlStr);
        }
    }

    /**
     * Rebuild a single <field> element as clean XML string.
     *
     * - Preserves all original attributes except those that would break
     *   rendering in a different form context.
     * - Strips `showon` because field names are now namespaced under a group
     *   (e.g. price_settings[base_price_show]) and bare-name references inside
     *   showon conditions would silently fail to resolve.
     * - Preserves <option> children for list/radio fields.
     *
     *
     * @return string Valid XML fragment for a single <field>.
     * @since   1.0.1
     */
    protected function buildFieldXml(SimpleXMLElement $field): string
    {
        /** Attributes that must be removed when re-using a config field in a sub-form context. */
        // $stripAttributes = ['showon'];
        $stripAttributes = [];

        $xml = '<field';

        foreach ($field->attributes() as $attrName => $attrValue) {
            if (in_array((string) $attrName, $stripAttributes, true)) {
                continue;
            }

            $xml .= ' ' . $attrName . '="' . $this->esc((string) $attrValue) . '"';
        }

        // Rebuild child <option> elements if present (list / radio fields).
        if (count($field->option) > 0) {
            $xml .= '>';

            foreach ($field->option as $option) {
                $xml .= '<option value="' . $this->esc((string) $option['value']) . '">'
                    . $this->esc((string) $option)
                    . '</option>';
            }

            $xml .= '</field>';
        } else {
            $xml .= ' />';
        }

        return $xml;
    }

    /**
     * Escape a string for safe embedding inside an XML attribute value.
     *
     *
     * @since   1.0.1
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // -------------------------------------------------------------------------
    // Data load / save
    // -------------------------------------------------------------------------

    /**
     * Method to get the data that should be injected in the form.
     *
     * Guards against stale session data belonging to a different record.
     *
     * @return mixed The data for the form.
     * @since   1.0.1
     */
    protected function loadFormData()
    {
        $app = Factory::getApplication();
        $data = $app->getUserState('com_alfa.edit.usergroup.data', []);

        if (!empty($data)) {
            $sessionId = is_array($data) ? ($data['id'] ?? 0) : ($data->id ?? 0);
            $requestId = (int) $app->getInput()->getInt('id', 0);

            // Discard session data if it belongs to a different record.
            if ($requestId > 0 && (int) $sessionId !== $requestId) {
                $data = [];
            }
        }

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
     * Decodes each JSON group column into an associative array so the form
     * can bind the values to the correct fields.
     *
     * @param int|null $pk The id of the primary key.
     *
     * @return object|bool Object on success, false on failure.
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if (!$item) {
            return false;
        }

        // Decode each overridable group column from JSON → array for form binding.
        foreach (self::OVERRIDE_FIELDSETS as $fieldsetName => $groupName) {
            if (!empty($item->$groupName) && is_string($item->$groupName)) {
                $item->$groupName = json_decode($item->$groupName, true) ?: [];
            } else {
                $item->$groupName = [];
            }
        }

        // Populate the read-only name field from the Joomla usergroup title.
        if (!empty($item->usergroup_id)) {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('title'))
                ->from($db->quoteName('#__usergroups'))
                ->where($db->quoteName('id') . ' = ' . (int) $item->usergroup_id);

            $item->name = $db->setQuery($query)->loadResult() ?? '';
        }

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * Encodes each overridable group array as a JSON string before persistence.
     * Empty values are stripped so that null is stored instead of an empty object,
     * allowing downstream code to cleanly fall back to global component settings.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, false on failure.
     * @since   1.0.1
     */
    public function save($data)
    {
        foreach (self::OVERRIDE_FIELDSETS as $fieldsetName => $groupName) {
            if (!empty($data[$groupName]) && is_array($data[$groupName])) {
                $filtered = array_filter(
                    $data[$groupName],
                    static fn ($value) => $value !== '' && $value !== null,
                );

                $data[$groupName] = !empty($filtered)
                    ? json_encode($filtered, JSON_UNESCAPED_UNICODE)
                    : null;
            } else {
                $data[$groupName] = null;
            }
        }

        return parent::save($data);
    }
}
