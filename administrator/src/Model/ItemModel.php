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

use Alfa\Component\Alfa\Administrator\Event\Items\ItemAfterSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Items\ItemPrepareFormEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\FieldParams;
use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\VisibilityNotifier;
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Download\Storage;
use Alfa\Component\Alfa\Administrator\Service\Inventory\BackorderPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityNotice;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryProjectionMismatchException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryReservedFloorException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryTrackingException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitRetirementException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Table;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Item model.
 *
 * Price index sync hooks
 * The price index (#__alfa_items_price_index) must be kept in sync whenever
 * an item's published state or pricing data changes. Three hooks are added:
 *
 *   save()          - re-indexes this item after ALL associated data is committed
 *                     (prices, categories, manufacturers, users, usergroups).
 *                     Call order inside save() is critical: syncItem() is LAST.
 *
 *   publish()       - if state becomes 1 (published): re-index the item so it
 *                     appears in the filter with correct prices.
 *                     If state is anything else (0=unpublish, -2=trash): remove
 *                     the item's index rows so it disappears from the filter.
 *
 *   delete()        - remove the item's index rows.
 *                     Note: the FK ON DELETE CASCADE on the index table already
 *                     handles hard deletes automatically. This call is an explicit
 *                     safety net for soft-delete / trash scenarios.
 *
 * All sync operations are wrapped in try/catch and are NON-FATAL: a sync
 * failure is logged as a warning but never blocks the admin save operation.
 *
 * @since  1.0.0
 */
class ItemModel extends AlfaAdminModel
{
    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.item';

    protected $formName = 'item';

    /**
     * The admin-contract entity of this screen.
     *
     * ORDERING FACTS a contract handler must know (decided at migration):
     * - onAlfaAdminBeforeSave fires AFTER this model's own pre-parent steps, which strip
     *   stock, available_quantity, inventory_stock, backorder and min_qty out of $data:
     *   a handler neither sees nor overrides those; they belong to the inventory domain.
     *   The projection columns, and the tracking setting of an existing item, are also
     *   held out of every row write by ItemTable::store, so no handler or listener can
     *   write them. A save that changes the tracking setting leaves manage_stock out of
     *   $data too: the inventory service writes the change after the row save, together
     *   with the opening stock of a switch to tracked. A save that moves the product into
     *   or out of the archive leaves state out of $data (a new product is written
     *   unpublished): publish() makes the move once the save is done.
     * - onAlfaAdminAfterSave fires right after the ROW write, before multilingual, media,
     *   prices, associations, item-fields, combinations, stock and projections. A handler
     *   that needs the FINISHED item listens to onItemAfterSave (the domain event at the
     *   end of save()) instead.
     * - save() re-invokes getForm() to merge multilingual data, so the form/prepare-data
     *   pair dispatches a second time during a save request; handlers must be idempotent.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'item';

    /**
     * How many times publish() judges a selection that crosses the archive when
     * another request keeps moving one of its products into or out of the archive
     * meanwhile.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const ARCHIVE_ATTEMPTS = 3;

    /**
     * The product's quantity rules, stored as exact decimals: the smallest
     * quantity sold, the step it is sold in, and the largest quantity one cart
     * line may hold.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    private const QUANTITY_RULES = ['quantity_min', 'quantity_step', 'quantity_max'];

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

    protected $item = null;
    protected $batch_copymove = false;

    protected $batch_commands = [
        'category_id' => 'batchCategory',
        'manufacturer_id' => 'batchManufacturer',
        'user_id' => 'batchUser',
        'usergroup_id' => 'batchUserGroup',
    ];

    /**
     * Batch command: set the allowed users for each selected item in the
     * #__alfa_items_users join table.
     *
     * @param array $value The user ids to assign (single empty value = no change).
     * @param array $pks The item ids being processed.
     * @param array $contexts The context for each item.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    protected function batchUser($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_USERS_NOT_CHANGED'), 'info');

            return true;
        }

        foreach ($pks as $id) {
            AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_users', 'item_id', 'user_id');
        }

        // ONE event for the whole batch, not one per item: a consumer holding a
        // materialised copy wants the set so it can bulk-update.
        VisibilityNotifier::changed(itemIds: $pks);

        $app->enqueueMessage(Text::_('COM_ALFA_USERS_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    /**
     * Batch command: set the allowed user groups for each selected item in the
     * #__alfa_items_usergroups join table.
     *
     * @param array $value The usergroup ids to assign (single empty value = no change).
     * @param array $pks The item ids being processed.
     * @param array $contexts The context for each item.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    protected function batchUserGroup($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_USERGROUP_NOT_CHANGED'), 'info');

            return true;
        }

        foreach ($pks as $id) {
            AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_usergroups', 'item_id', 'usergroup_id');
        }

        VisibilityNotifier::changed(itemIds: $pks);

        $app->enqueueMessage(Text::_('COM_ALFA_USERGROUP_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    /**
     * Batch command: set the manufacturers for each selected item in the
     * #__alfa_items_manufacturers join table.
     *
     * @param array $value The manufacturer ids to assign (single empty value = no change).
     * @param array $pks The item ids being processed.
     * @param array $contexts The context for each item.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    protected function batchManufacturer($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_MANUFACTURERS_NOT_CHANGED'), 'info');

            return true;
        }

        foreach ($pks as $id) {
            AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');
        }

        // Manufacturer ids and per-language names are copied into every item's
        // search document, so the set membership change must be projected.
        (new ProjectionManager())->syncItems($pks);

        $app->enqueueMessage(Text::_('COM_ALFA_MANUFACTURERS_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    /**
     * Batch command: set the categories for each selected item in the
     * #__alfa_items_categories join table, then clear the cache for every
     * category affected (both the previously assigned and the newly assigned ones).
     *
     * @param array $value The category ids to assign (single empty value = no change).
     * @param array $pks The item ids being processed.
     * @param array $contexts The context for each item.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    protected function batchCategory($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_CATEGORIES_NOT_CHANGED'), 'info');

            return true;
        }

        // A category newly given to a product must be available (CategoryPickerPolicy): refused for the whole
        // batch, before anything is written; a category a product already holds is kept.
        $tree = CategoryPickerPolicy::tree();
        $refused = [];

        foreach ($pks as $id) {
            $held = AlfaHelper::getAssocsFromDb($id, '#__alfa_items_categories', 'item_id', 'category_id');
            $refused += CategoryPickerPolicy::refusedNew(posted: (array) $value, stored: (array) $held, tree: $tree);
        }

        if ($refused !== []) {
            $this->setError(CategoryPickerPolicy::refusal(refused: $refused));

            return false;
        }

        $affectedCategories = [];
        foreach ($pks as $id) {
            $oldCats = AlfaHelper::getAssocsFromDb($id, '#__alfa_items_categories', 'item_id', 'category_id');
            $affectedCategories = array_merge($affectedCategories, array_map('intval', $oldCats));
            AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_categories', 'item_id', 'category_id');
        }

        // Clear cache for all affected categories (old + new)
        $affectedCategories = array_unique(array_merge($affectedCategories, array_map('intval', $value)));
        foreach ($affectedCategories as $catId) {
            if ($catId > 0) {
                CategoryHelper::clearCache($catId);
            }
        }

        // The item's categories feed its search document facets AND, through the
        // default category, its inherited audience. One call for the whole batch.
        (new ProjectionManager())->syncItems($pks);
        VisibilityNotifier::changed(itemIds: $pks);

        $app->enqueueMessage(Text::_('COM_ALFA_CATEGORIES_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    /**
     * Build the item edit form (com_alfa.item).
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        // $this->formName is item
        // Get the form.
        $form = $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        $this->applyLowStockVisibility(form: $form);
        $this->applyStockActionGlobalLabel(form: $form);
        $this->showQuantityRulesExactly(form: $form);

        // Feature plugins (`alfa` group, imported at component boot) inject
        // their fieldsets here; a new fieldset automatically becomes its own
        // tab in the item editor. No listeners = the form as-is (core stands
        // alone). Never blocks form building.
        try {
            $event = new ItemPrepareFormEvent('onItemPrepareForm', [
                'form' => $form,
                'data' => $data,
                'itemId' => (int) ($data['id'] ?? $this->getState($this->getName() . '.id', 0)),
            ]);

            Factory::getApplication()->getDispatcher()->dispatch('onItemPrepareForm', $event);
        } catch (Throwable $e) {
            Log::add(
                entry:    'onItemPrepareForm listener failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                priority: Log::ERROR,
                category: 'com_alfa',
            );
        }

        return $form;
    }

    /**
     * Reconciles the low-stock fields' `showon` with what "empty" now means.
     *
     * `stock_low` carries `useglobal="true"`, so an empty item value reads
     * "inherit the shop-wide threshold", NOT "this product has none". The two
     * dependent fields (the notify switch and the low-stock message) are
     * gated by `showon="stock_low!:"`, which sees only the raw input and so
     * hid them on precisely the products that DO have an active threshold:
     * every product inheriting a configured global. The merchant could reach
     * them only by retyping the global into the field, which then broke the
     * inheritance it was meant to express.
     *
     * The effective threshold is a server-side fact, so the condition is
     * decided here: with a global configured, low stock is active unless the
     * merchant overrides it, and only the untracked-stock gate remains; with
     * no global, empty genuinely means "none" and the threshold gate stands
     * alongside it.
     *
     * @param Form $form The item form to adjust in place.
     *
     *
     * @since  2.0.0
     */
    private function applyLowStockVisibility(Form $form): void
    {
        $globalThreshold = (float) ComponentHelper::getParams('com_alfa')->get('stock_low', 0);

        // Untracked stock can never cross a threshold, so the whole cluster
        // follows manage_stock exactly like backorders and safety stock do.
        $condition = $globalThreshold > 0 ? 'manage_stock:1' : 'manage_stock:1[AND]stock_low!:';

        foreach (['low_stock_notify', 'stock_low_message'] as $dependent) {
            $form->setFieldAttribute(name: $dependent, attribute: 'showon', value: $condition);
        }
    }

    /**
     * Names the shop-wide default inside `stock_action`'s inherit option.
     *
     * `stock_action` cannot use Joomla's `useglobal`: it already owns an
     * inherit sentinel of its own ({@see StockVisibilityPolicy::ACTION_INHERIT},
     * `-1`), and `useglobal` would prepend a SECOND option (value `''`) with
     * the very same meaning. Worse, that blank cast to the `tinyint` column
     * lands as `0` = "No action", a different behaviour written silently.
     *
     * So the sentinel keeps its value and borrows only the wording: the option
     * is relabelled with Joomla's own "Use Global (x)" string, naming whatever
     * the shop is currently set to. One option, one meaning, no schema change.
     *
     * Deliberately applied to the model's form instance, not the XML file: the
     * combination Studio loads that file separately, and there `-1` means
     * "follow the ITEM", not the shop; the same label would be a lie.
     *
     * @param Form $form The item form to adjust in place.
     *
     *
     * @since  2.0.0
     */
    private function applyStockActionGlobalLabel(Form $form): void
    {
        $xml = $form->getXml();

        if (!$xml) {
            return;
        }

        $inherit = $xml->xpath('//field[@name="stock_action"]/option[@value="' . StockVisibilityPolicy::ACTION_INHERIT . '"]');

        if (!$inherit) {
            return;
        }

        $global = (new StockVisibilityPolicy())->globalAction();
        $named = $xml->xpath('//field[@name="stock_action"]/option[@value="' . $global . '"]');

        if (!$named) {
            return;
        }

        $inherit[0][0] = Text::sprintf('JGLOBAL_USE_GLOBAL_VALUE', Text::_((string) $named[0]));
    }

    /**
     * Shows the quantity rules bound to the form exactly, without trailing zeros.
     *
     * The rules are stored as exact decimals with eight places, and the item keeps
     * them that way for every other reader. On the form, a bound value the exact
     * parser accepts is shown in its shortest exact form ('2.5' for 2.50000000); a
     * value it refuses, such as an entry kept for correction after a refused save,
     * is shown as it was typed.
     *
     * @param Form $form The item form, with its data bound.
     *
     *
     * @since  2.0.0
     */
    private function showQuantityRulesExactly(Form $form): void
    {
        foreach (self::QUANTITY_RULES as $rule) {
            $exact = QuantityDecimal::parse(value: $form->getValue(name: $rule));

            if ($exact !== null) {
                $form->setValue(name: $rule, value: QuantityDecimal::display(a: $exact));
            }
        }
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
        $data = \Alfa\Component\Alfa\Administrator\Helper\KeptPost::forRecord(
            stateKey: 'com_alfa.edit.item.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = ($this->item === null ? $this->getItem() : $this->item);
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
        $pk = (!empty($pk)) ? $pk : (int) $this->getState($this->getName() . '.id');

        if (isset($this->item[$pk])) {
            return $this->item[$pk];
        }

        if ($item = parent::getItem($pk)) {
            $meta_data = json_decode($item->meta_data ?? '{}');
            $item->robots = $meta_data->robots ?? '';

            // Decoded for form binding (the layouts field group); Table re-encodes on save.
            $item->layouts = json_decode($item->layouts ?: '{}', true) ?: [];

            $item->prices = $this->getPrices($item->id);

            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_categories', 'item_id', 'category_id');
            $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');

            $item->allowedUsers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_users', 'item_id', 'user_id');
            $item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_usergroups', 'item_id', 'usergroup_id');

            // get media origin (cat, man, item...)
            $item->medias = MediaHelper::getMediaData(
                origin: $this->name,
                itemIDs: $item->id,
            );
        }

        $this->item[$pk] = $item;

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * Extends the parent save() to also maintain the price index.
     *
     * CALL ORDER: the price index sync MUST be the very last step because
     * it reads all associated data committed in the steps before it:
     *
     *   1. parent::save()           - commits the #__alfa_items row
     *   2. setPrices()              - commits #__alfa_items_prices rows
     *   3. setAssocsToDb() x 4     - commits categories, manufacturers, users, usergroups
     *   4. PriceIndexSyncService    - reads everything above, writes the index
     *
     * STOCK. The row never carries stock, nor an existing product's tracking
     * setting: every stock, tracking and combination refusal that can be known is
     * returned, in the model error, before the row is written; a change of the
     * tracking setting is written by the inventory service right after the row
     * save, with the opening stock of a switch to tracked; changed stock figures
     * of a product that tracks stock are applied through the inventory service,
     * all locations or none, after the combinations are saved.
     *
     * ARCHIVE. The row never carries a state that moves the product into the
     * archive: an existing product keeps its stored state and a new one is written
     * unpublished. A product that still holds stock or claims, or whose figures
     * disagree with its stock records, is refused before the row is written, as
     * the list's Archive is. Otherwise publish() makes the move after everything
     * else, projections included, so the inventory service judges it under its own
     * locks with what this save changed; a refusal there leaves the product where
     * it was while the rest of the save stands, and publish() reports it as an
     * error. A save that posts any other state for an archived product is refused
     * whole: an archived product returns to the active catalogue only through
     * restoreArchived(). The row write itself never crosses the archive either
     * way (ItemTable::store()), so an archive or a restore that commits while the
     * save runs stands.
     *
     * QUANTITY RULES. The minimum, step and maximum are exact decimals. A posted
     * rule is parsed exactly and normalised (an empty or zero minimum is 1, an
     * empty step is 1, an empty or zero maximum is no limit); a rule that is not a
     * number within the quantity precision, a negative rule, a step of zero, a
     * minimum above the maximum, and a set of rules that leaves no whole quantity
     * to sell are refused before anything is written. Only a set this save changes
     * is judged, together with every current combination that inherits a changed
     * rule. A change of the quantity rules, the backorder action or the safety
     * stock of an existing product is recorded by the inventory service, so every
     * cached stock verdict is rebuilt.
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
        $input = $app->getInput();
        $table = $this->getTable();

        // Recover the per-language flat keys (e.g. name_en_gb) and other undeclared
        // keys the form filter drops, without overwriting any validated field or
        // resurrecting the housekeeping fields the form unsets. Recovered
        // per-language values are safehtml-cleaned like their base field.
        $rawData = $input->post->get('jform', [], 'raw');
        $form = $this->getForm(data: $data, loadData: false) ?: null;
        $data = MultilingualHelper::mergeValidatedFormData(validated: $data, raw: $rawData, form: $form);

        $pk = (int) ($data['id'] ?? (int) $this->getState($this->getName() . '.id'));

        // STOCK INTENT. The stock field never writes a column: a posted figure is an
        // instruction to the inventory service, applied after the row save. A shop
        // stocking one location posts the plain 'stock' figure, otherwise the
        // per-location breakdown. An empty figure is no figure, and a negative one
        // is taken as typed.
        $stockProvided = \array_key_exists('stock', $data);
        $stockRaw = $stockProvided && \is_scalar($data['stock']) ? trim((string) $data['stock']) : '';
        $stockRequested = $stockRaw !== '' && is_numeric($stockRaw) ? (float) $stockRaw : null;
        $locationStock = null;

        if (isset($rawData['inventory_stock']) && \is_array($rawData['inventory_stock'])) {
            $locationStock = $rawData['inventory_stock'];
        }

        // THE FIGURE THE OPERATOR ACTUALLY SAW, carried back by the field, keyed by
        // location id with 0 for the single-figure rendering. A figure is a command
        // only when it differs from what was rendered, so a form left open while
        // stock sells cannot put the sold units back. A programmatic save (API, CLI)
        // renders nothing; its figures are compared with the stored figures instead.
        $stockBaseline = null;

        if (isset($rawData['inventory_stock_baseline']) && \is_array($rawData['inventory_stock_baseline'])) {
            $stockBaseline = $rawData['inventory_stock_baseline'];
        }

        unset(
            $data['stock'],
            $data['available_quantity'],
            $data['inventory_stock'],
            $data['inventory_stock_baseline'],
        );

        // Inventory POLICY columns (config, not quantities), written explicitly
        // after the save. The backorder action is stored in the one normalised
        // form every reader resolves: an action, '' to follow the shop, or NULL for
        // anything else. An empty safety stock persists as NULL (the global default).
        $policyValues = null;

        if (\array_key_exists('backorder', $data) || \array_key_exists('min_qty', $data)) {
            $policyValues = [
                'backorder' => BackorderPolicy::normaliseStoredValue(value: $data['backorder'] ?? ''),
                'min_qty' => trim((string) ($data['min_qty'] ?? '')) === '' ? null : (float) $data['min_qty'],
            ];
        }

        unset($data['backorder'], $data['min_qty']);

        // Checking valid height/width/depth/weight.
        if ($data['width'] < 0) {
            $data['width'] = 0;
        }
        if ($data['height'] < 0) {
            $data['height'] = 0;
        }
        if ($data['depth'] < 0) {
            $data['depth'] = 0;
        }
        if ($data['weight'] < 0) {
            $data['weight'] = 0;
        }

        $data['meta_data'] = json_encode(
            ['robots' => $data['robots'] ?? ''],
        );

        $newDropped = $input->files->get('jform')['uploads'] ?? [];

        // A NEW category of the product, or a new default category, must be available (not archived or trashed,
        // not inside an archived or trashed category); a category the product already holds is kept.
        $db = $this->getDatabase();
        $storedDefault = $pk > 0
            ? (int) $db->setQuery(
                'SELECT ' . $db->quoteName('id_category_default') . ' FROM ' . $db->quoteName('#__alfa_items')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $pk,
            )->loadResult()
            : 0;
        $categoryRefusal = CategoryPickerPolicy::refusalForSave(
            recordId:     $pk,
            posted:       array_merge((array) ($data['categories'] ?? []), [(int) ($data['id_category_default'] ?? 0)]),
            table:        '#__alfa_items_categories',
            recordColumn: 'item_id',
            alsoHeld:     [$storedDefault],
        );

        if ($categoryRefusal !== null) {
            return $this->refuseSave(messages: [$categoryRefusal]);
        }

        // PRE-FLIGHT: every stock, tracking and combination refusal that can be
        // known before the row is written refuses the whole save up front, so a
        // refused request writes nothing at all.
        $stockPlan = $this->stockSavePlan(
            pk:             $pk,
            data:           $data,
            stockProvided:  $stockProvided,
            stockRaw:       $stockRaw,
            stockRequested: $stockRequested,
            locationStock:  $locationStock,
            stockBaseline:  $stockBaseline,
        );

        if ($stockPlan['refusals'] !== []) {
            return $this->refuseSave(messages: $stockPlan['refusals']);
        }

        // The posted quantity rules, parsed exactly and normalised in $data.
        $ruleRefusals = $this->quantityRuleRefusals(pk: $pk, data: $data, form: $form);

        if ($ruleRefusals !== []) {
            return $this->refuseSave(messages: $ruleRefusals);
        }

        // A move into the archive is made by publish() at the end of the save, never
        // by the row write; an archive the stock refuses is refused here. An
        // archived product leaves the archive only through restoreArchived().
        $archiveMove = $this->postedArchiveMove(pk: $pk, data: $data);

        if ($archiveMove !== null && $archiveMove !== CombinationState::ARCHIVED) {
            return $this->refuseSave(messages: [Text::sprintf('COM_ALFA_ITEM_ARCHIVED_RESTORE_FIRST', $pk)]);
        }

        if ($archiveMove === CombinationState::ARCHIVED && $pk > 0) {
            $blockers = (new InventoryService())->retirementBlockers(itemId: $pk, combinationIds: null);

            if ($blockers !== []) {
                return $this->refuseSave(messages: [$this->stockHeldMessage(
                    key:      'COM_ALFA_ITEM_ARCHIVE_STOCK_HELD',
                    itemId:   $pk,
                    blockers: $blockers,
                )]);
            }

            if (!$this->projectionsAgree(itemId: $pk)) {
                return $this->refuseSave(messages: [Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE')]);
            }
        }

        if ($archiveMove !== null) {
            if ($pk > 0) {
                unset($data['state']);
            } else {
                $data['state'] = CombinationState::UNPUBLISHED;
            }
        }

        // A change of the tracking setting is written by the inventory service after
        // the row save, with the opening stock of a switch to tracked and the
        // operation that records it (the row write never carries the setting of an
        // existing product: ItemTable::store holds it back).
        if ($stockPlan['flagChange']) {
            unset($data['manage_stock']);
        }

        // The sale rules as stored before the save, to tell whether it changed them.
        $saleRulesBefore = $pk > 0 ? $this->saleRuleFacts(itemId: $pk) : null;

        // URL ALIASES, judged before anything is written: a save that would put this product
        // on another one's address in any language is refused; an address it already shared
        // is kept and said after the save. A copy takes its own aliases, generated.
        if ($input->get('task') === 'save2copy') {
            $data = MultilingualHelper::releaseCopiedAliases(
                data:              $data,
                tableName:         '#__alfa_items',
                primaryColumnName: 'id_item',
                originalId:        $input->getInt('id'),
                aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_items'],
            );
        }

        $aliasClashes = MultilingualHelper::aliasClashes(
            tableName:         '#__alfa_items',
            primaryColumnName: 'id_item',
            currentId:         $pk,
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_items'],
        );

        if ($aliasClashes['refuse'] !== []) {
            return $this->refuseSave(messages: $aliasClashes['refuse']);
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $pk > 0 ? $pk : (int) $this->getState($this->getName() . '.id'); //get the id from joomla state

        if ($stockPlan['flagChange']) {
            $trackingChange = null;
            $trackingError = null;

            try {
                $trackingChange = (new InventoryService())->changeTracking(
                    itemId:         $currentId,
                    combinationId:  null,
                    value:          $stockPlan['flag'],
                    openingTargets: $stockPlan['transition'] === 'on' ? $stockPlan['openings'] : null,
                    context:        InventoryContext::ambient(),
                );
            } catch (Throwable $refusal) {
                $trackingError = $refusal;
            }

            $this->reportTrackingChange(itemId: $currentId, result: $trackingChange, error: $trackingError);
        }

        // MULTILINGUAL: persist per-language translations (name, alias, short_desc,
        // full_desc, meta_title, meta_desc). A blank alias slug is generated,
        // sanitised and made globally unique (an item can belong to many categories).
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_item',
            tableName:         '#__alfa_items',
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_items'],
        );

        foreach ($aliasClashes['warn'] as $warning) {
            $app->enqueueMessage($warning, 'warning');
        }

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

        $currentId = $pk > 0 ? $pk : (int) $this->getState($this->getName() . '.id'); //get the id from joomla state

        // AUTO SET DEFAULT CATEGORY ID AND CATEGORIES ARRAY
        // Check if $categoryIdDefault is set, if not set it to the first category
        $categoryIdDefault = $data['id_category_default'];
        $categories = $data['categories'] ?? [];

        if (!isset($categoryIdDefault) && !empty($categories)) {
            $categoryIdDefault = $categories[0]; // assuming categories are indexed as an array
        }

        // Check if $defaultCategoryId exists in $data['categories'], if not, add it
        if (!in_array($categoryIdDefault, $categories)) {
            $data['categories'][] = $categoryIdDefault;
        }
        // END OF AUTO SET DEFAULT CATEGORY ID AND CATEGORIES ARRAY

        // Step 2: save prices
        // ?? null, not ?? []: absent must stay a no-op (setPrices bails on a
        // non-array), because [] means "delete every price row". Without the
        // coalesce a body that omits prices raised an undefined-key warning
        // straight into the API's JSON response.
        $this->setPrices($currentId, $data['prices'] ?? null);

        // Step 3: save all associations
        // Get old categories BEFORE overwriting so we can clear their cache too
        $oldCategories = AlfaHelper::getAssocsFromDb($currentId, '#__alfa_items_categories', 'item_id', 'category_id');
        $newCategories = $data['categories'] ?? [];

        AlfaHelper::setAssocsToDb($currentId, $newCategories, '#__alfa_items_categories', 'item_id', 'category_id');
        AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'] ?? [], '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');

        // Clear category cache for all affected categories (old + new)
        $affectedCategories = array_unique(array_merge(
            array_map('intval', $oldCategories),
            array_map('intval', $newCategories),
        ));
        foreach ($affectedCategories as $catId) {
            if ($catId > 0) {
                CategoryHelper::clearCache($catId);
            }
        }

        AlfaHelper::setAssocsToDb($currentId, $data['allowedUsers'] ?? [], '#__alfa_items_users', 'item_id', 'user_id');
        AlfaHelper::setAssocsToDb($currentId, $data['allowedUserGroups'] ?? [], '#__alfa_items_usergroups', 'item_id', 'usergroup_id');

        // Anyone holding a materialised copy of catalogue visibility needs to know.
        VisibilityNotifier::changed(itemIds: [$currentId]);

        // ITEM FIELDS: no wizard step; option values are ADDED via the
        // picker popup (item.addOptions), informational values save via
        // their popup (item.saveFieldSettings), combinations via the
        // explicit generator. The item save persists the LIST edits:
        // option deltas + deferred option removals (variants-grid mechanics).
        ItemFieldsAdminHelper::saveItemOptions(
            itemId:          $currentId,
            payload:         (array) ($data['options'] ?? []),
            deletedValueIds: (string) ($data['deleted_option_value_ids'] ?? ''),
        );
        ItemFieldsAdminHelper::deleteItemInfoValues(
            itemId:          $currentId,
            deletedValueIds: (string) ($data['deleted_info_value_ids'] ?? ''),
        );
        ItemFieldsAdminHelper::saveItemInfoOrder(
            itemId:          $currentId,
            orderedValueIds: (array) ($data['info_values'] ?? []),
        );
        // Per-item GROUP order: the option/info field groups, dragged by header.
        ItemFieldsAdminHelper::saveItemFieldOrder(
            itemId:          $currentId,
            orderedFieldIds: (array) ($data['field_order'] ?? []),
        );
        // Per-item REQUIRED overrides ('' = inherit the field's global flag).
        ItemFieldsAdminHelper::saveItemFieldRequired(
            itemId:  $currentId,
            payload: (array) ($data['field_required'] ?? []),
        );

        // DOWNLOADS: persist the Downloads-tab metadata grid (title, limits,
        // variant binding, state); file bytes stay on the AJAX tasks
        // (upload/replace/delete need multipart + immediate GC).
        Entitlements::saveItemDownloadsMeta(
            itemId:  $currentId,
            payload: (array) ($data['item_downloads'] ?? []),
        );

        // Field plugin config is NOT saved here: it is written through the
        // field's row actions (the configure popup -> FieldParams), so the
        // item save stays out of plugin-owned config entirely.

        // COMBINATIONS: persist the grid edits first (they reference rows from
        // before this save). Combinations are NOT auto-generated here; the
        // admin generates them explicitly from the grid's Generate panel
        // (additive by design), so no orphan handling is needed.
        // A refusal here means the stock or a variant's price rows changed after
        // the pre-flight: the grid edits roll back as a whole while the product
        // row stands, so the save reports it as its own error and carries on.
        // Every refusal the grid throws is a complete sentence, so it follows the
        // saved notice whole.
        try {
            ItemFieldsAdminHelper::saveCombinations(
                itemId:                 $currentId,
                payload:                (array) ($data['combinations'] ?? []),
                defaultId:              (string) ($data['combinations_default'] ?? ''),
                deletedCombinationIds:  (string) ($data['deleted_combination_ids'] ?? ''),
            );
        } catch (RuntimeException $combinationError) {
            Log::add(
                entry:    'Item ' . $currentId . ' saved, but its combination changes were refused: '
                          . $combinationError->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.inventory',
            );

            $app->enqueueMessage(
                msg:  Text::sprintf('COM_ALFA_ITEM_SAVED_VARIANTS_NOT_CHANGED', $combinationError->getMessage()),
                type: 'error',
            );
        }

        // STOCK ADAPTER (see the extraction above): converge the item-level
        // unit to the changed figures through the kernel. It runs only for a
        // product that tracks stock as STORED after the row save, never in a save
        // that switched tracking (the switch booked its own opening stock), and
        // never for a product with combinations, which has no item-level unit.
        // Archived combinations are kept only for order history, so a product
        // whose combinations are all archived is a plain product again.
        $db = $this->getDatabase();
        $hasCombinations = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->where($db->quoteName('item_id') . ' = ' . $currentId)
                ->where(CombinationState::currentSql(column: $db->quoteName('state'))),
        )->loadResult() > 0;
        $storedManageStock = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COALESCE(' . $db->quoteName('manage_stock') . ', 0)')
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . $currentId),
        )->loadResult();

        if ($stockPlan['transition'] === null && !$hasCombinations && $storedManageStock === 1) {
            // A REFUSED STOCK ADJUSTMENT IS NOT A FAILED SAVE. The row and its
            // combinations are already committed, so the save is reported as the
            // success it is and the stock refusal as its own error naming the
            // reason; the operator corrects the quantity and saves again.
            try {
                $this->applyStockAdapter(
                    itemId:         $currentId,
                    locationStock:  $locationStock,
                    stockProvided:  $stockProvided,
                    stockRequested: $stockRequested,
                    stockBaseline:  $stockPlan['baseline'],
                );
            } catch (Throwable $stockError) {
                Log::add(
                    entry:    'Item ' . $currentId . ' saved, but its stock could not be adjusted: '
                              . $stockError->getMessage(),
                    priority: Log::ERROR,
                    category: 'com_alfa.inventory',
                );

                // Stock reserved after the pre-flight is refused by the kernel under
                // the record's lock; the operator reads the floor it keeps. Its
                // sentence is complete, so it follows the saved notice whole.
                $app->enqueueMessage(
                    msg:  match (true) {
                        $stockError instanceof InventoryIntegrityException => $this->inventoryRefusalMessage(error: $stockError),
                        $stockError instanceof InventoryReservedFloorException => Text::sprintf(
                            'COM_ALFA_ITEM_SAVED_STOCK_NOT_CHANGED',
                            $this->inventoryRefusalMessage(error: $stockError),
                        ),
                        default => Text::sprintf('COM_ALFA_ITEM_SAVED_STOCK_REFUSED', $stockError->getMessage()),
                    },
                    type: 'error',
                );
            }
        }

        // Persist the policy overrides (NULL = global default).
        if ($policyValues !== null) {
            $db = $this->getDatabase();
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_items'))
                    ->set($db->quoteName('backorder') . ' = ' . ($policyValues['backorder'] === null ? 'NULL' : $db->quote($policyValues['backorder'])))
                    ->set($db->quoteName('min_qty') . ' = ' . ($policyValues['min_qty'] === null ? 'NULL' : (float) $policyValues['min_qty']))
                    ->where($db->quoteName('id') . ' = ' . $currentId),
            )->execute();
        }

        // A changed sale rule changes stock verdicts: the inventory service records
        // it, which moves the inventory version every cached verdict is keyed on.
        // The save stands whatever happens to that record.
        if ($saleRulesBefore !== null && $saleRulesBefore !== $this->saleRuleFacts(itemId: $currentId)) {
            try {
                (new InventoryService())->recordSaleRules(itemId: $currentId, context: InventoryContext::ambient());
            } catch (Throwable $recordError) {
                Log::add(
                    entry:    'Item ' . $currentId . ' saved, but its sale rule change was not recorded: '
                              . $recordError->getMessage(),
                    priority: Log::ERROR,
                    category: 'com_alfa.inventory',
                );
            }
        }

        // Step 4: rebuild every catalog projection (price, item-field values, ...)
        // for this item. MUST be the last step: projections read the prices and
        // associations committed above. The manager isolates and logs each
        // projection's failures, so a sync problem never blocks the save.
        (new ProjectionManager())->syncItem($currentId);

        // The move into the archive held out of the row write, made as the list
        // makes it; publish() reports a refusal and redoes the projections.
        if ($archiveMove !== null) {
            $moved = [$currentId];

            $this->publish(pks: $moved, value: $archiveMove);
        }

        // Feature plugins persist their injected-field data into their OWN
        // tables here (subscription settings, bundle contents, ...). Read-only
        // payload; a listener failure never blocks the item save.
        try {
            $event = new ItemAfterSaveEvent('onItemAfterSave', [
                'itemId' => $currentId,
                'data' => $data,
                'isNew' => $pk <= 0,
            ]);

            Factory::getApplication()->getDispatcher()->dispatch('onItemAfterSave', $event);
        } catch (Throwable $e) {
            Log::add(
                entry:    'onItemAfterSave listener failed for item ' . $currentId . ': '
                          . $e->getMessage() . "\n" . $e->getTraceAsString(),
                priority: Log::ERROR,
                category: 'com_alfa',
            );
        }

        return true;
    }

    /**
     * Method to change the published state of one or more items.
     *
     * Extends the parent publish() to keep the price index in sync:
     *
     *   state = 1 (publish)                          -> syncItems()
     *   state = 0 / -2 / 2 (unpublish/trash/archive) -> deleteForItems()
     *
     * The target state is the same for the whole selection, so the batch goes one
     * way or the other in a single fan-out, never one projection call per key.
     *
     * Non-fatal: sync failures are logged but never block the publish operation.
     *
     * ARCHIVING IS AN INVENTORY TRANSITION. An archived product has left current
     * commerce, and every stock identity of it is kept only for its order
     * history. Every product this call moves into the archive (value 2) goes
     * through the inventory service, which locks its stock, refuses to archive a
     * product still holding stock or claims or whose figures disagree with its
     * stock records, runs the state write below inside its transaction and records
     * one 'archive' operation per product. The state write of the whole selection
     * runs there, and a product it leaves out (checked out by another user, or
     * refused by a plugin) rolls the change back. An archived product leaves every
     * live cart in the same transaction, as an archived combination does. The
     * projection and category cache work runs after the inventory service has
     * committed.
     *
     * PUBLISHING NEVER BRINGS A PRODUCT OUT OF THE ARCHIVE. Publishing,
     * unpublishing and trashing only change the visibility of a current product.
     * A request for any of them that names an archived product is refused whole,
     * naming the archived products, and nothing changes: an archived product
     * returns to the active catalogue only through restoreArchived().
     *
     * Which products cross the archive is read under a lock on their rows that
     * the state write holds too, so a concurrent move into or out of the archive
     * never reaches a state write the inventory service did not judge.
     *
     * @param array $pks An array of item primary keys.
     * @param int $value The target state: 1=publish, 0=unpublish, 2=archive, -2=trash.
     * @param bool $queueRefusal Also show a refusal in the message queue: the list's
     *                           state tasks report only a count, never the model error.
     *                           delete() reports it through its own error instead.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function publish(&$pks, $value = 1, bool $queueRefusal = true)
    {
        $value = (int) $value;
        $db = $this->getDatabase();
        $requested = (array) $pks;
        $ids = array_values(array_filter(array_map('intval', $requested), static fn (int $pk): bool => $pk > 0));

        // A selection that crosses nothing is written in the transaction that
        // locked its rows and read their states. One that crosses goes to the
        // inventory service, whose state write reads the crossing set again under
        // the service's locks: when another request changed it in between, nothing
        // is written and the selection is judged again.
        for ($attempt = 1; ; ++$attempt) {
            $pks = $requested;

            $db->transactionStart(asSavepoint: true);

            try {
                $crossing = $this->archiveCrossing(ids: $ids, value: $value);

                if ($crossing === []) {
                    // Let Joomla handle the actual state change in the database
                    $result = parent::publish(pks: $pks, value: $value);
                }

                TransactionState::commitLevel(db: $db);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $db, failure: $e);

                throw $e;
            }

            if ($crossing === []) {
                break;
            }

            // Any state but the archive, asked for an archived product: refused whole.
            if ($value !== CombinationState::ARCHIVED) {
                $refusal = Text::sprintf('COM_ALFA_ITEM_ARCHIVED_RESTORE_FIRST', implode(', #', $crossing));
                $this->setError(error: $refusal);

                if ($queueRefusal) {
                    Factory::getApplication()->enqueueMessage(msg: $refusal, type: 'error');
                }

                // Nothing changed: an empty list keeps the list's state task from reporting a count.
                $pks = [];

                return false;
            }

            $writeRefused = false;
            $crossingChanged = false;
            $refusal = null;

            try {
                (new InventoryService())->changeItemArchive(
                    itemIds: $crossing,
                    archive: true,
                    write:   function () use (&$pks, $ids, $value, $crossing, &$writeRefused, &$crossingChanged, $db): bool {
                        if ($this->archiveCrossing(ids: $ids, value: $value) !== $crossing) {
                            $crossingChanged = true;

                            throw new RuntimeException(
                                'The archived status of products #' . implode(', #', $ids)
                                . ' changed while the change was being made; nothing was changed.',
                            );
                        }

                        $written = (bool) parent::publish(pks: $pks, value: $value);

                        if (!$written || array_diff($crossing, array_map('intval', (array) $pks)) !== []) {
                            $writeRefused = true;

                            throw new RuntimeException(
                                'The state of products #' . implode(', #', $crossing) . ' was not written.',
                            );
                        }

                        (new CartRepository(db: $db))->removeLinesForItems(itemIds: $crossing);

                        return true;
                    },
                    context: InventoryContext::ambient(),
                );

                $result = true;
            } catch (InventoryUnitRetirementException $held) {
                $refusal = $this->stockHeldMessage(
                    key:      'COM_ALFA_ITEM_ARCHIVE_STOCK_HELD',
                    itemId:   $held->itemId,
                    blockers: $held->blockers,
                );
            } catch (InventoryProjectionMismatchException) {
                $refusal = Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE');
            } catch (Throwable $e) {
                if ($crossingChanged && $attempt < self::ARCHIVE_ATTEMPTS) {
                    continue;
                }

                if (!$writeRefused) {
                    throw $e;
                }

                // The state write reported its own reason; nothing changed.
                $pks = [];

                return false;
            }

            if ($refusal !== null) {
                $this->setError(error: $refusal);

                if ($queueRefusal) {
                    Factory::getApplication()->enqueueMessage(msg: $refusal, type: 'error');
                }

                // Nothing changed: an empty list keeps the list's state task from reporting a count.
                $pks = [];

                return false;
            }

            break;
        }

        if (!$result) {
            return false;
        }

        // parent::publish() may rewrite $pks, so read the keys only afterwards.
        $itemIds = array_values(
            array_filter(
                array_map('intval', (array) $pks),
                static fn (int $pk): bool => $pk > 0,
            ),
        );

        if ($itemIds === []) {
            return true;
        }

        $projectionManager = new ProjectionManager();

        if ((int) $value === 1) {
            // Re-published: rebuild the batch's projection rows so the items re-enter the catalog.
            $projectionManager->syncItems(itemIds: $itemIds);
        } else {
            // Unpublished, trashed or archived: remove them from the catalog immediately.
            $projectionManager->deleteForItems(itemIds: $itemIds);
        }

        // The membership changed, so the cached trees are wrong
        //
        // CategoryHelper caches category trees AND their product counts
        // persistently, with a hard-coded one-year lifetime and caching forced
        // on regardless of the site's global setting. save() and
        // batchCategory() already clear it when they change which items belong
        // where; publishing, unpublishing, trashing, archiving and deleting
        // change which items COUNT as members, which is the same fact from the
        // tree's point of view.
        //
        // Cleared unconditionally rather than only for items that have
        // categories: clearCache() cleans the whole group in one call whatever
        // id it is given, over-invalidating is harmless, and under-invalidating
        // is the defect being fixed. Measured on the demo: a trashed product
        // kept being counted (34 served against 33 recomputed), and a DELETED
        // one kept its place too.
        CategoryHelper::clearCache();

        return true;
    }

    /**
     * Restore archived products to the active catalogue: the one way out of the
     * archive.
     *
     * An archived product is a retired identity kept for the history of earlier
     * orders, so bringing it back is a lifecycle transition, not a change of
     * visibility. Each product is judged on its own, in ascending id order, by the
     * inventory service under its own locks: while the product has current
     * combinations its own stock must be clear (every record at zero, nothing
     * reserved, no open reservation, no pending stock effect, no open order line
     * still to be fulfilled from it); its stock figures must agree with its stock
     * records; and no stock unit of it may be held until its figures are repaired.
     * A product that passes is written unpublished, with one 'unarchive'
     * operation; no stock record, movement, reservation, figure or historical
     * identity changes. It is then an ordinary current product: it can be
     * published, given combinations and sold under the normal rules.
     *
     * Every outcome is reported in the message queue, one message per product: a
     * restored product, a product that is not archived (a warning; nothing to do),
     * or a refusal naming its reason (an error; the product stays archived). A
     * restore that fails for any other reason (a lock that could not be taken, a
     * plugin failing on the state change) is rolled back, reported as not
     * restored and logged. The other products of the request proceed either way.
     * The refusals are also the model error.
     *
     * @param array $pks The products to restore; on return, the products restored.
     *
     * @return bool True when no product was refused.
     *
     * @since  2.0.0
     */
    public function restoreArchived(array &$pks): bool
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();
        $service = new InventoryService();
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $pks),
            static fn (int $pk): bool => $pk > 0,
        )));
        sort($ids);
        $restored = [];
        $refusals = [];

        /** The stored state of a product, read under its row lock when asked; null when it does not exist. */
        $stateOf = static function (int $itemId, bool $lock) use ($db): mixed {
            $query = $db->getQuery(new: true)
                ->select(columns: $db->quoteName(name: 'state'))
                ->from(table: $db->quoteName(name: '#__alfa_items'))
                ->where(conditions: $db->quoteName(name: 'id') . ' = ' . $itemId);
            $row = $db->setQuery(query: $lock ? (string) $query . ' FOR UPDATE' : $query)->loadRow();

            return $row === null ? null : $row[0];
        };

        foreach ($ids as $itemId) {
            $stored = $stateOf(itemId: $itemId, lock: false);

            if ($stored !== null && CombinationState::isCurrent(state: $stored)) {
                $app->enqueueMessage(msg: Text::sprintf('COM_ALFA_ITEM_RESTORE_NOT_ARCHIVED', $itemId), type: 'warning');

                continue;
            }

            $notArchived = false;
            $writeRefused = false;
            $errorsBefore = \count($this->getErrors());
            $refusal = null;

            try {
                $service->changeItemArchive(
                    itemIds: [$itemId],
                    archive: false,
                    write:   function () use ($itemId, $stateOf, &$notArchived, &$writeRefused): bool {
                        // Read under the lock on the product row the inventory service holds.
                        if (CombinationState::isCurrent(state: $stateOf(itemId: $itemId, lock: true))) {
                            $notArchived = true;

                            throw new RuntimeException(message: 'Product #' . $itemId . ' is no longer archived; nothing was changed.');
                        }

                        $target = [$itemId];
                        $written = (bool) parent::publish(pks: $target, value: CombinationState::UNPUBLISHED);

                        if (!$written || !\in_array($itemId, array_map('intval', $target), true)) {
                            $writeRefused = true;

                            throw new RuntimeException(message: 'The state of product #' . $itemId . ' was not written.');
                        }

                        return true;
                    },
                    context: InventoryContext::ambient(),
                );
            } catch (InventoryUnitRetirementException $held) {
                $refusal = $this->stockHeldMessage(
                    key:      'COM_ALFA_ITEM_RESTORE_STOCK_HELD',
                    itemId:   $held->itemId,
                    blockers: $held->blockers,
                );
            } catch (InventoryProjectionMismatchException) {
                $refusal = Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE');
            } catch (InventoryIntegrityException $integrity) {
                $refusal = $integrity->operatorMessage();
            } catch (InventoryUnitException $unit) {
                $refusal = $unit->operatorMessage();
            } catch (Throwable $e) {
                if ($notArchived) {
                    $app->enqueueMessage(msg: Text::sprintf('COM_ALFA_ITEM_RESTORE_NOT_ARCHIVED', $itemId), type: 'warning');

                    continue;
                }

                if ($writeRefused) {
                    // The state write reported its own reason (a checked-out product is
                    // named by the platform); nothing changed.
                    $errors = array_slice($this->getErrors(), $errorsBefore);
                    $last = end($errors);
                    $refusal = \is_string($last) && $last !== '' ? $last : Text::sprintf('COM_ALFA_ITEM_RESTORE_FAILED', $itemId);
                } else {
                    // Anything else (a lock that could not be taken, a plugin failing on
                    // the state change) rolled this product's change back: it stays
                    // archived, the detail goes to the log, and the request goes on.
                    Log::add(
                        entry:    'Product ' . $itemId . ' was not restored to the active catalogue: '
                                  . $e::class . ': ' . $e->getMessage(),
                        priority: Log::ERROR,
                        category: 'com_alfa.inventory',
                    );
                    $refusal = Text::sprintf('COM_ALFA_ITEM_RESTORE_FAILED', $itemId);
                }
            }

            if ($refusal !== null) {
                $refusals[] = $refusal;

                if ($refusal !== '') {
                    $app->enqueueMessage(msg: $refusal, type: 'error');
                }

                continue;
            }

            $restored[] = $itemId;
            $app->enqueueMessage(msg: Text::sprintf('COM_ALFA_ITEM_RESTORED_TO_CATALOGUE', $itemId), type: 'message');
        }

        // A restored product counts again in the category trees once published.
        if ($restored !== []) {
            CategoryHelper::clearCache();
        }

        if ($refusals !== []) {
            $this->setError(error: trim(implode(' ', $refusals)));
        }

        $pks = $restored;

        return $refusals === [];
    }

    /**
     * The requested products whose archived status a state change crosses: the
     * current ones for the archived state, the archived ones for any other state.
     *
     * Their rows are read under an exclusive lock (FOR UPDATE), held until the
     * caller's transaction ends, so the answer stays true for the state write
     * made in that transaction.
     *
     * @param int[] $ids The requested products (positive ids).
     * @param int $value The target state.
     *
     * @return int[] The crossing products, ascending.
     *
     * @since  2.0.0
     */
    private function archiveCrossing(array $ids, int $value): array
    {
        if ($ids === []) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'state']))
            ->from($db->quoteName('#__alfa_items'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')')
            ->order($db->quoteName('id'));
        $crossing = [];

        foreach ($db->setQuery(query: (string) $query . ' FOR UPDATE')->loadObjectList() ?: [] as $row) {
            if (CombinationState::isCurrent(state: $row->state) === ($value === CombinationState::ARCHIVED)) {
                $crossing[] = (int) $row->id;
            }
        }

        return $crossing;
    }

    /**
     * The state a save moves a product to when that move crosses the archive,
     * or null when the save leaves the product's archived status as it is.
     *
     * A new product crosses only when it is posted as archived. A save that
     * carries no state never crosses.
     *
     * @param int $pk The product being saved; 0 for a new one.
     * @param array $data The save data.
     *
     * @return int|null The posted state, or null.
     *
     * @since  2.0.0
     */
    private function postedArchiveMove(int $pk, array $data): ?int
    {
        if (!\array_key_exists('state', $data) || !is_numeric($data['state'])) {
            return null;
        }

        $posted = (int) $data['state'];

        if ($pk <= 0) {
            return $posted === CombinationState::ARCHIVED ? $posted : null;
        }

        $db = $this->getDatabase();
        $stored = $db->setQuery(
            query: $db->getQuery(true)
                ->select($db->quoteName('state'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . $pk),
        )->loadRow();

        if ($stored === null) {
            return null;
        }

        return CombinationState::isCurrent(state: $stored[0]) === CombinationState::isCurrent(state: $posted)
            ? null
            : $posted;
    }

    /**
     * The refusals of the quantity rules a save posts, with every posted rule
     * normalised in the save data.
     *
     * Only the rules the save posts are read. Each is parsed exactly: a number
     * with at most 8 decimal places and 16 digits before the decimal point. One
     * that is not such a number, or is below zero, is refused naming its field.
     * An empty or zero minimum is 1, an empty step is 1 and an empty or zero
     * maximum is no limit; a step of zero is refused.
     *
     * The resulting set is judged only when it differs exactly from the stored
     * one, or the product is new, so rules stored earlier never block an edit that
     * leaves them as they are. A minimum above the maximum, and rules that leave no
     * whole quantity a cart line can hold, are refused. Every current combination
     * that inherits a rule this save changes is judged by the rules it would then
     * sell under, and a refusal names it.
     *
     * @param int $pk The product being saved; 0 for a new one.
     * @param array $data The save data; each posted rule is replaced by its exact
     *                    canonical form (NULL for no maximum).
     * @param Form|null $form The item form, for the field labels.
     *
     * @return list<string> The refusals; empty when the rules may be saved.
     *
     * @since  2.0.0
     */
    private function quantityRuleRefusals(int $pk, array &$data, ?Form $form): array
    {
        $posted = array_values(array_filter(
            self::QUANTITY_RULES,
            static fn (string $rule): bool => \array_key_exists($rule, $data),
        ));

        if ($posted === []) {
            return [];
        }

        $db = $this->getDatabase();
        $stored = null;

        if ($pk > 0) {
            $stored = $db->setQuery(
                query: $db->getQuery(new: true)
                    ->select(columns: $db->quoteName(name: self::QUANTITY_RULES))
                    ->from(table: $db->quoteName(name: '#__alfa_items'))
                    ->where(conditions: $db->quoteName(name: 'id') . ' = ' . $pk),
            )->loadAssoc() ?: null;
        }

        $one = QuantityDecimal::canonical(value: 1);
        $rules = [];
        $refusals = [];

        foreach (self::QUANTITY_RULES as $rule) {
            // A rule the save does not post stands as stored; a new product takes the defaults.
            if (!\in_array($rule, $posted, true)) {
                $current = $stored === null ? ($rule === 'quantity_max' ? null : $one) : $stored[$rule];
                $rules[$rule] = $current === null ? null : QuantityDecimal::canonical(value: $current);

                continue;
            }

            $value = $data[$rule];
            $blank = $value === null || (\is_string($value) && trim($value) === '');
            $parsed = $blank ? null : QuantityDecimal::parse(value: $value);

            if (!$blank && ($parsed === null || QuantityDecimal::isNegative(a: $parsed))) {
                $label = $form instanceof Form ? (string) $form->getFieldAttribute(name: $rule, attribute: 'label') : '';
                $refusals[] = Text::sprintf('COM_ALFA_ITEM_QUANTITY_RULE_INVALID', Text::_($label !== '' ? $label : $rule));

                continue;
            }

            $zero = !$blank && QuantityDecimal::isZero(a: $parsed);

            // A zero step is kept, to be refused below.
            $rules[$rule] = match ($rule) {
                'quantity_min' => $blank || $zero ? $one : $parsed,
                'quantity_max' => $blank || $zero ? null : $parsed,
                default => $blank ? $one : $parsed,
            };
            $data[$rule] = $rules[$rule];
        }

        if ($refusals !== []) {
            return $refusals;
        }

        /** Whether two rules are the same exact quantity (both absent included). */
        $same = static fn (?string $a, ?string $b): bool => $a === null || $b === null
            ? $a === $b
            : QuantityDecimal::compare(a: $a, b: $b) === 0;

        $changed = array_values(array_filter(
            self::QUANTITY_RULES,
            static fn (string $rule): bool => $stored === null || !$same(
                a: $rules[$rule],
                b: $stored[$rule] === null ? null : QuantityDecimal::canonical(value: $stored[$rule]),
            ),
        ));

        if ($changed === []) {
            return [];
        }

        $reason = $this->quantityRuleReason(
            min:  $rules['quantity_min'],
            step: $rules['quantity_step'],
            max:  $rules['quantity_max'],
        );

        if ($reason !== null || $pk <= 0) {
            return $reason === null ? [] : [$reason];
        }

        // Every current combination that inherits a changed rule sells under the new one.
        $combinations = $db->setQuery(
            query: $db->getQuery(new: true)
                ->select(columns: $db->quoteName(name: array_merge(['id'], self::QUANTITY_RULES)))
                ->from(table: $db->quoteName(name: '#__alfa_item_combinations'))
                ->where(conditions: $db->quoteName(name: 'item_id') . ' = ' . $pk)
                ->where(conditions: CombinationState::currentSql(column: $db->quoteName(name: 'state')))
                ->order(columns: $db->quoteName(name: 'id')),
        )->loadAssocList() ?: [];

        foreach ($combinations as $combination) {
            $inherits = false;

            foreach ($changed as $rule) {
                $own = $combination[$rule] === null ? null : QuantityDecimal::parse(value: $combination[$rule]);

                if ($own === null || QuantityDecimal::isZero(a: $own)) {
                    $inherits = true;

                    break;
                }
            }

            if (!$inherits) {
                continue;
            }

            $reason = $this->quantityRuleReason(
                min:  CartQuantityPolicy::ruleValue(combination: $combination['quantity_min'], item: $rules['quantity_min']),
                step: CartQuantityPolicy::ruleValue(combination: $combination['quantity_step'], item: $rules['quantity_step']),
                max:  CartQuantityPolicy::ruleValue(combination: $combination['quantity_max'], item: $rules['quantity_max']),
            );

            if ($reason !== null) {
                $refusals[] = Text::sprintf(
                    'COM_ALFA_ITEM_QUANTITY_RULES_VARIANT_REFUSED',
                    htmlspecialchars(
                        string:   InventoryUnitException::labelFor(itemId: $pk, combinationId: (int) $combination['id']),
                        flags:    ENT_QUOTES,
                        encoding: 'UTF-8',
                    ),
                    $reason,
                );
            }
        }

        return $refusals;
    }

    /**
     * Why a set of quantity rules cannot be sold under, or null when it can.
     *
     * A cart line holds whole units, so the rules sell the whole multiples of the
     * step from the minimum up to the maximum; rules that leave none are refused.
     * Every comparison is exact.
     *
     * @param string $min The minimum, canonical.
     * @param string $step The step, canonical.
     * @param string|null $max The maximum, canonical; null or zero for no limit.
     *
     * @return string|null The translated reason, or null.
     *
     * @since  2.0.0
     */
    private function quantityRuleReason(string $min, string $step, ?string $max): ?string
    {
        $limited = $max !== null && !QuantityDecimal::isZero(a: $max);

        if (QuantityDecimal::isZero(a: $step)) {
            return Text::_('COM_ALFA_ITEM_QUANTITY_STEP_ZERO');
        }

        if ($limited && QuantityDecimal::compare(a: $min, b: $max) > 0) {
            return Text::sprintf(
                'COM_ALFA_ITEM_QUANTITY_MIN_ABOVE_MAX',
                QuantityDecimal::display(a: $min),
                QuantityDecimal::display(a: $max),
            );
        }

        if (CartQuantityPolicy::saleMinimum(min: $min, step: $step, max: $limited ? $max : QuantityDecimal::ZERO) === null) {
            return Text::sprintf(
                'COM_ALFA_ITEM_QUANTITY_RULES_UNORDERABLE',
                QuantityDecimal::display(a: $min),
                QuantityDecimal::display(a: $step),
                $limited ? QuantityDecimal::display(a: $max) : Text::_('COM_ALFA_ITEM_QUANTITY_NO_MAXIMUM'),
            );
        }

        return null;
    }

    /**
     * The sale rules of a product as stored: its quantity rules in their exact
     * canonical form (null for no maximum), its backorder action normalised and its
     * safety stock. Two readings compare equal exactly when no rule that decides a
     * stock verdict changed in between.
     *
     * @param int $itemId The product.
     *
     * @return array<string, mixed>|null Null when the product does not exist.
     *
     * @since  2.0.0
     */
    private function saleRuleFacts(int $itemId): ?array
    {
        $db = $this->getDatabase();
        $row = $db->setQuery(
            query: $db->getQuery(new: true)
                ->select(columns: $db->quoteName(name: array_merge(self::QUANTITY_RULES, ['backorder', 'min_qty'])))
                ->from(table: $db->quoteName(name: '#__alfa_items'))
                ->where(conditions: $db->quoteName(name: 'id') . ' = ' . $itemId),
        )->loadAssoc();

        if (!$row) {
            return null;
        }

        foreach (self::QUANTITY_RULES as $rule) {
            $row[$rule] = $row[$rule] === null ? null : QuantityDecimal::canonical(value: $row[$rule]);
        }

        $row['backorder'] = BackorderPolicy::normaliseStoredValue(value: $row['backorder']);

        return $row;
    }

    /**
     * Apply the item form's changed stock figures through the inventory kernel.
     *
     * Extracted so the caller can catch a kernel refusal without swallowing
     * anything else in the save. Throws what the kernel throws, and a
     * RuntimeException naming the reason when a changed figure cannot be applied.
     *
     * A FIGURE THE OPERATOR DID NOT TOUCH IS NOT A COMMAND.
     *
     * `items.stock` is a projection, never authority: physical quantity enters
     * and leaves only through a movement. Each posted figure is compared with the
     * figure it was rendered with, and an untouched figure issues nothing, so a
     * form left open while stock sells cannot put the sold units back.
     *
     * A changed figure is a TARGET: the kernel converges the location to it under
     * the stock row's lock, so a second submit of the same form, or a figure that
     * is already true, moves nothing. The plain figure stands for the default
     * location, and only while the product is stocked at one location at most.
     * The changed figures apply in one transaction that first locks the product's
     * stock records, in the inventory service's lock order: every location
     * converges, or none does.
     *
     * @param int $itemId The saved product.
     * @param array|null $locationStock Per-location figures keyed by location id, or null.
     * @param bool $stockProvided Whether a plain figure was posted.
     * @param float|null $stockRequested The plain figure, or null when none was posted.
     * @param array|null $stockBaseline The figure each input was rendered with, keyed by
     *                                  location id (0 = the single figure); null for a
     *                                  programmatic save, whose figures are compared with
     *                                  the stored product figure and the stock records.
     *
     *
     * @throws RuntimeException When a changed figure cannot be applied.
     *
     * @since  2.0.0
     */
    private function applyStockAdapter(
        int $itemId,
        ?array $locationStock,
        bool $stockProvided,
        ?float $stockRequested,
        ?array $stockBaseline = null,
    ): void {
        $baseline = $stockBaseline ?? $this->implicitStockBaseline(
            itemId:        $itemId,
            locationStock: $locationStock,
            storedStock:   $this->storedStockFigure(itemId: $itemId),
        );

        $changed = self::changedStockFigures(
            locationStock:  $locationStock,
            stockProvided:  $stockProvided,
            stockRequested: $stockRequested,
            baseline:       $baseline,
        );

        if ($changed['unbased'] !== []) {
            throw new RuntimeException(Text::_('COM_ALFA_INLINE_STOCK_INVALID'));
        }

        $service = new InventoryService();
        $targets = $changed['locations'];

        if ($changed['plain'] !== null) {
            $locations = \count((new InventoryAvailabilityService())->locationBreakdown(itemId: $itemId));

            if ($locations > 1) {
                throw new RuntimeException(Text::sprintf('COM_ALFA_ITEM_STOCK_PLAIN_AMBIGUOUS', $locations));
            }

            $targets = [$service->defaultLocationId() => $changed['plain']];
        }

        if ($targets === []) {
            return;
        }

        $db = $this->getDatabase();
        $unapplied = false;

        $db->transactionStart(asSavepoint: true);

        try {
            $service->lockItemStock(itemId: $itemId);

            foreach ($targets as $locationId => $target) {
                $applied = $service->adjustTo(
                    itemId:        $itemId,
                    combinationId: null,
                    locationId:    (int) $locationId,
                    target:        $target,
                    sourceType:    'catalog_edit',
                    sourceId:      $itemId,
                    context:       InventoryContext::ambient(),
                );

                // Null means the product is no longer a stock unit of its own.
                if ($applied === null) {
                    $unapplied = true;

                    break;
                }
            }

            // The change stands whole or not at all: what the locations already
            // converged would announce is discarded with a change that does not.
            if ($unapplied) {
                TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The product is no longer a stock unit of its own.'));
            } else {
                TransactionState::commitLevel(db: $db);
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        if ($unapplied) {
            throw new RuntimeException($this->unappliedStockReason(itemId: $itemId));
        }
    }

    /**
     * What a save asks of the inventory service, and every refusal that can be
     * known before the row is written.
     *
     * Every change of an existing product's tracking setting is written by the
     * inventory service (flagChange). It is a TRANSITION only when it changes
     * some unit's effective tracking: a plain product's own unit, or the
     * combinations that inherit the product's setting. A new product is never a
     * transition; its stored setting is what the save writes. Without a
     * transition, the changed figures of a product that tracks stock and has no
     * combinations are the stock commands of the save; a figure without a
     * rendering for a product that will not track stock is refused, since it
     * would change nothing.
     *
     * A switch to untracked is refused while a unit it takes off still has open
     * reservations and, when it has none, while open order lines placed as stock
     * claims still have quantity to be delivered from it, naming how many lines
     * and orders: the operator completes or cancels those orders, or removes
     * those lines, and switches again.
     *
     * @param int $pk The product being saved; 0 for a new one.
     * @param array $data The save data.
     * @param bool $stockProvided Whether a plain figure was posted.
     * @param string $stockRaw The plain figure as posted, trimmed.
     * @param float|null $stockRequested The plain figure, or null when there is none.
     * @param array|null $locationStock Per-location figures keyed by location id, or null.
     * @param array|null $stockBaseline The rendered figures, or null for a programmatic save.
     *
     * @return array{refusals: list<string>, flagChange: bool, transition: ?string, flag: ?int, openings: ?array<int, float>, baseline: array<int, string>}
     *
     * @since  2.0.0
     */
    private function stockSavePlan(
        int $pk,
        array $data,
        bool $stockProvided,
        string $stockRaw,
        ?float $stockRequested,
        ?array $locationStock,
        ?array $stockBaseline,
    ): array {
        $db = $this->getDatabase();
        $service = new InventoryService();
        $plan = ['refusals' => [], 'flagChange' => false, 'transition' => null, 'flag' => null, 'openings' => null, 'baseline' => []];

        // What is stored now: the product's tracking and stock figure, and which of
        // its current combinations inherit its tracking. An archived combination
        // is kept only for order history: it is not one of the product's stock
        // units, so it neither makes the product combination-bearing nor counts
        // among the combinations a tracking switch affects.
        $stored = null;
        $bearsCombinations = false;
        $inheriting = [];

        if ($pk > 0) {
            $stored = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['manage_stock', 'stock']))
                    ->from($db->quoteName('#__alfa_items'))
                    ->where($db->quoteName('id') . ' = ' . $pk),
            )->loadObject() ?: null;

            $combinations = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'manage_stock']))
                    ->from($db->quoteName('#__alfa_item_combinations'))
                    ->where($db->quoteName('item_id') . ' = ' . $pk)
                    ->where(CombinationState::currentSql(column: $db->quoteName('state'))),
            )->loadObjectList() ?: [];

            foreach ($combinations as $combination) {
                $bearsCombinations = true;

                if ($combination->manage_stock === null) {
                    $inheriting[] = (int) $combination->id;
                }
            }
        }

        $storedFlag = (int) ($stored->manage_stock ?? 0) === 1 ? 1 : 0;
        $postedFlag = \array_key_exists('manage_stock', $data) ? ((int) $data['manage_stock'] === 1 ? 1 : 0) : null;
        $storedStock = $stored?->stock === null ? null : (float) $stored->stock;
        $plan['flag'] = $postedFlag;
        $plan['flagChange'] = $stored !== null && $postedFlag !== null && $postedFlag !== $storedFlag;

        if ($plan['flagChange'] && (!$bearsCombinations || $inheriting !== [])) {
            $plan['transition'] = $postedFlag === 1 ? 'on' : 'off';
        }

        // A new product takes the schema default (tracked) unless the save says otherwise.
        $trackedAfter = ($postedFlag ?? ($pk > 0 ? $storedFlag : 1)) === 1;
        $figuresApply = !$bearsCombinations && $plan['transition'] === null && $trackedAfter;

        // A figure that is not a number is refused rather than read as zero.
        $invalid = $stockProvided && $stockRaw !== '' && !is_numeric($stockRaw);

        foreach ($locationStock ?? [] as $value) {
            if ($value !== null && (!\is_scalar($value) || (trim((string) $value) !== '' && !is_numeric(trim((string) $value))))) {
                $invalid = true;
            }
        }

        if ($invalid) {
            $plan['refusals'] = [Text::_('COM_ALFA_INLINE_STOCK_INVALID')];

            return $plan;
        }

        $plan['baseline'] = $stockBaseline ?? $this->implicitStockBaseline(
            itemId:        $pk,
            locationStock: $locationStock,
            storedStock:   $storedStock,
        );

        $changed = self::changedStockFigures(
            locationStock:  $locationStock,
            stockProvided:  $stockProvided,
            stockRequested: $stockRequested,
            baseline:       $plan['baseline'],
        );
        $hasChanged = $changed['plain'] !== null || $changed['locations'] !== [];

        // A rendering always carries the figure each input was rendered with.
        if (($figuresApply || $bearsCombinations) && $changed['unbased'] !== []) {
            $plan['refusals'] = [Text::_('COM_ALFA_INLINE_STOCK_INVALID')];

            return $plan;
        }

        // A product with combinations holds its stock on them.
        if ($bearsCombinations && $hasChanged) {
            $plan['refusals'] = [Text::_('COM_ALFA_ITEM_STOCK_VARIANTS_OWN')];

            return $plan;
        }

        // A figure sent without a rendering for a product that will not track stock
        // would change nothing, so it is refused instead of being dropped.
        if ($stockBaseline === null && !$trackedAfter && $hasChanged) {
            $plan['refusals'] = [Text::sprintf(
                'COM_ALFA_ITEM_STOCK_NOT_APPLIED',
                Text::_('COM_ALFA_COMBI_STOCK_REASON_UNTRACKED'),
            )];

            return $plan;
        }

        // The plain figure stands for one location only.
        if ($figuresApply && $changed['plain'] !== null) {
            $locations = \count((new InventoryAvailabilityService())->locationBreakdown(itemId: $pk));

            if ($locations > 1) {
                $plan['refusals'] = [Text::sprintf('COM_ALFA_ITEM_STOCK_PLAIN_AMBIGUOUS', $locations)];

                return $plan;
            }
        }

        // A programmatic figure is judged against the stored figure, which is only
        // meaningful while that figure agrees with the stock records.
        if ($figuresApply && $hasChanged && $stockBaseline === null && $pk > 0
            && !$this->projectionsAgree(itemId: $pk)
        ) {
            $plan['refusals'] = [Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE')];

            return $plan;
        }

        if ($plan['transition'] === 'on') {
            if ($bearsCombinations) {
                $plan['refusals'] = [Text::sprintf('COM_ALFA_ITEM_TRACKING_ON_VARIANTS_INHERIT', \count($inheriting))];

                return $plan;
            }

            if (!$this->projectionsAgree(itemId: $pk)) {
                $plan['refusals'] = [Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE')];

                return $plan;
            }

            $plan['openings'] = $this->openingTargets(
                itemId:         $pk,
                stockRequested: $stockRequested,
                locationStock:  $locationStock,
            );

            if ($plan['openings'] === null) {
                $plan['refusals'] = [Text::_('COM_ALFA_INVENTORY_TRACKING_OPENING_REQUIRED')];

                return $plan;
            }

            $plan['refusals'] = $this->targetRefusals(
                problems: $service->validateTargets(
                    itemId:        $pk,
                    combinationId: null,
                    targets:       $plan['openings'],
                    assumeTracked: true,
                ),
            );

            if ($plan['refusals'] !== []) {
                return $plan;
            }
        }

        if ($plan['transition'] === 'off') {
            if (!$this->projectionsAgree(itemId: $pk)) {
                $plan['refusals'] = [Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE')];

                return $plan;
            }

            // The units the switch takes off: the product's own, or every current
            // combination that inherits its setting.
            $goingOff = $bearsCombinations ? $inheriting : [0];

            $reserved = $service->countActiveReservations(
                itemId:         $pk,
                combinationIds: $goingOff,
                lock:           false,
            );

            if ($reserved > 0) {
                $plan['refusals'] = [Text::sprintf('COM_ALFA_INVENTORY_TRACKING_OFF_RESERVED', $reserved)];

                return $plan;
            }

            // An open order line placed as a stock claim deducts the stock of its
            // unit when it is fulfilled, reserved or not, so the unit keeps
            // tracking stock while such a line still has quantity to deliver.
            $relying = $service->relyingOpenLines(
                itemId:         $pk,
                combinationIds: $goingOff,
                lock:           false,
            );

            if ($relying['lines'] > 0) {
                $plan['refusals'] = [InventoryTrackingException::openLinesText(
                    lines:  $relying['lines'],
                    orders: $relying['orders'],
                )];

                return $plan;
            }
        }

        // A setting that changes no unit's tracking (every combination has its
        // own) is still written by the inventory service, which refuses it while
        // the figures disagree.
        if ($plan['flagChange'] && $plan['transition'] === null && !$this->projectionsAgree(itemId: $pk)) {
            $plan['refusals'] = [Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE')];

            return $plan;
        }

        // The changed figures of a tracked plain product: a new product's figures
        // are its opening stock, which the inventory service judges against a
        // product that holds no stock yet (item id 0).
        if ($figuresApply && $hasChanged) {
            $targets = $changed['plain'] !== null
                ? [$service->defaultLocationId() => $changed['plain']]
                : $changed['locations'];

            $plan['refusals'] = $this->targetRefusals(
                problems: $service->validateTargets(
                    itemId:        $pk,
                    combinationId: null,
                    targets:       $targets,
                    assumeTracked: false,
                ),
            );

            if ($plan['refusals'] !== []) {
                return $plan;
            }
        }

        // Combination edits carried by the same save.
        if ($pk > 0) {
            $plan['refusals'] = array_values(ItemFieldsAdminHelper::combinationSaveRefusals(
                itemId:                $pk,
                payload:               (array) ($data['combinations'] ?? []),
                deletedCombinationIds: (string) ($data['deleted_combination_ids'] ?? ''),
            ));
        }

        return $plan;
    }

    /**
     * The posted stock figures that are commands: each one whose formatted value
     * differs from the figure it is compared with.
     *
     * A per-location breakdown takes precedence over the plain figure, and an
     * empty figure is no figure.
     *
     * @param array|null $locationStock Per-location figures keyed by location id, or null.
     * @param bool $stockProvided Whether a plain figure was posted.
     * @param float|null $stockRequested The plain figure, or null when there is none.
     * @param array $baseline The figures to compare with, keyed by location id
     *                        (0 = the plain figure).
     *
     * @return array{plain: ?float, locations: array<int, float>, unbased: list<int>}
     *                                                                                The changed plain figure, the changed per-location figures, and the
     *                                                                                posted figures that have nothing to be compared with.
     *
     * @since  2.0.0
     */
    private static function changedStockFigures(
        ?array $locationStock,
        bool $stockProvided,
        ?float $stockRequested,
        array $baseline,
    ): array {
        $changed = ['plain' => null, 'locations' => [], 'unbased' => []];

        $rendered = static function (mixed $value): string {
            $figure = \is_scalar($value) ? trim((string) $value) : '';

            return InventoryAvailabilityService::formatQuantity(value: is_numeric($figure) ? (float) $figure : 0.0);
        };

        if ($locationStock !== null) {
            foreach ($locationStock as $locationId => $value) {
                $figure = \is_scalar($value) ? trim((string) $value) : '';

                if ($figure === '' || !is_numeric($figure)) {
                    continue;
                }

                $locationId = (int) $locationId;

                if (!\array_key_exists($locationId, $baseline)) {
                    $changed['unbased'][] = $locationId;

                    continue;
                }

                if (InventoryAvailabilityService::formatQuantity(value: (float) $figure) !== $rendered($baseline[$locationId])) {
                    $changed['locations'][$locationId] = (float) $figure;
                }
            }

            return $changed;
        }

        if ($stockProvided && $stockRequested !== null) {
            if (!\array_key_exists(0, $baseline)) {
                $changed['unbased'][] = 0;
            } elseif (InventoryAvailabilityService::formatQuantity(value: $stockRequested) !== $rendered($baseline[0])) {
                $changed['plain'] = $stockRequested;
            }
        }

        return $changed;
    }

    /**
     * The figures a programmatic save is compared with: the stored product figure
     * for the plain figure, and the stock record of each posted location.
     *
     * @param int $itemId The product; 0 for one not saved yet.
     * @param array|null $locationStock The posted per-location figures, or null.
     * @param float|null $storedStock The stored stock figure; null when there is none.
     *
     * @return array<int, string> Formatted figures keyed by location id (0 = the plain figure).
     *
     * @since  2.0.0
     */
    private function implicitStockBaseline(int $itemId, ?array $locationStock, ?float $storedStock): array
    {
        $baseline = [0 => InventoryAvailabilityService::formatQuantity(value: $storedStock ?? 0.0)];

        if ($locationStock === null) {
            return $baseline;
        }

        $held = [];

        if ($itemId > 0) {
            foreach ((new InventoryAvailabilityService())->byLocation(itemId: $itemId) as $row) {
                $held[(int) $row['location_id']] = (float) $row['physical'];
            }
        }

        foreach (array_keys($locationStock) as $locationId) {
            $baseline[(int) $locationId] = InventoryAvailabilityService::formatQuantity(
                value: $held[(int) $locationId] ?? 0.0,
            );
        }

        return $baseline;
    }

    /**
     * The opening stock a switch to tracked books, per location.
     *
     * Every location the stock field renders, and every location holding a
     * stock record that is not at zero, needs an explicitly entered figure
     * (0 is a figure; an empty input is not). The plain figure stands for the
     * default location while the field renders one location at most.
     *
     * @param int $itemId The product.
     * @param float|null $stockRequested The plain figure, or null when there is none.
     * @param array|null $locationStock Per-location figures keyed by location id, or null.
     *
     * @return array<int, float>|null The openings keyed by location id; null when incomplete.
     *
     * @since  2.0.0
     */
    private function openingTargets(int $itemId, ?float $stockRequested, ?array $locationStock): ?array
    {
        $availability = new InventoryAvailabilityService();
        $required = [];

        foreach ($availability->locationBreakdown(itemId: $itemId) as $row) {
            $required[(int) $row['location_id']] = true;
        }

        foreach ($availability->byLocation(itemId: $itemId) as $row) {
            if (abs((float) $row['physical']) > InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                $required[(int) $row['location_id']] = true;
            }
        }

        if ($locationStock === null) {
            if ($stockRequested === null || \count($required) > 1) {
                return null;
            }

            return [(new InventoryService())->defaultLocationId() => $stockRequested];
        }

        $openings = [];

        foreach ($locationStock as $locationId => $value) {
            $figure = \is_scalar($value) ? trim((string) $value) : '';

            if ($figure !== '' && is_numeric($figure)) {
                $openings[(int) $locationId] = (float) $figure;
            }
        }

        foreach (array_keys($required) as $locationId) {
            if (!\array_key_exists($locationId, $openings)) {
                return null;
            }
        }

        return $openings;
    }

    /**
     * Merchant messages for the problems InventoryService::validateTargets() found.
     *
     * A unit with no stock records behind its figures, and a unit held until its
     * figures are repaired, are worded by the integrity refusal's own text with the
     * figures the problem carries. A figure below what active reservations hold on
     * the location's stock record is worded by the reservation floor refusal's own
     * text, which names the product as typed in the back office and is therefore
     * escaped here, once, for the screen and the API that show it.
     *
     * @param array $problems Each {location_id, code}; for the integrity codes the stock
     *                        and available figures; for the reservation floor the item,
     *                        combination, on-hand, reserved and requested figures.
     *
     * @return list<string>
     *
     * @since  2.0.0
     */
    private function targetRefusals(array $problems): array
    {
        if ($problems === []) {
            return [];
        }

        $db = $this->getDatabase();
        $locationIds = [];

        foreach ($problems as $problem) {
            $locationId = (int) (((array) $problem)['location_id'] ?? 0);

            if ($locationId > 0) {
                $locationIds[$locationId] = $locationId;
            }
        }

        $titles = [];

        if ($locationIds !== []) {
            foreach ($db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'title']))
                    ->from($db->quoteName('#__alfa_inventory_locations'))
                    ->whereIn($db->quoteName('id'), array_values($locationIds)),
            )->loadObjectList() ?: [] as $location) {
                $titles[(int) $location->id] = (string) $location->title;
            }
        }

        $messages = [];

        foreach ($problems as $problem) {
            $problem = (array) $problem;
            $locationId = (int) ($problem['location_id'] ?? 0);
            $code = (string) ($problem['code'] ?? '');
            $label = $titles[$locationId] ?? ('#' . $locationId);

            $messages[] = match ($code) {
                'location_disabled' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_LOCATION_DISABLED', $label),
                'not_a_location' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NOT_A_LOCATION', '#' . $locationId),
                'negative_floor' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NEGATIVE', $label),
                'ledger_missing',
                'integrity_held' => InventoryIntegrityException::refusalText(
                    stock:     isset($problem['stock']) ? (float) $problem['stock'] : null,
                    available: isset($problem['available']) ? (float) $problem['available'] : null,
                    held:      $code === 'integrity_held',
                ),
                'not_tracked' => Text::sprintf('COM_ALFA_ITEM_STOCK_NOT_APPLIED', Text::_('COM_ALFA_COMBI_STOCK_REASON_UNTRACKED')),
                'identity_gone' => Text::sprintf('COM_ALFA_ITEM_STOCK_NOT_APPLIED', Text::_('COM_ALFA_COMBI_STOCK_REASON_GONE')),
                'item_archived' => Text::sprintf('COM_ALFA_ITEM_STOCK_NOT_APPLIED', Text::_('COM_ALFA_ITEM_STOCK_REASON_ARCHIVED')),
                'reserved_floor' => htmlspecialchars(
                    string:   InventoryReservedFloorException::refusalText(
                        itemId:        (int) ($problem['item_id'] ?? 0),
                        combinationId: (int) ($problem['combination_id'] ?? 0),
                        locationId:    $locationId,
                        onHand:        (float) ($problem['on_hand'] ?? 0.0),
                        reserved:      (float) ($problem['reserved'] ?? 0.0),
                        requested:     (float) ($problem['requested'] ?? 0.0),
                    ),
                    flags:    ENT_QUOTES,
                    encoding: 'UTF-8',
                ),
                default => $code,
            };
        }

        return array_values(array_unique($messages));
    }

    /**
     * Report the inventory service's answer to a change of the tracking setting.
     *
     * The service writes the setting, books the opening stock of a switch to
     * tracked and records the operation in one transaction. A refusal there (the
     * stock, or the open orders that rely on it, changed after the pre-flight)
     * leaves the setting as it was while the rest of the save stands, so it is
     * logged and reported as a warning. A switch to untracked that leaves stock
     * records holding quantities says so.
     *
     * @param int $itemId The saved product.
     * @param array|null $result What InventoryService::changeTracking() returned; null when it threw.
     * @param Throwable|null $error What it threw, or null.
     *
     *
     * @since  2.0.0
     */
    private function reportTrackingChange(int $itemId, ?array $result, ?Throwable $error): void
    {
        $app = Factory::getApplication();

        if ($error !== null) {
            Log::add(
                entry:    'Item ' . $itemId . ' saved, but its stock tracking was not changed: '
                          . $error->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.inventory',
            );

            $app->enqueueMessage(
                msg:  Text::sprintf(
                    'COM_ALFA_ITEM_SAVED_TRACKING_REFUSED',
                    $this->inventoryRefusalMessage(error: $error),
                ),
                type: 'warning',
            );

            return;
        }

        if ((int) ($result['kept_rows'] ?? 0) > 0) {
            $app->enqueueMessage(
                msg:  Text::sprintf(
                    'COM_ALFA_INVENTORY_TRACKING_OFF_STOCK_KEPT',
                    (int) $result['kept_rows'],
                    InventoryAvailabilityService::formatQuantity(value: (float) ($result['kept_on_hand'] ?? 0.0)),
                ),
                type: 'warning',
            );
        }
    }

    /**
     * The merchant message for an inventory refusal.
     *
     * A stock change below what active reservations hold is worded by the
     * reservation floor refusal's translated sentence, escaped once because it
     * names the product as typed in the back office. A switch to untracked
     * refused for the open order lines that still rely on the unit is worded by
     * the tracking refusal's own sentence, which carries two counts and no name.
     *
     * @param Throwable $error What the inventory service threw.
     *
     *
     * @since  2.0.0
     */
    private function inventoryRefusalMessage(Throwable $error): string
    {
        if ($error instanceof InventoryTrackingException) {
            return match ($error->reason) {
                InventoryTrackingException::RESERVED => Text::sprintf(
                    'COM_ALFA_INVENTORY_TRACKING_OFF_RESERVED',
                    $error->count,
                ),
                InventoryTrackingException::OPEN_LINES => InventoryTrackingException::openLinesText(
                    lines:  $error->count,
                    orders: $error->orders,
                ),
                InventoryTrackingException::OPENING_REQUIRED => Text::_('COM_ALFA_INVENTORY_TRACKING_OPENING_REQUIRED'),
                InventoryTrackingException::VARIANTS_INHERIT => Text::sprintf(
                    'COM_ALFA_ITEM_TRACKING_ON_VARIANTS_INHERIT',
                    $error->count,
                ),
                InventoryTrackingException::TARGET => $this->targetRefusals(
                    problems: [['location_id' => (int) $error->locationId, 'code' => (string) $error->targetCode]],
                )[0] ?? $error->getMessage(),
                default => $error->getMessage(),
            };
        }

        if ($error instanceof InventoryProjectionMismatchException) {
            return Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE');
        }

        if ($error instanceof InventoryIntegrityException) {
            return $error->operatorMessage();
        }

        if ($error instanceof InventoryReservedFloorException) {
            return htmlspecialchars(string: $error->operatorMessage(), flags: ENT_QUOTES, encoding: 'UTF-8');
        }

        return $error->getMessage();
    }

    /**
     * Whether the product's stock figures agree with its stock records.
     *
     * @param int $itemId The product.
     *
     *
     * @since  2.0.0
     */
    private function projectionsAgree(int $itemId): bool
    {
        try {
            (new InventoryService())->assertProjectionsAgree(itemId: $itemId);
        } catch (InventoryProjectionMismatchException) {
            return false;
        }

        return true;
    }

    /**
     * Refuse a save before anything is written.
     *
     * The refusals are reported once, through the model error: the edit form's
     * controller shows it as the reason the save failed, and the API answers
     * with it.
     *
     * @param list<string> $messages The refusals.
     *
     * @return bool Always false.
     *
     * @since  2.0.0
     */
    private function refuseSave(array $messages): bool
    {
        $this->setError(error: implode(' ', $messages));

        return false;
    }

    /**
     * The stored stock figure of a product.
     *
     * @param int $itemId The product.
     *
     * @return float|null Null when the product has no figure (or does not exist).
     *
     * @since  2.0.0
     */
    private function storedStockFigure(int $itemId): ?float
    {
        $db = $this->getDatabase();
        $stock = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('stock'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . $itemId),
        )->loadResult();

        return $stock === null ? null : (float) $stock;
    }

    /**
     * Why a changed figure could not be applied to a product's own stock unit.
     *
     * @param int $itemId The product.
     *
     * @return string The reason as a clause for COM_ALFA_ITEM_SAVED_STOCK_REFUSED.
     *
     * @since  2.0.0
     */
    private function unappliedStockReason(int $itemId): string
    {
        $db = $this->getDatabase();

        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . $itemId),
        )->loadResult() > 0;

        if (!$exists) {
            return Text::_('COM_ALFA_COMBI_STOCK_REASON_GONE');
        }

        // Archived combinations are kept only for order history and hold none of
        // the product's stock.
        $bearsCombinations = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->where($db->quoteName('item_id') . ' = ' . $itemId)
                ->where(CombinationState::currentSql(column: $db->quoteName('state'))),
        )->loadResult() > 0;

        return Text::_($bearsCombinations ? 'COM_ALFA_ITEM_STOCK_REASON_VARIANTS_OWN' : 'COM_ALFA_COMBI_STOCK_REASON_UNTRACKED');
    }

    /**
     * The refusal of a product delete, archive or restore while the product still
     * holds stock or claims: the stock sentence when a blocked unit holds a stock
     * claim (a record not at zero, reserved quantity, an open reservation or a
     * pending stock effect), followed by the open order lines still to be
     * fulfilled from it when there are any. A product held only by such order
     * lines reads the order-line sentence alone.
     *
     * The order-line sentence names the product and its variants as typed in the
     * back office, so it is escaped here, once, for the screen that shows it.
     *
     * @param string $key The stock sentence: COM_ALFA_ITEM_DELETE_STOCK_HELD,
     *                    COM_ALFA_ITEM_ARCHIVE_STOCK_HELD or
     *                    COM_ALFA_ITEM_RESTORE_STOCK_HELD (product id, stock records
     *                    not at zero, reserved quantity, open reservations, pending
     *                    stock effects).
     * @param int $itemId The product.
     * @param array $blockers What InventoryService::retirementBlockers() reports for it:
     *                        one entry per blocked stock unit.
     *
     *
     * @since  2.0.0
     */
    private function stockHeldMessage(string $key, int $itemId, array $blockers): string
    {
        $stockClaim = false;

        foreach ($blockers as $blocker) {
            if ((int) ($blocker['rows_not_zero'] ?? 0) > 0
                || (float) ($blocker['reserved'] ?? 0) > InventoryAvailabilityService::QUANTITY_TOLERANCE
                || (int) ($blocker['active_reservations'] ?? 0) > 0
                || (int) ($blocker['pending_effects'] ?? 0) > 0
            ) {
                $stockClaim = true;

                break;
            }
        }

        $openLines = InventoryUnitRetirementException::openLinesText(itemId: $itemId, blockers: $blockers);
        $sentences = [];

        if ($stockClaim || $openLines === null) {
            $sentences[] = Text::sprintf(
                $key,
                $itemId,
                (int) array_sum(array_column($blockers, 'rows_not_zero')),
                InventoryAvailabilityService::formatQuantity(value: (float) array_sum(array_column($blockers, 'reserved'))),
                (int) array_sum(array_column($blockers, 'active_reservations')),
                (int) array_sum(array_column($blockers, 'pending_effects')),
            );
        }

        if ($openLines !== null) {
            $sentences[] = htmlspecialchars(string: $openLines, flags: ENT_QUOTES, encoding: 'UTF-8');
        }

        return implode(' ', $sentences);
    }

    /**
     * The order lines sold as a product or as any of its combinations, in every
     * order, trashed orders included.
     *
     * Their fulfilment, cancellation and returns act on the product's stock
     * identity, so while any exists the product is archived, never deleted.
     *
     * @param int $itemId The product.
     * @param bool $lock Count under a shared lock on the product's range of the
     *                   order-line index (inside a transaction), so no order line
     *                   naming the product can be added until the transaction ends.
     *
     *
     * @since  2.0.0
     */
    private function orderHistoryLineCount(int $itemId, bool $lock): int
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__alfa_order_items'))
            ->where($db->quoteName('id_item') . ' = ' . $itemId);

        return (int) $db->setQuery(
            query: $lock ? ((string) $query . ' LOCK IN SHARE MODE') : $query,
        )->loadResult();
    }

    /**
     * Method to delete one or more records.
     *
     * Every product the request names leaves the active catalogue only while it
     * holds no stock: every stock record of the product and of its combinations
     * at zero, nothing reserved, no open reservation, no pending stock effect, no
     * open order line still to be fulfilled from it, and stock figures that agree
     * with the stock records. Otherwise the whole request is refused before
     * anything is written. Neither path writes stock off; the operator brings the
     * stock to zero first through the inventory workflows.
     *
     * A product that order lines were sold as (or as one of its combinations), in
     * any order, is then archived instead of deleted: those orders are still
     * fulfilled, cancelled and returned against its stock identity. It is archived
     * through publish(), keeps its rows, combinations and stock records, leaves
     * the live carts, and is named in one warning; it is not in $pks afterwards.
     *
     * The other products are deleted: their zero stock records and finished
     * reservations are retired by the inventory service in the same transaction
     * as the product rows, with no movement.
     *
     * The archive is committed on its own, before that transaction starts, and
     * stands when the delete of the other products is then refused: the request
     * reports the failure while its warnings name the archived products. A
     * product sold after the pre-check is never deleted: the delete rolls back,
     * the error says the product was sold meanwhile, and a repeated request
     * archives it.
     *
     * The price index rows go with the product: the FK ON DELETE CASCADE handles
     * hard deletes, and the explicit projection call is the safety net for
     * soft-delete scenarios. Projection failures are logged, never fatal.
     *
     * @param array $pks An array of item primary keys to delete; on success, the
     *                   products that were deleted.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function delete(&$pks)
    {
        $pks = array_map('intval', (array) $pks);
        sort($pks);
        $db = $this->getDatabase();

        // Permission first: a refused user never reaches the stock checks.
        if (!$this->canDelete((object) [])) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $service = new InventoryService();

        // PRE-CHECK, read-only, before anything is written: a product still
        // holding stock or claims, or whose figures disagree with its stock
        // records, refuses the whole request, whether it would be deleted or
        // archived. Only products that still exist are removed, so only they are
        // judged.
        $existing = $pks === [] ? [] : array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_items'))
                ->whereIn($db->quoteName('id'), $pks)
                ->order($db->quoteName('id')),
        )->loadColumn() ?: []);

        foreach ($existing as $pk) {
            $blockers = $service->retirementBlockers(itemId: $pk, combinationIds: null);

            if ($blockers !== []) {
                $this->setError(error: $this->stockHeldMessage(
                    key:      'COM_ALFA_ITEM_DELETE_STOCK_HELD',
                    itemId:   $pk,
                    blockers: $blockers,
                ));

                return false;
            }

            if (!$this->projectionsAgree(itemId: $pk)) {
                $this->setError(error: Text::_('COM_ALFA_INVENTORY_FIGURES_DISAGREE'));

                return false;
            }
        }

        // A PRODUCT WITH ORDER HISTORY IS ARCHIVED, NOT DELETED, and only the
        // other products go on to the delete below. The archive runs through
        // publish(), so the inventory service judges each product again under its
        // own locks.
        $orderHistory = [];

        foreach ($existing as $pk) {
            $orderLines = $this->orderHistoryLineCount(itemId: $pk, lock: false);

            if ($orderLines > 0) {
                $orderHistory[$pk] = $orderLines;
            }
        }

        if ($orderHistory !== []) {
            $archive = array_keys($orderHistory);

            if (!$this->publish(pks: $archive, value: CombinationState::ARCHIVED, queueRefusal: false)) {
                return false;
            }

            foreach ($orderHistory as $itemId => $orderLines) {
                Factory::getApplication()->enqueueMessage(
                    msg:  Text::sprintf('COM_ALFA_ITEM_ARCHIVED_ORDER_HISTORY', $itemId, $orderLines),
                    type: 'warning',
                );
            }

            $existing = array_values(array_diff($existing, array_keys($orderHistory)));

            // Every product that still exists was archived: none is deleted.
            if ($existing === []) {
                $pks = [];

                return true;
            }
        }

        // Capture child identities BEFORE the rows vanish: only the combinations
        // of the products deleted below. An archived product keeps its
        // combinations, and a combination row whose product is already gone
        // stays: either can still be the stock identity of order lines sold as it.
        $comboIds = $existing === [] ? [] : array_map('intval', $db->setQuery(
            query: $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->whereIn(keyName: $db->quoteName('item_id'), keyValues: $existing),
        )->loadColumn() ?: []);

        // THE ITEM IDS AS REQUESTED, kept before the parent gets them.
        //
        // parent::delete() takes $pks by reference and unsets any row its
        // canDelete() check refuses, so by the time the cart cascade runs the
        // array no longer describes what was asked for. The cascade has always
        // operated on the REQUESTED set, and that is preserved here verbatim;
        // see the note on the cascade itself for the consequence.
        $requestedItemIds = array_map('intval', (array) $pks);

        $downloadRows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['storage_disk', 'storage_key']))
                ->from($db->quoteName('#__alfa_item_downloads'))
                ->whereIn($db->quoteName('item_id'), $pks),
        )->loadObjectList() ?: [];

        // RETIRE THE UNITS, THEN DELETE: BOTH IN ONE TRANSACTION, per product in
        // ascending id order. After locking each product's stock records, the
        // order history is counted again under a shared lock that keeps new order
        // lines naming the product out until the transaction ends. The inventory
        // service then re-checks under its lock that every stock record is at zero
        // and nothing is reserved or pending, and removes the zero records and the
        // finished reservations with one 'retire' operation and no movement. If a
        // product gained an order line or stock since the pre-check, or the parent
        // refuses, everything rolls back and the products survive untouched: a
        // product sold in the meantime is never deleted.
        //
        // The parent stops at the first id it cannot load and still reports
        // success, so it is given only the products that exist; a request naming
        // none of them still reaches it and is answered as not found. Every
        // product retired here must be gone when the parent returns, or nothing
        // is kept.
        $deletePks = $existing === [] ? $pks : $existing;

        // Every delete veto, Alfa's and Joomla's, before the transaction and its locks (2.1.4, design 0.2): no plugin code
        // runs under them.
        if (!$this->alfaAskBeforeDelete(pks: (array) $deletePks)) {
            return false;
        }

        $db->transactionStart(true);

        try {
            foreach ($existing as $pk) {
                $service->lockItemStock(itemId: $pk);

                if ($this->orderHistoryLineCount(itemId: $pk, lock: true) > 0) {
                    $refusal = Text::sprintf('COM_ALFA_ITEM_DELETE_SOLD_MEANWHILE', $pk);

                    // Every close of this level discards what was deferred inside it.
                    TransactionState::rollBackLevel(db: $db, failure: new RuntimeException($refusal));
                    $this->setError(error: $refusal);

                    return false;
                }

                $service->retireUnits(
                    itemId:         $pk,
                    combinationIds: null,
                    sourceType:     'catalog_edit',
                    sourceId:       $pk,
                    context:        InventoryContext::ambient(),
                );
            }

            // On success $deletePks holds exactly the rows that were removed
            // (uncanDeletable ones are unset by the parent).
            $this->alfaDeleteAsked = true;
            $result = parent::delete(pks: $deletePks);

            if (!$result) {
                TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The products were not deleted.'));
                $pks = $deletePks;

                return false;
            }

            $surviving = $existing === [] ? 0 : (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_items'))
                    ->whereIn($db->quoteName('id'), $existing),
            )->loadResult();

            if ($surviving > 0) {
                TransactionState::rollBackLevel(db: $db, failure: new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_DELETE')));
                $this->setError(error: Text::_('JLIB_APPLICATION_ERROR_DELETE'));

                return false;
            }

            $pks = $deletePks;

            // The combination rows and their value links go with the product.
            if ($comboIds !== []) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName('#__alfa_item_combination_values'))
                        ->whereIn($db->quoteName('combination_id'), $comboIds),
                )->execute();

                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName('#__alfa_item_combinations'))
                        ->whereIn($db->quoteName('id'), $comboIds),
                )->execute();
            }

            TransactionState::commitLevel(db: $db);
        } catch (InventoryUnitRetirementException $retirement) {
            TransactionState::rollBackLevel(db: $db, failure: $retirement);

            $this->setError(error: $this->stockHeldMessage(
                key:      'COM_ALFA_ITEM_DELETE_STOCK_HELD',
                itemId:   $retirement->itemId,
                blockers: $retirement->blockers,
            ));

            return false;
        } catch (Throwable $e) {
            // After a deadlock in the retirement the whole transaction is gone,
            // on the server and in the driver's count.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        // A deleted product has no figures left to repair.
        foreach ($pks as $pk) {
            InventoryIntegrityNotice::clear(itemId: (int) $pk);
        }

        // The combinations' media, field configs and translations.
        if ($comboIds !== []) {
            MediaHelper::deleteMediaForItems(itemIds: $comboIds, origin: 'combination');
            FieldParams::deleteForVariants(scope: FieldParams::SCOPE_COMBINATION, scopeIds: $comboIds);
            MultilingualHelper::deleteMultilingualData(
                ids:               $comboIds,
                primaryColumnName: 'id_item_combination',
                tableName:         '#__alfa_item_combinations',
            );
        }

        // Catalog children and pivots keyed by the item.
        foreach ([
            '#__alfa_items_prices' => 'item_id',
            '#__alfa_item_option_values' => 'item_id',
            '#__alfa_item_info_values' => 'item_id',
            '#__alfa_item_downloads' => 'item_id',
            '#__alfa_items_categories' => 'item_id',
            '#__alfa_items_manufacturers' => 'item_id',
            '#__alfa_items_usergroups' => 'item_id',
            '#__alfa_items_users' => 'item_id',
        ] as $table => $column) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($table))
                    ->whereIn($db->quoteName($column), $pks),
            )->execute();
        }

        // LIVE CART LINES HOLDING THE ITEM, and the clocks of the carts that
        // lose one. Cart storage is owned by CartRepository; this model states
        // the intent and the repository knows that a line takes its field rows
        // with it and that a changed cart must be stamped.
        //
        // POSITION IS DELIBERATE. This runs AFTER the item transaction has
        // committed, exactly where it ran before. The repository is
        // savepoint-aware, which would make it safe to run inside that
        // transaction, but moving it there would change when a cart is emptied
        // relative to the item disappearing: a separate decision, not a
        // side effect of extraction.
        //
        // The set is the REQUESTED item ids, not the surviving $pks, which is
        // what the pre-captured line ids described before. That preserves
        // today's behaviour including its edge: an item the parent refused to
        // delete still loses its cart lines.
        (new CartRepository($db))->removeLinesForItems($requestedItemIds);

        // Storage objects: GC only when nothing references the key
        // (sold entitlement snapshots keep their objects alive).
        foreach ($downloadRows as $downloadRow) {
            Storage::deleteIfOrphaned(disk: $downloadRow->storage_disk, key: $downloadRow->storage_key);
        }

        // Remove the items' projection rows in one fan-out. FK CASCADE already
        // handles hard deletes; this is the safety net for soft-delete / trash.
        (new ProjectionManager())->deleteForItems(itemIds: $pks);

        // MULTILINGUAL: remove the per-language rows for the deleted items.
        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_item',
            tableName:         '#__alfa_items',
        );

        // Remove the items' media (rows; files when media_full_deletion is on).
        MediaHelper::deleteMediaForItems($pks, 'item');

        // Remove the items' field plugin configs (item-level + variant rows).
        FieldParams::deleteForItems(itemIds: $pks);

        // The membership changed, so the cached trees are wrong
        //
        // CategoryHelper caches category trees AND their product counts
        // persistently, with a hard-coded one-year lifetime and caching forced
        // on regardless of the site's global setting. save() and
        // batchCategory() already clear it when they change which items belong
        // where; publishing, unpublishing, trashing, archiving and deleting
        // change which items COUNT as members, which is the same fact from the
        // tree's point of view.
        //
        // Cleared unconditionally rather than only for items that have
        // categories: clearCache() cleans the whole group in one call whatever
        // id it is given, over-invalidating is harmless, and under-invalidating
        // is the defect being fixed. Measured on the demo: a trashed product
        // kept being counted (34 served against 33 recomputed), and a DELETED
        // one kept its place too.
        CategoryHelper::clearCache();

        return true;
    }

    /**
     * Load all price rows for an item from #__alfa_items_prices.
     *
     * @param int $id The item id.
     *
     * @return array An array of price rows (associative), empty if id is invalid.
     *
     * @since  1.0.0
     */
    public function getPrices($id)
    {
        $id = intval($id);
        if ($id <= 0) {
            return [];
        }

        // Get the database object
        $db = $this->getDatabase();

        // Build the query to select all relevant fields. ITEM-LEVEL rows only:
        // combination price rows share this item_id but belong to the
        // combinations editor; without the filter they'd surface in the item's
        // own price editor (and get duplicated as item-level rows on save).
        $query = $db->getQuery(true);
        $query
            ->select('*')
            ->from('#__alfa_items_prices')
            ->where('item_id = ' . $db->quote($id))
            ->where($db->quoteName('combination_id') . ' IS NULL');

        // Execute the query
        $db->setQuery($query);

        // Return the result as an associative array
        return $db->loadAssocList();
    }

    /**
     * Synchronise an item's price rows in #__alfa_items_prices: delete rows no
     * longer present in $prices, update existing rows (nulls included so
     * nullable fields can be cleared), and insert any new rows.
     *
     * @param int $productId The item id the prices belong to.
     * @param array $prices The incoming price rows.
     *
     * @return bool True on success, false if input is invalid.
     *
     * @since  1.0.0
     */
    public function setPrices($productId, $prices)
    {
        if (!is_array($prices) || $productId <= 0) {
            return false;
        }

        $db = $this->getDatabase();

        // Get the existing ITEM-LEVEL price IDs for the product. The
        // combination_id IS NULL filter is critical: combination price rows
        // share this item_id but are owned by the combinations editor; without
        // it, every item save would delete them (they never appear in the
        // item-level jform[prices] payload).
        $query = $db->getQuery(true);
        $query->select('id')
            ->from('#__alfa_items_prices')
            ->where('item_id = ' . intval($productId))
            ->where($db->quoteName('combination_id') . ' IS NULL');
        $db->setQuery($query);
        $existingPriceIds = $db->loadColumn();  // Array of existing price IDs

        // Extract incoming IDs from the $prices array
        $incomingIds = [];
        foreach ($prices as $price) {
            if (isset($price['id']) && intval($price['id']) > 0) {//not those except new with id 0
                $incomingIds[] = intval($price['id']);
            }
        }

        // Find differences
        $idsToDelete = array_diff($existingPriceIds, $incomingIds);

        //  Delete records that are no longer present in incoming prices array
        if (!empty($idsToDelete)) {
            $query = $db->getQuery(true);
            $query->delete('#__alfa_items_prices')->whereIn('id', $idsToDelete);
            $db->setQuery($query);
            $db->execute();
        }

        foreach ($prices as $price) {
            $priceObject = new stdClass();
            $priceObject->id = isset($price['id']) ? intval($price['id']) : 0;
            $priceObject->item_id = $productId;
            $priceObject->value = isset($price['value']) ? floatval($price['value']) : 0.0;
            $priceObject->override_value = (trim((string) ($price['override_value'] ?? '')) !== '') ? floatval($price['override_value']) : null;
            $priceObject->override_type = in_array($price['override_type'] ?? '', ['start', 'final'], true) ? $price['override_type'] : 'start';
            $priceObject->state = ((string) ($price['price_status'] ?? '1') === '1') ? 1 : 0;
            $priceObject->country_id = isset($price['country_id']) ? intval($price['country_id']) : 0;
            $priceObject->usergroup_id = isset($price['usergroup_id']) ? intval($price['usergroup_id']) : 0;
            $priceObject->user_id = isset($price['user_id']) ? intval($price['user_id']) : 0;
            $priceObject->currency_id = isset($price['currency_id']) ? intval($price['currency_id']) : 0;
            $priceObject->modify = isset($price['modify']) ? intval($price['modify']) : 0;
            $priceObject->modify_function = $price['modify_function'] ?? null;
            $priceObject->modify_type = $price['modify_type'] ?? null;
            $priceObject->publish_up = !empty($price['publish_up']) ? Factory::getDate($price['publish_up'])->toSql() : null;
            $priceObject->publish_down = !empty($price['publish_down']) ? Factory::getDate($price['publish_down'])->toSql() : null;
            $priceObject->quantity_start = !empty($price['quantity_start']) ? intval($price['quantity_start']) : null;
            $priceObject->quantity_end = !empty($price['quantity_end']) ? intval($price['quantity_end']) : null;

            $query = $db->getQuery(true);

            if ($priceObject->id > 0 && in_array($priceObject->id, $existingPriceIds)) {
                // Update existing price row.
                // $updateNulls = true so nullable fields (like publish_down,
                // quantity_end) can be explicitly cleared back to NULL.
                $updateNulls = true;
                $db->updateObject('#__alfa_items_prices', $priceObject, 'id', $updateNulls);
            } else {
                // Insert new price (unset ID to let DB auto-increment)
                unset($priceObject->id);
                $db->insertObject('#__alfa_items_prices', $priceObject);
            }
        }

        return true;
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

        // The quantity rules are exact decimals: an empty or zero minimum is 1, an
        // empty step is 1 and an empty or zero maximum is no limit. Any other value
        // is written as it stands: save() refused every value the quantity rules
        // do not allow before the row was bound.
        $blank = static fn (mixed $value): bool => $value === null || (\is_string($value) && trim($value) === '');
        $blankOrZero = static function (mixed $value) use ($blank): bool {
            if ($blank(value: $value)) {
                return true;
            }

            $parsed = QuantityDecimal::parse(value: $value);

            return $parsed !== null && QuantityDecimal::isZero(a: $parsed);
        };

        if ($blankOrZero(value: $table->quantity_min)) {
            $table->quantity_min = QuantityDecimal::canonical(value: 1);
        }

        if ($blank(value: $table->quantity_step)) {
            $table->quantity_step = QuantityDecimal::canonical(value: 1);
        }

        if ($blankOrZero(value: $table->quantity_max)) {
            $table->quantity_max = null;
        }

        if (empty($table->stock_low) || $table->stock_low <= 0) {
            $table->stock_low = null;
        }

        if ($table->id == 0 && empty($table->created_by)) {
            $table->created_by = $user->id;
        }

        $table->modified = Factory::getDate()->toSql();
        $table->modified_by = $user->id;

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        parent::prepareTable($table);
    }
}
