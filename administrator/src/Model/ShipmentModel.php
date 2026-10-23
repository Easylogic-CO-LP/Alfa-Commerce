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

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Throwable;

/**
 * Shipment model.
 *
 * @since  1.0.0
 */
class ShipmentModel extends AlfaAdminModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('shipment').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'shipment';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save() persists the per-language translations and the five scope pivots
     *   AFTER parent::save(), so onAlfaAdminAfterSave fires with the base row
     *   written but those side tables not yet updated.
     * - delete() removes the scope pivots and translations AFTER parent::delete(),
     *   so onAlfaAdminAfterDelete fires before that cleanup runs.
     */

    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.shipment';

    protected $formName = 'shipment';

    /**
     * Per-request getItem() cache, keyed by the loaded item's id.
     *
     * @var object|null
     *
     * @since  1.0.0
     */
    protected $item = null;

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return Form|bool A Form object on success, false on failure
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        // Initialise variables.
        $app = Factory::getApplication();
        // Form::addFieldPath(JPATH_ADMINISTRATOR . '/cfomponents/com_users/models/fields');

        // Get the form.
        $form = $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        // Get ID of the article from input
        $idFromInput = $app->getInput()->getInt('id', 0);

        // On edit order, we get ID of order from order.id state, but on save, we use data from input
        $id = (int) $this->getState($this->formName . '.id', $idFromInput);

        if (empty($form)) {
            return false;
        }

        $item = ($this->item === null ? $this->getItem() : $this->item);

        AlfaHelper::addPluginForm(form: $form, data: (array) $data, values: (array) ($item->params ?? []), type: 'shipments');

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since  1.0.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.shipment.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = ($this->item === null ? $this->getItem() : $this->item);

            $data->shipmentsparams = $data->params;
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
     * @since  1.0.0
     */
    public function getItem($pk = null)
    {
        // Per-request cache, keyed by pk: reuse the loaded item only when
        // the SAME id is asked again (the order hydration loop calls this
        // once per shipment row).
        $pk = (int) ($pk ?? $this->getState($this->getName() . '.id'));

        if ($this->item !== null && (int) ($this->item->id ?? 0) === $pk) {
            return $this->item;
        }

        // parent::getItem returns false on failure; cache objects only.
        $item = parent::getItem($pk);
        $this->item = \is_object($item) ? $item : null;

        if ($item) {
            // `name` is multilingual: it lives ONLY in the per-language
            // tables, so the base row carries none. Resolve it here
            // (current language -> site default) so every consumer of
            // getItem() sees a usable method name.
            if (!empty($item->id)) {
                $item->name = MultilingualHelper::getTranslatedValue(
                    id:                (int) $item->id,
                    tableName:         '#__alfa_shipments',
                    primaryColumnName: 'id_shipment',
                    field:             'name',
                );
            }

            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_categories', 'shipment_id', 'category_id');
            $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_manufacturers', 'shipment_id', 'manufacturer_id');
            $item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_places', 'shipment_id', 'place_id');

            $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_users', 'shipment_id', 'user_id');
            $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_usergroups', 'shipment_id', 'usergroup_id');

            // A new method allows everything until it is restricted: 0, shown as the "All ..." choice, which is what
            // a save of untouched scope fields stores.
            if (empty($item->id)) {
                $item->categories = [0];
                $item->manufacturers = [0];
                $item->places = [0];
                $item->users = [0];
                $item->usergroups = [0];
            }

            // The tax rules its charge is taxed with; none = no shipping tax.
            $item->taxes = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_taxes', 'shipment_id', 'tax_id');
        }

        return $item;
    }

    /**
     * Save the shipment: fold the plugin params into the params JSON, persist
     * the per-language translations, and sync the category, manufacturer, place,
     * user and usergroup associations.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, false on failure.
     *
     * @since  1.0.0
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();

        $input = $app->getInput();

        // Recover the per-language flat keys (e.g. name_en_gb) and other undeclared
        // keys the form filter drops, without overwriting any validated field or
        // resurrecting the housekeeping fields the form unsets. Recovered
        // per-language values are safehtml-cleaned like their base field.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        // The method plugin's settings are written from their group. A REST POST or PATCH that does not send the
        // group reads nothing: a shipping method being created starts with none, and an existing one keeps the
        // settings it has; empty settings are never written over stored ones over REST (since 2.1.0). On the
        // method form the group is absent only when every field of it posted nothing (all cleared): that is
        // saved as no settings.
        if (\array_key_exists('shipmentsparams', $data)) {
            $data['params'] = json_encode($data['shipmentsparams']);
        } elseif ((int) ($data['id'] ?? 0) > 0 && $app->isClient('api')) {
            unset($data['params']);
        } else {
            $data['params'] = '{}';
        }

        // A NEW category must be available (not archived or trashed, not inside an archived or trashed category);
        // one the record already holds is kept. Refused before anything is written.
        $categoryRefusal = CategoryPickerPolicy::refusalForSave(
            recordId:     (int) ($data['id'] ?? 0),
            posted:       (array) ($data['categories'] ?? []),
            table:        '#__alfa_shipment_categories',
            recordColumn: 'shipment_id',
        );

        if ($categoryRefusal !== null) {
            $this->setError($categoryRefusal);

            return false;
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = 0;
        if ($data['id'] > 0) { // existing record
            $currentId = intval($data['id']);
        } else { // new record: read the id from the Joomla model state
            $currentId = intval($this->getState($this->getName() . '.id'));
        }

        // Translations, the five scope pivots and the tax reference must be atomic: each setAssocsToDb
        // DELETEs then re-INSERTs, and a failure between them would leave zero scope
        // rows, which the engine reads as "applies EVERYWHERE". An unposted (empty)
        // multiselect omits its key, hence `?? []`.
        //
        // The language tables are extended BEFORE the transaction opens: DDL commits
        // implicitly, and inside it that would commit the half-rewritten scope.
        MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_shipments', primaryColumnName: 'id_shipment', data: $data);

        $db = $this->getDatabase();
        $db->transactionStart(true);

        try {
            // MULTILINGUAL: persist per-language translations (name, description).
            // Shipments are not URL-routed, so there is no alias slug.
            MultilingualHelper::saveMultilingualData(
                currentId:         $currentId,
                primaryColumnName: 'id_shipment',
                tableName:         '#__alfa_shipments',
                data:              $data,
                aliasFields:       [],
            );

            // Category / manufacturer / place / user / usergroup associations.
            $assignZeroIdIfDataEmpty = true;
            AlfaHelper::setAssocsToDb($currentId, $data['categories'] ?? [], '#__alfa_shipment_categories', 'shipment_id', 'category_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'] ?? [], '#__alfa_shipment_manufacturers', 'shipment_id', 'manufacturer_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['places'] ?? [], '#__alfa_shipment_places', 'shipment_id', 'place_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['users'] ?? [], '#__alfa_shipment_users', 'shipment_id', 'user_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['usergroups'] ?? [], '#__alfa_shipment_usergroups', 'shipment_id', 'usergroup_id', $assignZeroIdIfDataEmpty);

            // The method's shipping taxes. NO wildcard row when none is chosen:
            // no row means no shipping tax (there is no shop default).
            AlfaHelper::setAssocsToDb(
                $currentId,
                array_values(array_unique(array_filter(array_map('intval', (array) ($data['taxes'] ?? [])), static fn (int $id): bool => $id > 0))),
                '#__alfa_shipment_taxes',
                'shipment_id',
                'tax_id',
                false,
            );

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            throw $e;
        }

        return true;
    }

    /**
     * Method to delete one or more records.
     *
     * @param array &$pks An array of record primary keys.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function delete(&$pks)
    {
        // Permission first: the guards below read order counts and linked payment method names
        // and put them in their messages, which a user who may not delete must not be shown.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $pks = array_map('intval', (array) $pks);
        $db = $this->getDatabase();

        // GUARD: orders and shipment rows. The mirror of PaymentModel's guard:
        // this side had none at all, so a shipment method could be deleted out
        // from under every order that used it. The row is the only place the
        // plugin element lives; the orders freeze the NAME, which cannot boot
        // a plugin.
        foreach ($pks as $pk) {
            $orders = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_orders'))
                    ->where($db->quoteName('id_shipment_method') . ' = ' . $pk),
            )->loadResult();

            $shipments = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_order_shipments'))
                    ->where($db->quoteName('id_shipment_method') . ' = ' . $pk),
            )->loadResult();

            if ($orders > 0 || $shipments > 0) {
                Factory::getApplication()->enqueueMessage(
                    Text::sprintf('COM_ALFA_SHIPMENT_DELETE_HAS_ORDERS', $pk, $orders, $shipments),
                    'error',
                );

                return false;
            }
        }

        // GUARD: payment methods offered only with this shipping method.
        // Deleting it would silently narrow their allow-list, or empty it,
        // which hides the payment method everywhere. The link is removed on
        // the payment method, or the shipping method is unpublished instead.
        foreach ($pks as $pk) {
            $paymentIds = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('payment_id'))
                    ->from($db->quoteName('#__alfa_payment_shipments'))
                    ->where($db->quoteName('shipment_id') . ' = ' . $pk),
            )->loadColumn() ?: [];

            if ($paymentIds !== []) {
                $names = array_map(
                    static fn (int $paymentId): string => MultilingualHelper::getTranslatedValue(
                        id:                $paymentId,
                        tableName:         '#__alfa_payments',
                        primaryColumnName: 'id_payment',
                        field:             'name',
                    ) ?: ('#' . $paymentId),
                    array_map('intval', $paymentIds),
                );

                Factory::getApplication()->enqueueMessage(
                    Text::sprintf('COM_ALFA_SHIPMENT_DELETE_HAS_PAYMENTS', $pk, implode(', ', $names)),
                    'error',
                );

                return false;
            }
        }

        $result = parent::delete($pks);

        if ($result && !empty($pks)) {
            // MULTILINGUAL: remove the per-language rows for the deleted shipments.
            // Scope/link pivots of the deleted rows.
            foreach ([
                '#__alfa_shipment_categories',
                '#__alfa_shipment_manufacturers',
                '#__alfa_shipment_places',
                '#__alfa_shipment_usergroups',
                '#__alfa_shipment_users',
                '#__alfa_shipment_taxes',
            ] as $pivotTable) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName($pivotTable))
                        ->whereIn($db->quoteName('shipment_id'), $pks),
                )->execute();
            }

            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_shipment',
                tableName:         '#__alfa_shipments',
            );
        }

        return $result;
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param Table $table Table Object
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function prepareTable($table)
    {
        $user = $this->getCurrentUser();

        if ($table->id == 0 && empty($table->created_by)) {
            $table->created_by = $user->id;
        }

        $table->modified = Factory::getDate()->toSql();
        $table->modified_by = $user->id;
    }
}
