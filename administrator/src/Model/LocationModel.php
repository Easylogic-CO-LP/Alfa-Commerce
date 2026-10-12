<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

/**
 * Inventory location admin model.
 *
 * Locations are configuration DATA: this model never writes stock. A change
 * of a location's enabled state runs through the inventory kernel
 * (InventoryService::changeLocationState()), which moves exactly the salable
 * figures the change affects. Its guards protect the engine's structural
 * promises: exactly one default location always exists (and stays
 * published), and a location with any inventory footprint (stock records,
 * reservations, or ledger history) can be disabled but never deleted: the
 * audit trail outlives the place it happened at.
 *
 * @since  1.4.0
 */
class LocationModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'location';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save() normalises capabilities, strips the protected ownership columns and
     *   runs the default-location guards BEFORE parent::save(), so
     *   onAlfaAdminBeforeSave sees the normalised data, and a guard-refused save
     *   (unset/disable the default) fires no save bracket at all.
     * - The exactly-one-default sweep and the multilingual name persistence run
     *   AFTER parent::save(), so onAlfaAdminAfterSave fires before they complete.
     * - save() reloads the main form (getForm($data, false)) for the multilingual
     *   merge, so onAlfaAdminForm/PrepareData fire again during the save request:
     *   the merge form carries the plugin-contributed fields, which is intended.
     * - delete() removes the multilingual rows AFTER parent::delete(), so
     *   onAlfaAdminAfterDelete fires before that cleanup. A location holding any
     *   stock record is never deleted (canDelete()).
     *
     * @since  1.8.0
     */

    /**
     * @var string Message prefix for translations.
     * @since  1.4.0
     */
    protected $text_prefix = 'COM_ALFA_LOCATION';

    /**
     * Reserved ownership columns (a future owning domain claims them)
     * and service-maintained columns: never form-writable.
     *
     * @var string[]
     * @since  1.4.0
     */
    private const PROTECTED_COLUMNS = ['owner_type', 'owner_id'];

    /**
     * Build the edit form.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load the form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false
     *
     * @since  1.4.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.location', 'location', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        return $form ?: false;
    }

    /**
     * Load the data for the form (edited record or saved user state).
     *
     * @return mixed
     *
     * @since  1.4.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.location.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    /**
     * Save the location: capabilities normalise to the stored comma set,
     * default-location transitions keep the "exactly one default"
     * promise, per-language display names persist to the aux tables.
     *
     * An existing location is saved inside the inventory kernel's location
     * transition, so a change of its enabled state moves exactly its stock's
     * salable contribution, atomically with the save; a save that leaves the
     * state as it was moves nothing.
     *
     * @param array $data The form data.
     *
     * @return bool
     *
     * @since  1.4.0
     */
    public function save($data)
    {
        $rawData = Factory::getApplication()->getInput()->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, (array) $rawData, $this->getForm($data, false) ?: null);

        foreach (self::PROTECTED_COLUMNS as $column) {
            unset($data[$column]);
        }

        if (isset($data['capabilities']) && \is_array($data['capabilities'])) {
            $allowed = ['storage', 'fulfillment', 'sourcing'];
            $data['capabilities'] = implode(',', array_values(array_intersect($allowed, $data['capabilities'])));
        }

        $id = (int) ($data['id'] ?? 0);
        $wasDefault = $id > 0 && $this->isDefault($id);

        // The default location cannot renounce its role or be disabled:
        // another row becomes default first.
        if ($wasDefault) {
            if (empty($data['is_default'])) {
                $this->setError(Text::_('COM_ALFA_LOCATION_ERROR_UNSET_DEFAULT'));

                return false;
            }

            if (isset($data['state']) && (int) $data['state'] !== 1) {
                $this->setError(Text::_('COM_ALFA_LOCATION_ERROR_DISABLE_DEFAULT'));

                return false;
            }
        }

        // A location claiming the default role must be published.
        if (!empty($data['is_default'])) {
            $data['state'] = 1;
        }

        $saved = $id > 0
            ? (new InventoryService())->changeLocationState(
                locationIds: [$id],
                write: fn (): bool => (bool) parent::save($data),
                context: InventoryContext::ambient(),
            )
            : parent::save($data);

        if (!$saved) {
            return false;
        }

        $currentId = (int) ($data['id'] ?? 0) ?: (int) $this->getState($this->getName() . '.id');

        // Exactly one default: claiming the role releases every other row.
        if (!empty($data['is_default'])) {
            $db = $this->getDatabase();
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_inventory_locations'))
                    ->set($db->quoteName('is_default') . ' = 0')
                    ->where($db->quoteName('id') . ' != ' . $currentId),
            )->execute();
        }

        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_inventory_location',
            tableName:         '#__alfa_inventory_locations',
            data:              $data,
            aliasFields:       [],
        );

        return true;
    }

    /**
     * Deletion policy: never the default location; never a location with
     * inventory footprint: any stock record (a record at zero included),
     * reservations of any state, or ledger movements (history outlives its
     * place). The permission is asked first, so a refused user learns nothing
     * about the default or the footprint.
     *
     * @param object $record The record to test.
     *
     * @return bool
     *
     * @since  1.4.0
     */
    protected function canDelete($record)
    {
        if (!parent::canDelete($record)) {
            return false;
        }

        if ((int) ($record->is_default ?? 0) === 1) {
            $this->setError(Text::_('COM_ALFA_LOCATION_ERROR_DELETE_DEFAULT'));

            return false;
        }

        $footprint = $this->footprintOf(locationId: (int) $record->id);

        if ($footprint !== '') {
            $this->setError(Text::sprintf('COM_ALFA_LOCATION_ERROR_DELETE_FOOTPRINT', Text::_($footprint)));

            return false;
        }

        return true;
    }

    /**
     * Delete the location and its per-language names. A location holding any
     * stock record never reaches here (canDelete()), so no stock is touched.
     *
     * @param array $pks Record ids (by reference, as the parent contract).
     *
     * @return bool
     *
     * @since  1.4.0
     */
    public function delete(&$pks)
    {
        $pks = (array) $pks;

        if (!parent::delete($pks)) {
            return false;
        }

        if ($pks !== []) {
            MultilingualHelper::deleteMultilingualData(
                ids:               array_map('intval', $pks),
                primaryColumnName: 'id_inventory_location',
                tableName:         '#__alfa_inventory_locations',
            );
        }

        return true;
    }

    /**
     * Unpublish guard: the default location stays published.
     *
     * @param array $pks Record ids (by reference, as the parent contract).
     * @param int $value New state.
     *
     * @return bool
     *
     * @since  1.4.0
     */
    public function publish(&$pks, $value = 1)
    {
        if ((int) $value !== 1) {
            // Permission first, so a refused user is not told which location is the default.
            if (!AlfaHelper::can(section: $this->alfaEntity, action: 'edit.state', user: $this->getCurrentUser())) {
                Log::add(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), Log::WARNING, 'jerror');

                // Nothing changed: an empty list keeps the controller from reporting a count.
                $pks = [];

                return false;
            }

            foreach ((array) $pks as $pk) {
                if ($this->isDefault((int) $pk)) {
                    $this->setError(Text::_('COM_ALFA_LOCATION_ERROR_DISABLE_DEFAULT'));

                    return false;
                }
            }
        }

        // THE IDS ARE TAKEN BEFORE THE CALL, ON PURPOSE: AdminModel::publish()
        // prunes from $pks every row already at the target state and can return
        // true with the array emptied.
        //
        // The state write runs inside the inventory kernel's location
        // transition, which locks the locations' stock records first and moves
        // exactly the salable figures of the locations whose enabled state the
        // write changed, atomically with it. Archive, trash and unpublish are
        // aliases of publish (AdminController), so they arrive here too; a write
        // refused by return value changes no state and moves nothing.
        $targets = array_map('intval', (array) $pks);

        return (bool) (new InventoryService())->changeLocationState(
            locationIds: $targets,
            write: function () use (&$pks, $value): bool {
                return (bool) parent::publish($pks, $value);
            },
            context: InventoryContext::ambient(),
        );
    }

    /**
     * Whether the row is the default location.
     *
     * @param int $id Location id.
     *
     *
     * @since  1.4.0
     */
    private function isDefault(int $id): bool
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('is_default'))
            ->from($db->quoteName('#__alfa_inventory_locations'))
            ->where($db->quoteName('id') . ' = ' . $id);

        return (int) $db->setQuery($query)->loadResult() === 1;
    }

    /**
     * The location's inventory footprint as a language key, '' when clean.
     *
     * @param int $locationId Location id.
     *
     *
     * @since  1.4.0
     */
    private function footprintOf(int $locationId): string
    {
        $db = $this->getDatabase();

        $checks = [
            'COM_ALFA_LOCATION_FOOTPRINT_STOCK' => $db->getQuery(true)
                ->select('1')->from($db->quoteName('#__alfa_inventory_stock'))
                ->where($db->quoteName('location_id') . ' = ' . $locationId)
                ->where('(' . $db->quoteName('quantity') . ' != 0 OR ' . $db->quoteName('reserved_quantity') . ' != 0)')
                ->setLimit(1),
            'COM_ALFA_LOCATION_FOOTPRINT_RESERVATIONS' => $db->getQuery(true)
                ->select('1')->from($db->quoteName('#__alfa_inventory_reservations'))
                ->where($db->quoteName('location_id') . ' = ' . $locationId)
                ->setLimit(1),
            'COM_ALFA_LOCATION_FOOTPRINT_MOVEMENTS' => $db->getQuery(true)
                ->select('1')->from($db->quoteName('#__alfa_inventory_movements'))
                ->where($db->quoteName('location_id') . ' = ' . $locationId)
                ->setLimit(1),
            'COM_ALFA_LOCATION_FOOTPRINT_TRANSFERS' => $db->getQuery(true)
                ->select('1')->from($db->quoteName('#__alfa_inventory_transfers'))
                ->where('(' . $db->quoteName('source_location_id') . ' = ' . $locationId
                    . ' OR ' . $db->quoteName('destination_location_id') . ' = ' . $locationId . ')')
                ->where($db->quoteName('state') . ' != ' . $db->quote('completed'))
                ->setLimit(1),
            // Any stock record, a record at zero included: stock records are
            // written and removed only by the inventory kernel, never swept here.
            'COM_ALFA_LOCATION_FOOTPRINT_STOCK_RECORDS' => $db->getQuery(true)
                ->select('1')->from($db->quoteName('#__alfa_inventory_stock'))
                ->where($db->quoteName('location_id') . ' = ' . $locationId)
                ->setLimit(1),
        ];

        foreach ($checks as $key => $query) {
            if ($db->setQuery($query)->loadResult()) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Split the stored comma set back to an array for the form.
     *
     * @param int $pk Record id.
     *
     * @return object|false
     *
     * @since  1.4.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if (\is_object($item) && isset($item->capabilities) && \is_string($item->capabilities)) {
            $item->capabilities = array_values(array_filter(explode(',', $item->capabilities)));
        }

        return $item;
    }
}
