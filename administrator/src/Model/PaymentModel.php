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
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Throwable;

/**
 * Payment model.
 *
 * @since  1.0.0
 */
class PaymentModel extends AlfaAdminModel
{
    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.payment';

    protected $formName = 'payment';

    /**
     * The admin-contract entity of this screen.
     *
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save(): parent::save() runs mid-method, so the onAlfaAdminValidate/BeforeSave/
     *   AfterSave bracket fires BEFORE the per-language translations and the five scope
     *   pivots are persisted; AfterSave observers see the base row only.
     * - delete(): a batch blocked by stored payment references (mandates / recurring
     *   tokens) returns before parent::delete(), so the contract's delete bracket fires
     *   only when something actually reaches the table.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'payment';

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
     * @param array $data Data for the form.
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
        // Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_users/models/fields');

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

        // The plugin's settings are seeded from the same data the form was
        // loaded with: the kept post after a failed save, so nothing the admin
        // typed is replaced by the saved values; the saved row otherwise.
        $values = [];

        if ($loadData) {
            $formData = $this->loadFormData();
            $values = \is_array($formData) ? ($formData['paymentsparams'] ?? []) : ($formData->paymentsparams ?? []);
        }

        AlfaHelper::addPluginForm(form: $form, data: (array) $data, values: \is_array($values) ? $values : [], type: 'payments');

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
        //        exit;
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.payment.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = ($this->item === null ? $this->getItem() : $this->item);

            $data->paymentsparams = $data->params;
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
        // the SAME id is asked again (a bare cache returned the first item
        // for every subsequent id: wrong method on multi-method orders).
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
                    tableName:         '#__alfa_payments',
                    primaryColumnName: 'id_payment',
                    field:             'name',
                );
            }

            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_categories', 'payment_id', 'category_id');
            $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_manufacturers', 'payment_id', 'manufacturer_id');
            $item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_places', 'payment_id', 'place_id');

            $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_users', 'payment_id', 'user_id');
            $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_usergroups', 'payment_id', 'usergroup_id');
            $item->shipments = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_shipments', 'payment_id', 'shipment_id');
        }

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
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

        $data['params'] = json_encode($data['paymentsparams']);

        if (!parent::save($data)) {
            return false;
        }

        $currentId = 0;
        if ($data['id'] > 0) { //not a new
            $currentId = intval($data['id']);
        } else { // is new
            $currentId = intval($this->getState($this->getName() . '.id'));//get the id from setted joomla state
        }

        // Translations + the six scope pivots must be atomic: each setAssocsToDb
        // DELETEs then re-INSERTs, and a failure between them would leave zero scope
        // rows, which the engine reads as "applies EVERYWHERE". Wrapping them means a
        // mid-rewrite failure restores the prior scope intact.
        //
        // The language tables are extended BEFORE the transaction opens: DDL commits
        // implicitly, and inside it that would commit the half-rewritten scope.
        MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_payments', primaryColumnName: 'id_payment', data: $data);

        $db = $this->getDatabase();
        $db->transactionStart(true);

        try {
            // MULTILINGUAL: persist per-language translations (name, description).
            // Payments are not URL-routed, so there is no alias slug.
            MultilingualHelper::saveMultilingualData(
                currentId:         $currentId,
                primaryColumnName: 'id_payment',
                tableName:         '#__alfa_payments',
                data:              $data,
                aliasFields:       [],
            );

            //Category/manufacturer etc associations
            $assignZeroIdIfDataEmpty = true;
            AlfaHelper::setAssocsToDb($currentId, $data['categories'] ?? [], '#__alfa_payment_categories', 'payment_id', 'category_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'] ?? [], '#__alfa_payment_manufacturers', 'payment_id', 'manufacturer_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['places'] ?? [], '#__alfa_payment_places', 'payment_id', 'place_id', $assignZeroIdIfDataEmpty);

            AlfaHelper::setAssocsToDb($currentId, $data['users'] ?? [], '#__alfa_payment_users', 'payment_id', 'user_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['usergroups'] ?? [], '#__alfa_payment_usergroups', 'payment_id', 'usergroup_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['shipments'] ?? [], '#__alfa_payment_shipments', 'payment_id', 'shipment_id', $assignZeroIdIfDataEmpty);

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            throw $e;
        }

        return true;
        // return parent::save($data);
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
        // Permission first: the guards below read order and reference counts and name them in
        // their messages, which a user who may not delete must not be shown.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $pks = array_map('intval', (array) $pks);
        $db = $this->getDatabase();

        // GUARD: stored payment references (mandates / recurring tokens)
        // charge through this method: losing it would strand them.
        foreach ($pks as $pk) {
            $references = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_payment_references'))
                    ->where($db->quoteName('id_payment_method') . ' = ' . $pk),
            )->loadResult();

            if ($references > 0) {
                Factory::getApplication()->enqueueMessage(
                    Text::sprintf('COM_ALFA_PAYMENT_DELETE_HAS_REFERENCES', $pk, $references),
                    'error',
                );

                return false;
            }
        }

        // GUARD: orders and ledger rows. A method row is the ONLY place the
        // plugin element lives: the orders freeze the method's NAME, and a
        // name cannot boot a plugin. Delete the row and every order that used
        // it loses the ability to refund, capture or even name its gateway
        // correctly. Counted per method so the message can say which and how
        // many, the same shape the references guard above already uses.
        foreach ($pks as $pk) {
            $orders = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_orders'))
                    ->where($db->quoteName('id_payment_method') . ' = ' . $pk),
            )->loadResult();

            $payments = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_order_payments'))
                    ->where($db->quoteName('id_payment_method') . ' = ' . $pk),
            )->loadResult();

            if ($orders > 0 || $payments > 0) {
                Factory::getApplication()->enqueueMessage(
                    Text::sprintf('COM_ALFA_PAYMENT_DELETE_HAS_ORDERS', $pk, $orders, $payments),
                    'error',
                );

                return false;
            }
        }

        $result = parent::delete($pks);

        if ($result && !empty($pks)) {
            // Scope pivots of the deleted methods.
            foreach (['#__alfa_payment_categories', '#__alfa_payment_manufacturers', '#__alfa_payment_places', '#__alfa_payment_usergroups', '#__alfa_payment_users', '#__alfa_payment_shipments'] as $pivotTable) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName($pivotTable))
                        ->whereIn($db->quoteName('payment_id'), $pks),
                )->execute();
            }

            // MULTILINGUAL: remove the per-language rows for the deleted payments.
            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_payment',
                tableName:         '#__alfa_payments',
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
