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
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\Traits\LocksMethodTypeTrait;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\DeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemShipments;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Table;
use RuntimeException;
use Throwable;

/**
 * Shipment model.
 *
 * @since  1.0.0
 */
class ShipmentModel extends AlfaAdminModel
{
    use LocksMethodTypeTrait;

    /**
     * @var string The entity a plugin targets with $context->targets('shipment').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'shipment';

    /*
     * CONTRACT COVERAGE NOTES:
     * - save() writes the row, the per-language translations, the five scope pivots
     *   and the taxes in one transaction, and onAlfaAdminAfterSave is announced at its
     *   commit, so it reads the method as saved.
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
        $idFromInput = RequestScalar::int($app->getInput(), 'id');

        // On edit order, we get ID of order from order.id state, but on save, we use data from input
        $id = (int) $this->getState($this->formName . '.id', $idFromInput);

        if (empty($form)) {
            return false;
        }

        // An order uses this method: its plugin is fixed.
        $this->lockMethodTypeField(form: $form, id: $id);

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

            // The method's image: one media row of origin 'shipment', as a manufacturer's.
            $item->medias = MediaHelper::getMediaData(origin: 'shipment', itemIDs: (int) ($item->id ?? 0));

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

            // The categories it does not deliver; none, never a wildcard row.
            $item->excluded_categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_excluded_categories', 'shipment_id', 'category_id');
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
        $rawData = $input->post->get('jform', [], 'array');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        // A save that takes a published method out of the published state: the products it leaves with no published
        // method on their list are counted before it, and said after it.
        $leaving = \array_key_exists('state', $data) && (int) ($data['id'] ?? 0) > 0
            ? $this->leavingPublished(ids: [(int) $data['id']], state: (int) $data['state'])
            : ['ids' => [], 'count' => 0];

        // A new method that leaves a colour out takes the one its form offers: the edit form always posts
        // both, a REST create need not, and neither column has a default of its own.
        $data = $this->withFormDefaults(data: $data, fields: ['color', 'bg_color']);

        // The plugin of an order's method cannot change.
        if (!$this->methodTypeChangeAllowed(data: $data)) {
            return false;
        }

        // The method plugin's settings are written from their group. A REST POST or PATCH that does not send the
        // group reads nothing: a shipping method being created starts with none, and an existing one keeps the
        // settings it has; empty settings are never written over stored ones over REST. On the
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
        ) ?? CategoryPickerPolicy::refusalForSave(
            recordId:     (int) ($data['id'] ?? 0),
            posted:       (array) ($data['excluded_categories'] ?? []),
            table:        '#__alfa_shipment_excluded_categories',
            recordColumn: 'shipment_id',
        );

        if ($categoryRefusal !== null) {
            $this->setError($categoryRefusal);

            return false;
        }

        // The method's image, as a manufacturer's: the uploads fetched apart from the form's data, and a
        // thumbnail the gallery names must be an image file of the media folders (MediaHelper::thumbnailRefusals()),
        // refused before anything is written, from the form and the API alike.
        $newDropped = $input->files->get('jform')['uploads'] ?? [];
        $thumbnailRefusals = MediaHelper::thumbnailRefusals(mediaData: (array) ($data['media'] ?? []));

        if ($thumbnailRefusals !== []) {
            $this->setError(implode(' ', $thumbnailRefusals));

            return false;
        }

        // Alfa's before-save is asked before the transaction. Joomla's onContentBeforeSave runs
        // inside it (parent::save()), so a write its listener makes is rolled back with a failed save;
        // onContentAfterSave and onAlfaAdminAfterSave wait for its commit.
        $asked = $this->alfaAskSave(data: $data);

        if ($asked === null) {
            return false;
        }

        // Translations, the five scope pivots and the tax reference must be atomic: each setAssocsToDb
        // DELETEs then re-INSERTs, and a failure between them would leave zero scope
        // rows, which the engine reads as "applies EVERYWHERE". An unposted (empty)
        // multiselect omits its key, hence `?? []`.
        //
        // The language tables are extended BEFORE the transaction opens: DDL commits
        // implicitly, and inside it that would commit the half-rewritten scope.
        MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_shipments', primaryColumnName: 'id_shipment', data: $data);

        // ONE TRANSACTION for the method's row as well: onAlfaAdminAfterSave and onContentAfterSave
        // wait for its commit, so a listener reads the shipping method as saved, translations and scope included, and a
        // failure anywhere leaves nothing of the save.
        $db = $this->alfaDriver();
        $db->transactionStart(true);

        try {
            $this->alfaSaveAsked = $asked;

            if (!parent::save($data)) {
                TransactionState::rollBackLevel(db: $db);

                return false;
            }

            $currentId = (int) ($data['id'] ?? 0) > 0 ? (int) $data['id'] : (int) $this->getState($this->getName() . '.id');

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

            // The categories the method does not deliver. NO wildcard row when none is chosen: no row excludes none.
            AlfaHelper::setAssocsToDb(
                $currentId,
                array_values(array_unique(array_filter(array_map('intval', (array) ($data['excluded_categories'] ?? [])), static fn (int $id): bool => $id > 0))),
                '#__alfa_shipment_excluded_categories',
                'shipment_id',
                'category_id',
                false,
            );

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

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            throw $e;
        }

        // The image's rows and files, after the method is saved: files are not transactional, as a manufacturer's.
        if (!empty($data['media'])) {
            MediaHelper::saveMedia(
                mediaData:      $data['media'],
                droppedMedia:   $newDropped,
                itemId:         $currentId,
                mediaOrigin:    'shipment',
                customFileName: $data['name_' . MultilingualHelper::getDefaultLanguageTag()] ?? '',
            );
        }

        $this->warnLeftWithout(leaving: $leaving);

        return true;
    }

    /**
     * Method to change the published state of one or more records. Unpublishing, archiving and trashing a shipping
     * method are allowed whatever the products' shipping lists say, and change no list: a product limited to methods
     * that are all unpublished keeps its restriction, ships with none meanwhile, and ships again when one is
     * published. The operator is told how many products the change leaves so, judged on the whole selection.
     *
     * @param array &$pks A list of the primary keys to change.
     * @param int $value The value of the published state.
     *
     * @return bool True on success.
     *
     * @since  2.3.0
     */
    public function publish(&$pks, $value = 1)
    {
        $leaving = $this->leavingPublished(ids: array_map('intval', (array) $pks), state: (int) $value);
        $result = parent::publish($pks, $value);

        if ($result) {
            $this->warnLeftWithout(leaving: $leaving);
        }

        return $result;
    }

    /**
     * The published methods among some that a change of state takes out of the published state, and the products
     * the change leaves with no published method on a list of theirs. Two indexed statements, read before the change.
     *
     * @param int[] $ids The methods the change is asked for.
     * @param int $state The state they are given.
     *
     * @return array{ids: int[], count: int}
     *
     * @since  2.3.0
     */
    private function leavingPublished(array $ids, int $state): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($state === 1 || $ids === []) {
            return ['ids' => [], 'count' => 0];
        }

        $db = $this->getDatabase();
        $published = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_shipments'))
                ->whereIn($db->quoteName('id'), $ids)
                ->where($db->quoteName('state') . ' = 1'),
        )->loadColumn() ?: []);

        return ['ids' => $published, 'count' => ItemShipments::countLeftWithoutPublished(db: $db, shipmentIds: $published)];
    }

    /**
     * Tell the operator how many products a change of state left with no published method on their shipping list,
     * with the link to them.
     *
     * @param array{ids: int[], count: int} $leaving What {@see self::leavingPublished()} read before the change.
     *
     * @since  2.3.0
     */
    private function warnLeftWithout(array $leaving): void
    {
        if ($leaving['count'] <= 0) {
            return;
        }

        Factory::getApplication()->enqueueMessage(
            trim(Text::plural('COM_ALFA_SHIPMENT_UNPUBLISH_LEAVES_NONE', $leaving['count']) . ' ' . $this->productsLink(ids: $leaving['ids'], list: ItemShipments::LIST_NONE)),
            'warning',
        );
    }

    /**
     * Method to delete one or more records: the methods' rows, their scope and link rows, their texts and their place
     * on the products' and variants' own shipping lists, in one transaction; their images after it.
     *
     * What the products' lists say decides, judged on the whole selection: no list names it, deleted; lists name it
     * beside other methods, deleted once the request confirms (the state delete.confirmed, set by the controllers
     * from the request) and the lists keep their other methods; a list names nothing but the selection, refused,
     * since a list with no row left would mean every method. A method an order or a parcel names is refused before
     * any of this, so an order keeps the method it was shipped with.
     *
     * @param array &$pks An array of record primary keys.
     *
     * @return bool True on success.
     *
     * @throws Throwable What ended the transaction; nothing of the delete is kept.
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
                // A model error, not a queued message: the back office prints it, and the REST delete answers
                // 409 with the sentence instead of 204 for a method that is still there.
                $this->setError(new DeleteRefusedException(Text::sprintf('COM_ALFA_SHIPMENT_DELETE_HAS_ORDERS', $pk, $orders, $shipments)));

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

                // The method names are the shop's own text: escaped for the back office, which prints messages
                // as HTML, and kept as written for the REST answer.
                $refusal = Text::sprintf('COM_ALFA_SHIPMENT_DELETE_HAS_PAYMENTS', $pk, implode(', ', $names));
                $this->setError(new DeleteRefusedException(htmlspecialchars($refusal, ENT_QUOTES, 'UTF-8'), $refusal));

                return false;
            }
        }

        // Every delete veto, Alfa's and Joomla's, before the transaction: no plugin code runs inside it.
        if (!$this->alfaAskBeforeDelete(pks: $pks)) {
            return false;
        }

        // ONE TRANSACTION: the judgement of the products' shipping lists, the methods' rows, their scope and link
        // pivots, their texts and their place on the lists. The methods and the list rows that name them are read
        // under locks held until the commit.
        $driver = $this->alfaDriver();
        $driver->transactionStart(true);
        $leftWithout = 0;

        try {
            // The methods' own rows first, locked: a product save that names one of them reads it under a shared
            // lock before it writes its list (ItemShipments::replace()), so it waits here and then finds the method
            // gone, or this delete waits for it and judges the list it committed. Never both at once.
            $driver->setQuery(
                'SELECT ' . $driver->quoteName('id') . ' FROM ' . $driver->quoteName('#__alfa_shipments')
                . ' WHERE ' . $driver->quoteName('id') . ' IN (' . implode(',', $pks ?: [0]) . ') FOR UPDATE',
            )->loadColumn();

            // What the delete of the WHOLE selection would do to the lists: two methods that are each other's only
            // alternative on a product leave it none when both are chosen.
            $naming = ItemShipments::countNaming(db: $driver, shipmentIds: $pks, lock: true);

            if ($naming > 0) {
                // A list that names nothing but the selection would have no row left, and a product with no row
                // ships with every method: a delete never turns a restriction into none. Refused, with the count.
                $only = ItemShipments::countLimitedTo(db: $driver, shipmentIds: $pks);

                if ($only > 0) {
                    throw $this->listRefusal(key: 'COM_ALFA_SHIPMENT_DELETE_ONLY_METHOD', count: $only, ids: $pks, list: ItemShipments::LIST_ONLY);
                }

                // Lists that name the selection beside other methods lose it and keep the others: done only when
                // the request confirms it, on every entry point.
                if (!$this->getState('delete.confirmed', false)) {
                    throw $this->listRefusal(key: 'COM_ALFA_SHIPMENT_DELETE_CONFIRM', count: $naming, ids: $pks, list: ItemShipments::LIST_ANY, confirm: true);
                }

                // Counted before the rows go: the products whose remaining methods are all unpublished.
                $leftWithout = ItemShipments::countLeftWithoutPublished(db: $driver, shipmentIds: $pks);
            }

            $this->alfaDeleteAsked = true;

            if (!parent::delete($pks)) {
                TransactionState::rollBackLevel(db: $driver, failure: new RuntimeException('The shipping methods were not deleted.'));

                return false;
            }

            if (!empty($pks)) {
                // Scope/link pivots of the deleted rows.
                foreach ([
                    '#__alfa_shipment_categories',
                    '#__alfa_shipment_excluded_categories',
                    '#__alfa_shipment_manufacturers',
                    '#__alfa_shipment_places',
                    '#__alfa_shipment_usergroups',
                    '#__alfa_shipment_users',
                    '#__alfa_shipment_taxes',
                ] as $pivotTable) {
                    $driver->setQuery(
                        $driver->getQuery(true)
                            ->delete($driver->quoteName($pivotTable))
                            ->whereIn($driver->quoteName('shipment_id'), $pks),
                    )->execute();
                }

                // Their place on the products' and variants' own shipping lists: every list judged above keeps
                // another method.
                ItemShipments::forgetMethods(db: $driver, shipmentIds: $pks);

                // MULTILINGUAL: remove the per-language rows for the deleted shipments.
                MultilingualHelper::deleteMultilingualData(
                    ids:               $pks,
                    primaryColumnName: 'id_shipment',
                    tableName:         '#__alfa_shipments',
                );
            }

            TransactionState::commitLevel(db: $driver);
        } catch (DeleteRefusedException $refused) {
            TransactionState::rollBackLevel(db: $driver, failure: $refused);
            $this->setError($refused);

            return false;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $driver, failure: $e);

            throw $e;
        }

        // The products that keep a list with no published method on it: their restriction stands, so they cannot be
        // shipped until one of their methods is published or they are given another. Said with the count.
        if ($leftWithout > 0) {
            Factory::getApplication()->enqueueMessage(
                trim(Text::plural('COM_ALFA_SHIPMENT_DELETE_LEAVES_NONE', $leftWithout) . ' ' . $this->productsLink(ids: [], list: ItemShipments::LIST_NONE)),
                'warning',
            );
        }

        // The methods' images (rows; files when media_full_deletion is on): files are not transactional, so after
        // the commit.
        if (!empty($pks)) {
            MediaHelper::deleteMediaForItems($pks, 'shipment');
        }

        return true;
    }

    /**
     * The refusal of a delete for what it would do to the products' shipping lists: the sentence with the count,
     * for the back office the link to the Products list filtered to those products and, when the delete only waits
     * for a confirmation, the button that confirms it; for the REST answer the sentence alone, with how to confirm.
     *
     * @param string $key The sentence's language key (its _1 and _MORE forms).
     * @param int $count The products.
     * @param int[] $ids The shipping methods chosen.
     * @param string $list The kind of list the products have (ItemShipments::LIST_*), for the link.
     * @param bool $confirm Whether the delete waits for a confirmation.
     *
     * @since  2.3.0
     */
    private function listRefusal(string $key, int $count, array $ids, string $list, bool $confirm = false): DeleteRefusedException
    {
        $sentence = Text::plural($key, $count);
        $html = trim(htmlspecialchars($sentence, ENT_QUOTES, 'UTF-8') . ' ' . $this->productsLink(ids: $ids, list: $list));

        // The button posts the back office's own delete task: printed there alone.
        if ($confirm && Factory::getApplication()->isClient('administrator')) {
            $html .= LayoutHelper::render('shipments.delete_confirm', ['ids' => $ids], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');
        }

        return new DeleteRefusedException($html, $sentence . ($confirm ? ' ' . Text::_('COM_ALFA_SHIPMENT_DELETE_CONFIRM_REST') : ''));
    }

    /**
     * The link to the Products list filtered to the products limited to shipping methods, in every state; '' outside
     * the back office.
     *
     * @param int[] $ids The shipping methods; none for every product whose list holds no published method.
     * @param string $list The kind of list (ItemShipments::LIST_*).
     *
     * @since  2.3.0
     */
    private function productsLink(array $ids, string $list): string
    {
        // A link of the back office: another client (the REST API, a command line run) is told the sentence alone.
        if (!Factory::getApplication()->isClient('administrator')) {
            return '';
        }

        $filter = ['state' => '*', 'shipment' => array_values(array_map('intval', $ids)), 'shipment_list' => $list];

        return HTMLHelper::_(
            'link',
            Route::_('index.php?option=com_alfa&view=items&' . http_build_query(['filter' => array_filter($filter, static fn ($value): bool => $value !== [] && $value !== '')])),
            Text::_('COM_ALFA_SHIPMENT_PRODUCTS_LINK'),
        );
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

    /**
     * {@inheritdoc}
     *
     * @since  2.2.0
     */
    protected function methodTypeReferences(): array
    {
        return ['id_shipment_method', '#__alfa_order_shipments'];
    }
}
