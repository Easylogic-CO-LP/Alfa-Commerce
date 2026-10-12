<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFailedCopyRedirect;
use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Controller\Traits\HasInlineEditTrait;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ExecuteFieldActionEvent;
use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryReservedFloorException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Versioning\VersionableControllerTrait;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Item controller class.
 *
 * @since  1.0.0
 */
class ItemController extends FormController
{
    use VersionableControllerTrait;
    use HasFormPermissions;
    use HasFailedCopyRedirect;
    use HasInlineEditTrait;

    /**
     * Base table backing the inline quick-edit endpoints.
     *
     * @since  1.5.0
     */
    private const INLINE_TABLE = '#__alfa_items';

    /**
     * Primary column of this entity's per-language aux tables.
     *
     * @since  1.5.0
     */
    private const INLINE_PRIMARY_COLUMN = 'id_item';

    /**
     * Fields the items list may quick-edit. An explicit whitelist: anything
     * else is refused before a single row is read.
     *
     * @since  1.5.0
     */
    private const INLINE_FIELDS = ['name', 'sku', 'stock'];

    /**
     * Maximum SKU length, mirroring #__alfa_items.sku varchar(255). The column
     * carries no uniqueness constraint and the edit form declares none, so the
     * inline editor enforces length only, never a rule the full form permits.
     *
     * @since  1.5.0
     */
    private const SKU_MAX_LENGTH = 255;

    /**
     * The stock editor's figures, as the item form posts them: the plain figure,
     * the per-location figures and the baselines they were rendered with.
     *
     * @since  1.10.0
     */
    private const STOCK_EDITOR_KEYS = ['stock', 'inventory_stock', 'inventory_stock_baseline'];

    protected $view_list = 'items';

    /**
     * Carry the stock editor's raw figures into the validated data.
     *
     * The per-location figures and their baselines are not declared form fields,
     * so validation drops them, and the declared plain figure comes back filtered.
     * A failed save keeps the validated data as the form's post; copied raw here,
     * the redisplayed form shows exactly what the operator typed, with the
     * baselines of its first rendering, so the next save still sees the change.
     *
     * @param BaseDatabaseModel $model The item model.
     * @param array $validData The validated form data.
     *
     * @return array The data to save.
     *
     * @since  1.10.0
     */
    protected function preSaveHook(BaseDatabaseModel $model, array $validData = []): array
    {
        $raw = $this->input->post->get('jform', [], 'raw');

        foreach (self::STOCK_EDITOR_KEYS as $key) {
            if (\is_array($raw) && \array_key_exists($key, $raw)) {
                $validData[$key] = $raw[$key];
            }
        }

        return parent::preSaveHook(model: $model, validData: $validData);
    }

    /**
     * Method to run batch operations.
     *
     * @param object $model The model.
     *
     * @return bool True if successful, false otherwise and internal error is set.
     *
     * @since  1.0.0
     */
    public function batch($model = null)
    {
        $this->checkToken();

        // Preset the redirect
        $this->setRedirect(Route::_('index.php?option=com_alfa&view=items' . $this->getRedirectToListAppend(), false));

        // Batch rewrites categories, manufacturers and visibility of every selected item, and
        // the model's batch commands check nothing per record: the permission is asked here.
        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $this->app->getIdentity())) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'), 'error');

            return false;
        }

        // Set the model
        /** @var \Joomla\Component\Content\Administrator\Model\ArticleModel $model */
        $model = $this->getModel('Item', 'Administrator', []);

        return parent::batch($model);
    }

    /**
     * Inline quick-edit: render the on-demand editor for one list field.
     *
     *   name   translatable: one input per installed content language,
     *          values exactly as stored (no fallback), so the editor shows
     *          empty where a translation is missing;
     *   sku    a single text input, offered only while the product owns its
     *          identity (no combinations);
     *   stock  one numeric input per location holding the ABSOLUTE on-hand
     *          count from the stock records, each carrying that figure as its
     *          baseline; offered only while the product is current (not
     *          archived) and is one inventory unit (no current combinations,
     *          stock managed).
     *
     *
     * @since  1.5.0
     */
    public function inlineLoad(): void
    {
        try {
            $this->assertInlineAuthorised();

            $id = $this->input->getInt('id', 0);
            $field = $this->input->getCmd('element', '');

            if ($id <= 0 || !\in_array($field, self::INLINE_FIELDS, true)) {
                throw new RuntimeException(Text::_('COM_ALFA_INLINE_UNSUPPORTED_FIELD'));
            }

            $this->assertRecordUnlocked(id: $id, table: self::INLINE_TABLE);

            if ($field === 'name') {
                $html = $this->renderInlineMultilingualEditor(
                    id:            $id,
                    table:         self::INLINE_TABLE,
                    primaryColumn: self::INLINE_PRIMARY_COLUMN,
                    field:         $field,
                );

                $this->sendInlineJson(true, '', ['html' => $html]);

                return;
            }

            if ($field === 'sku') {
                // Variants carry their own SKU; the item-level one is inert
                // once they exist, so it must not be offered for editing.
                $this->assertItemHasNoCombinations(itemId: $id);

                $this->sendInlineJson(true, '', [
                    'html' => $this->renderInlineScalarEditor(
                        value:     (string) $this->loadItemColumn(itemId: $id, column: 'sku'),
                        type:      'text',
                        maxlength: self::SKU_MAX_LENGTH,
                        hint:      Text::_('COM_ALFA_INLINE_SKU_HINT'),
                    ),
                ]);

                return;
            }

            if ($field === 'stock') {
                $this->assertStockInlineEditable(itemId: $id);

                $availability = new InventoryAvailabilityService();
                $locations = $availability->locationBreakdown(itemId: $id);

                if ($locations === []) {
                    throw new RuntimeException(Text::_('COM_ALFA_INLINE_STOCK_NO_LOCATION'));
                }

                $base = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';

                // Each input carries its rendered figure as its baseline, so the save
                // below only acts on the figures the operator actually changed.
                $inner = LayoutHelper::render(
                    'inline.locations',
                    [
                        'locations' => $locations,
                        'single' => \count($locations) <= 1,
                        'hint' => Text::sprintf(
                            'COM_ALFA_INLINE_STOCK_HINT',
                            InventoryAvailabilityService::formatQuantity(
                                value: $availability->availability(itemId: $id)['salable'],
                            ),
                        ),
                    ],
                    $base,
                );

                $this->sendInlineJson(true, '', [
                    'html' => LayoutHelper::render('inline.editor', ['inner' => $inner], $base),
                ]);

                return;
            }

            throw new RuntimeException(Text::_('COM_ALFA_INLINE_UNSUPPORTED_FIELD'));
        } catch (Throwable $e) {
            $this->sendInlineJson(false, $e->getMessage());
        }
    }

    /**
     * Inline quick-edit: persist a field edited from the items list. One
     * self-contained branch per field, each responsible for its own guards:
     * the client's markers are a UX affordance, never an authorisation.
     *
     *
     * @since  1.5.0
     */
    public function inlineSave(): void
    {
        try {
            $this->assertInlineAuthorised();

            $payload = $this->readInlineJsonBody();
            $id = (int) ($payload['id'] ?? 0);
            $field = (string) ($payload['field'] ?? '');

            if ($id <= 0) {
                throw new RuntimeException(Text::_('COM_ALFA_ERROR_INVALID_ITEM_ID'));
            }

            if (!\in_array($field, self::INLINE_FIELDS, true)) {
                throw new RuntimeException(Text::_('COM_ALFA_INLINE_UNSUPPORTED_FIELD'));
            }

            $this->assertRecordUnlocked(id: $id, table: self::INLINE_TABLE);

            if ($field === 'name') {
                $values = $payload['values'] ?? [];

                $display = $this->saveInlineMultilingualField(
                    id:            $id,
                    table:         self::INLINE_TABLE,
                    primaryColumn: self::INLINE_PRIMARY_COLUMN,
                    field:         $field,
                    values:        \is_array($values) ? $values : [],
                    aliasFields:   MultilingualAliasConfig::FIELDS[self::INLINE_TABLE],
                    aliasScope:    MultilingualAliasConfig::SCOPE[self::INLINE_TABLE] ?? [],
                );

                // The search document leads with the item name, so an inline rename
                // must rebuild it; otherwise the old name keeps matching and the
                // new one never does. Same call the full save makes.
                (new ProjectionManager())->syncItem($id);

                $this->sendInlineJson(true, Text::_('COM_ALFA_INLINE_SAVED'), ['display' => $display]);

                return;
            }

            if ($field === 'sku') {
                $this->assertItemHasNoCombinations(itemId: $id);

                // An identifier, never markup: tags are stripped outright rather
                // than reusing the form's safehtml, which would let them reach
                // order-line snapshots. No uniqueness check: the column has no
                // unique key and the edit form declares none, so the inline path
                // must not be stricter than the screen it shortcuts.
                $value = trim(InputFilter::getInstance()->clean((string) ($payload['value'] ?? ''), 'string'));

                if (mb_strlen($value) > self::SKU_MAX_LENGTH) {
                    throw new RuntimeException(
                        Text::sprintf('COM_ALFA_INLINE_SKU_TOO_LONG', self::SKU_MAX_LENGTH),
                    );
                }

                $db = Factory::getContainer()->get('DatabaseDriver');
                $modified = Factory::getDate()->toSql();
                $userId = (int) $this->app->getIdentity()->id;

                // A targeted three-column UPDATE, never Table::store(): stock and
                // available_quantity on this row are service-maintained caches and
                // a load-then-store round trip would rewrite them from a stale read.
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName(self::INLINE_TABLE))
                        ->set($db->quoteName('sku') . ' = :sku')
                        ->set($db->quoteName('modified') . ' = :modified')
                        ->set($db->quoteName('modified_by') . ' = :modifiedBy')
                        ->where($db->quoteName('id') . ' = :id')
                        ->bind(':sku', $value)
                        ->bind(':modified', $modified)
                        ->bind(':modifiedBy', $userId, ParameterType::INTEGER)
                        ->bind(':id', $id, ParameterType::INTEGER),
                )->execute();

                // The search document folds the item SKU into its haystack, so a
                // stale index would keep matching the OLD identifier for ever.
                (new ProjectionManager())->syncItem($id);

                $this->sendInlineJson(true, Text::_('COM_ALFA_INLINE_SAVED'), ['display' => $value]);

                return;
            }

            if ($field === 'stock') {
                $posted = $payload['values'] ?? null;
                $baselines = $payload['baselines'] ?? null;

                if (!\is_array($posted) || $posted === []) {
                    throw new RuntimeException(Text::_('COM_ALFA_INLINE_STOCK_INVALID'));
                }

                // Re-derived server-side: the cell's marker only decides whether a
                // pencil appears, it never decides whether a write is allowed.
                $this->assertStockInlineEditable(itemId: $id);

                $availability = new InventoryAvailabilityService();
                $offered = [];

                // Offered location id => its title, for the refusal texts.
                foreach ($availability->locationBreakdown(itemId: $id) as $row) {
                    $offered[(int) $row['location_id']] = (string) $row['title'];
                }

                // THE WHOLE PAYLOAD IS CHECKED BEFORE ANYTHING MOVES. Every posted
                // figure must come with the figure the editor rendered for it: a
                // figure equal to its baseline is one the operator left alone, and
                // leaving it alone must not undo a movement that happened while the
                // editor was open. "Equal" is decided at display precision, the
                // precision the baseline was rendered at.
                $targets = [];

                foreach ($posted as $rawLocation => $rawValue) {
                    $locationId = (int) $rawLocation;

                    // A location the editor never offered is not a location this
                    // request may write to, whatever the client posted.
                    if ($locationId <= 0 || !isset($offered[$locationId])) {
                        throw new RuntimeException(Text::_('COM_ALFA_INLINE_STOCK_NOT_EDITABLE'));
                    }

                    $raw = \is_scalar($rawValue) ? trim((string) $rawValue) : '';
                    $baseline = \is_array($baselines) && \array_key_exists($rawLocation, $baselines)
                        && \is_scalar($baselines[$rawLocation])
                        ? trim((string) $baselines[$rawLocation])
                        : '';

                    if ($raw === '' || !is_numeric($raw) || $baseline === '' || !is_numeric($baseline)) {
                        throw new RuntimeException(Text::_('COM_ALFA_INLINE_STOCK_INVALID'));
                    }

                    if (
                        InventoryAvailabilityService::formatQuantity(value: (float) $raw)
                        === InventoryAvailabilityService::formatQuantity(value: (float) $baseline)
                    ) {
                        continue;
                    }

                    $targets[$locationId] = (float) $raw;
                }

                $inventory = new InventoryService();
                $applied = 0;

                // EVERY REFUSAL THAT CAN BE KNOWN IS FOUND BEFORE THE FIRST WRITE: a
                // figure that would add stock at a disabled location, one below the
                // floor, one that would lower a location's stock below what open
                // orders have reserved there (or lower it at all while it is already
                // below them), or a product whose figures have no stock records
                // behind them (or which is held until they are repaired) refuses the
                // whole correction, and nothing moves.
                $refusals = $targets === [] ? [] : $this->inlineTargetRefusals(
                    problems: $inventory->validateTargets(
                        itemId:        $id,
                        combinationId: null,
                        targets:       $targets,
                        assumeTracked: false,
                    ),
                    titles:   $offered,
                );

                if ($targets !== [] && $refusals === []) {
                    $db = Factory::getContainer()->get('DatabaseDriver');
                    $locationId = 0;
                    $context = new InventoryContext(
                        userId: (int) $this->app->getIdentity()->id,
                        note:   Text::_('COM_ALFA_INLINE_STOCK_NOTE'),
                    );

                    // ONE CORRECTION, ALL OR NOTHING. The product's stock records are
                    // locked first; a refusal the checks above could not foresee (a
                    // location disabled, stock moved or reserved in between) rolls back
                    // every location already converged.
                    $db->transactionStart(true);

                    try {
                        $inventory->lockItemStock(itemId: $id);

                        foreach ($targets as $locationId => $target) {
                            // A changed figure is an absolute target: the kernel converges
                            // the locked record to it, so two saves of the same figure move
                            // the stock once. One command per location, so the ledger keeps
                            // saying WHERE.
                            $operation = $inventory->adjustTo(
                                itemId:        $id,
                                combinationId: null,
                                locationId:    $locationId,
                                target:        $target,
                                sourceType:    'catalog_edit',
                                sourceId:      $id,
                                context:       $context,
                            );

                            // Null means the kernel found no stock unit to converge: the
                            // product was archived, grew variants or stopped tracking since
                            // the gate ran. Read in this transaction, the product's state is
                            // the one the kernel judged, so an archived product is refused
                            // with the reason the dry run gives it.
                            if ($operation === null) {
                                throw new RuntimeException(
                                    CombinationState::isCurrent(state: $this->loadItemColumn(itemId: $id, column: 'state'))
                                        ? Text::_('COM_ALFA_INLINE_STOCK_NOT_EDITABLE')
                                        : Text::_('COM_ALFA_ITEM_STOCK_REASON_ARCHIVED'),
                                );
                            }

                            if (($operation['operation_id'] ?? null) !== null) {
                                $applied++;
                            }
                        }

                        // The adjustments' events are announced once this commits.
                        TransactionState::commitLevel(db: $db);
                    } catch (Throwable $e) {
                        // Closes the whole correction, after a deadlock too, and
                        // discards what it would have announced.
                        TransactionState::rollBackLevel(db: $db, failure: $e);
                        $applied = 0;

                        // The detail goes to the component log; the merchant gets the
                        // reason in the back office's words where one exists.
                        Log::add(
                            entry: sprintf(
                                'Inline stock correction refused for item %d at location %d, nothing applied: %s',
                                $id,
                                $locationId,
                                $e->getMessage(),
                            ),
                            priority: Log::WARNING,
                            category: 'com_alfa.inventory',
                        );

                        $refusals[] = match (true) {
                            // Stock reserved at a location while the correction waited for
                            // its lock: the figures read under that lock, in the dry run's words.
                            $e instanceof InventoryReservedFloorException => $e->operatorMessage(),
                            // Stock figures with no stock records behind them: nothing may
                            // move until the figures are repaired on the audit page.
                            $e instanceof InventoryIntegrityException => self::ledgerMissingMessage(exception: $e),
                            // The product was archived, or stopped tracking stock, while
                            // waiting for its lock: an archived one with the dry run's reason.
                            $e instanceof InventoryUnitException => $e->reason === InventoryUnitException::ITEM_ARCHIVED
                                ? Text::_('COM_ALFA_ITEM_STOCK_REASON_ARCHIVED')
                                : Text::_('COM_ALFA_INLINE_STOCK_NOT_EDITABLE'),
                            default => $e->getMessage(),
                        };
                    }
                }

                $refusals = array_values(array_unique($refusals));

                // Read back the column the list's Stock cell shows (the stock
                // projection the inventory service keeps with the stock records), so
                // the cell agrees with the refreshed Available cell beside it. Never
                // echo the input, which a concurrent movement may already have shifted.
                $fresh = InventoryAvailabilityService::formatQuantity(
                    value: (float) ($this->loadItemColumn(itemId: $id, column: 'stock') ?? 0.0),
                );

                // Available is DERIVED from the figure just written, so it goes
                // stale the moment stock moves. Ship the re-rendered cell with
                // every outcome, including a refusal, where the row must fall
                // back to what the product's figures say now, as the list does.
                $refresh = ['availability' => $this->renderAvailabilityCell(itemId: $id)];

                if ($refusals !== []) {
                    $this->sendInlineJson(
                        false,
                        Text::sprintf('COM_ALFA_INLINE_STOCK_REFUSED', implode('; ', $refusals)),
                        ['display' => $fresh, 'refresh' => $refresh],
                    );

                    return;
                }

                if ($applied === 0) {
                    $this->sendInlineJson(true, Text::_('COM_ALFA_INLINE_STOCK_UNCHANGED'), [
                        'display' => $fresh,
                        'refresh' => $refresh,
                    ]);

                    return;
                }

                $this->sendInlineJson(
                    true,
                    Text::sprintf('COM_ALFA_INLINE_STOCK_SAVED', $fresh),
                    ['display' => $fresh, 'refresh' => $refresh],
                );

                return;
            }

            throw new RuntimeException(Text::_('COM_ALFA_INLINE_UNSUPPORTED_FIELD'));
        } catch (Throwable $e) {
            $this->sendInlineJson(false, $e->getMessage());
        }
    }

    /**
     * Render the shared single-input editor chrome for a scalar inline field.
     *
     * @param string $value Current value, pre-formatted for display.
     * @param string $type Input type: "text" or "number".
     * @param string|null $step Number step (ignored for text).
     * @param int $maxlength Character cap; 0 = none.
     * @param string $hint One-line explanation under the input.
     *
     * @return string The rendered editor (input + Save/Cancel).
     *
     * @since  1.5.0
     */
    private function renderInlineScalarEditor(
        string $value,
        string $type = 'text',
        ?string $step = null,
        int $maxlength = 0,
        string $hint = '',
    ): string {
        $base = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';

        $inner = LayoutHelper::render(
            'inline.text',
            [
                'value' => $value,
                'type' => $type,
                'step' => $step,
                'maxlength' => $maxlength,
                'hint' => $hint,
            ],
            $base,
        );

        return LayoutHelper::render('inline.editor', ['inner' => $inner], $base);
    }

    /**
     * Re-render the row's Available cell through the SAME layout the list uses,
     * so a quick stock edit does not leave a derived figure contradicting the
     * one just saved.
     *
     * Server-rendered rather than assembled in JS: the cell's badge rules are
     * policy (low / backorder / out of stock / missing stock records / safety
     * stock), and a second implementation in the browser is a second place for
     * them to go wrong. The row facts are selected with the same expressions the
     * items list uses, so the refreshed cell and the listed one agree: the figure
     * is the product's salable projection, only combinations still in the
     * catalogue make it a product with combinations (an archived one is kept only
     * as the stock identity of order history), and a plain product's figure is
     * judged against the smallest quantity its quantity rules let the cart sell.
     *
     * @param int $itemId The product.
     *
     * @return string The cell markup.
     *
     * @since  1.9.0
     */
    private function renderAvailabilityCell(int $itemId): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName([
                    'a.manage_stock', 'a.available_quantity', 'a.stock_low', 'a.min_qty', 'a.backorder',
                    'a.quantity_min', 'a.quantity_step', 'a.quantity_max',
                ]))
                ->select(InventoryAvailabilityService::ledgerMissingSql(alias: 'a') . ' AS ' . $db->quoteName('ledger_missing'))
                ->select(
                    '(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_combinations', 'cc')
                    . ' WHERE ' . $db->quoteName('cc.item_id') . ' = ' . $db->quoteName('a.id')
                    . ' AND ' . CombinationState::currentSql(column: $db->quoteName('cc.state'))
                    . ') AS ' . $db->quoteName('combination_count'),
                )
                ->select(
                    '(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_combinations', 'tu')
                    . ' WHERE ' . $db->quoteName('tu.item_id') . ' = ' . $db->quoteName('a.id')
                    . ' AND ' . CombinationState::currentSql(column: $db->quoteName('tu.state'))
                    . ' AND COALESCE(' . $db->quoteName('tu.manage_stock') . ', ' . $db->quoteName('a.manage_stock')
                    . ', 0) = 1) AS ' . $db->quoteName('tracked_units'),
                )
                ->from($db->quoteName(self::INLINE_TABLE, 'a'))
                ->where($db->quoteName('a.id') . ' = :id')
                ->bind(':id', $itemId, ParameterType::INTEGER),
        )->loadObject();

        $hasVariants = (int) ($row->combination_count ?? 0) > 0;

        // The smallest quantity the cart sells of a plain product, each rule resolved
        // as the cart resolves it: the figure is out of stock while it cannot reach
        // that quantity, and null means the rules accept no quantity at all. A product
        // with combinations is judged against one unit, as the list judges it; its
        // combinations carry their own rules.
        $quantityPolicy = \Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy::class;
        $saleMinimum = $hasVariants ? 1 : $quantityPolicy::saleMinimum(
            min:  $quantityPolicy::ruleValue(combination: null, item: $row->quantity_min ?? null),
            step: $quantityPolicy::ruleValue(combination: null, item: $row->quantity_step ?? null),
            max:  $quantityPolicy::ruleValue(combination: null, item: $row->quantity_max ?? null),
        );

        // The component and client are named rather than read from the request, so
        // the layout resolves from the back-end layouts in any caller, and an
        // administrator template override applies here exactly as it does to the
        // list's own cell.
        return LayoutHelper::render(layoutFile: 'inventory.availability', displayData: [
            'id' => $itemId,
            // A product with combinations tracks stock when any of its units does.
            'manageStock' => $hasVariants
                ? (int) ($row->tracked_units ?? 0) > 0
                : (int) ($row->manage_stock ?? 0) === 1,
            'hasVariants' => $hasVariants,
            // The same column the list passes (items.available_quantity).
            'available' => (float) ($row->available_quantity ?? 0),
            // Also set while the product is held unavailable until its stock figures
            // are repaired, so the hold itself is not passed a second time.
            'ledgerMissing' => (bool) ($row->ledger_missing ?? false),
            'integrityHold' => false,
            'saleMinimum' => $saleMinimum,
            // The EFFECTIVE threshold, not the raw column: a product left on
            // "Use Global" has one, and passing null here meant the list never
            // badged it as low. Same chain the notification and the dashboard
            // card resolve.
            'stockLow' => InventoryAvailabilityService::lowStockThreshold(
                combination: null,
                item: ($row->stock_low ?? null) === null ? null : (float) $row->stock_low,
            ) ?: null,
            'minQty' => (float) ($row->min_qty ?? 0),
            'backorder' => (string) ($row->backorder ?? ''),
        ], options: [
            'component' => 'com_alfa',
            'client' => 1,
        ]);
    }

    /**
     * The merchant's reading of a stock command refused because the product's
     * stock figures have no stock records behind them, or because the product is
     * held until those figures are repaired: the kernel's own translated sentence,
     * with the figure the product shows (or, when held, showed) without records.
     *
     * @param InventoryIntegrityException $exception The kernel's refusal.
     *
     *
     * @since  1.10.0
     */
    private static function ledgerMissingMessage(InventoryIntegrityException $exception): string
    {
        return $exception->operatorMessage();
    }

    /**
     * The merchant's reading of the refusals the inventory service foresees for
     * an inline stock correction ({@see InventoryService::validateTargets()}).
     *
     * A product whose figures have no stock records behind them, or which is held
     * until they are repaired, and a figure that would lower a location's stock
     * below what open orders have reserved there, are worded by the same builders
     * as the kernel's own refusals, with the figures the finding carries. Every
     * text is raw: the items list shows the answer as text, never as markup.
     *
     * @param array<int, array<string, mixed>> $problems The service's findings: location_id and code; the stock
     *                                                   and available figures of an integrity refusal; item_id,
     *                                                   combination_id, on_hand, reserved and requested of a
     *                                                   reservation floor refusal.
     * @param array<int, string> $titles Offered location id => title.
     *
     * @return list<string> One text per distinct refusal.
     *
     * @since  1.10.0
     */
    private function inlineTargetRefusals(array $problems, array $titles): array
    {
        $texts = [];

        foreach ($problems as $problem) {
            $problem = (array) $problem;
            $locationId = (int) ($problem['location_id'] ?? 0);
            $label = $titles[$locationId] ?? ('#' . $locationId);
            $code = (string) ($problem['code'] ?? '');

            $texts[] = match ($code) {
                'location_disabled' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_LOCATION_DISABLED', $label),
                'negative_floor' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NEGATIVE', $label),
                'not_a_location' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NOT_A_LOCATION', '#' . $locationId),
                // The figure the product shows (or, when held, showed) without stock
                // records behind it, as the finding reports it.
                'ledger_missing', 'integrity_held' => InventoryIntegrityException::refusalText(
                    stock:     isset($problem['stock']) ? (float) $problem['stock'] : null,
                    available: isset($problem['available']) ? (float) $problem['available'] : null,
                    held:      $code === 'integrity_held',
                ),
                // Open orders hold reserved stock at that location: its on-hand may not
                // be lowered below what they hold, nor lowered at all while it is
                // already below it; the sentence names the smallest figure allowed.
                'reserved_floor' => InventoryReservedFloorException::refusalText(
                    itemId:        (int) ($problem['item_id'] ?? 0),
                    combinationId: (int) ($problem['combination_id'] ?? 0),
                    locationId:    $locationId,
                    onHand:        (float) ($problem['on_hand'] ?? 0.0),
                    reserved:      (float) ($problem['reserved'] ?? 0.0),
                    requested:     (float) ($problem['requested'] ?? 0.0),
                ),
                // The product stopped being one tracked stock unit since the gate ran.
                'not_tracked', 'identity_gone' => Text::_('COM_ALFA_INLINE_STOCK_NOT_EDITABLE'),
                // An archived product is kept only as the stock identity of its order
                // history: its stock takes no corrections, and the reason says so.
                'item_archived' => Text::_('COM_ALFA_ITEM_STOCK_REASON_ARCHIVED'),
                default => $code,
            };
        }

        return array_values(array_unique($texts));
    }

    /**
     * Read one scalar column off an item row.
     *
     * @param int $itemId The product.
     * @param string $column Column name.
     *
     * @return mixed The stored value, or null when the row is gone.
     *
     * @since  1.5.0
     */
    private function loadItemColumn(int $itemId, string $column): mixed
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName($column))
                ->from($db->quoteName(self::INLINE_TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $itemId, ParameterType::INTEGER),
        )->loadResult();
    }

    /**
     * Refuse any inline edit of a field a variant owns for itself.
     *
     * @param int $itemId The product.
     *
     *
     * @throws RuntimeException When the product has combinations.
     *
     * @since  1.5.0
     */
    private function assertItemHasNoCombinations(int $itemId): void
    {
        if ($this->countCombinations(itemId: $itemId) > 0) {
            throw new RuntimeException(Text::_('COM_ALFA_INLINE_SKU_VARIANT_OWNED'));
        }
    }

    /**
     * Combinations of a product that are still in the catalogue. An archived
     * combination is kept only as the stock identity of order history, so it
     * neither owns the product's SKU nor its stock: a product whose combinations
     * are all archived is edited as a product without combinations.
     *
     * @param int $itemId The product.
     *
     *
     * @since  1.5.0
     */
    private function countCombinations(int $itemId): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->where($db->quoteName('item_id') . ' = :id')
                ->where(CombinationState::currentSql(column: $db->quoteName('state')))
                ->bind(':id', $itemId, ParameterType::INTEGER),
        )->loadResult();
    }

    /**
     * The AUTHORITATIVE stock gate, for offering the editor and for accepting its
     * save alike. An archived product is refused first: it is kept only as the
     * stock identity of its order history and takes no stock corrections. Then
     * a product that stocks PER VARIANT, whose item-level figure is a read-only
     * aggregate that no single correction can own, or that does not track stock.
     * Spreading a quantity over several locations is NOT ambiguous any more; the
     * editor asks for each location by name.
     *
     * @param int $itemId The product.
     *
     *
     * @throws RuntimeException When this product's stock is not inline-editable.
     *
     * @since  1.5.0
     */
    private function assertStockInlineEditable(int $itemId): void
    {
        if (!CombinationState::isCurrent(state: $this->loadItemColumn(itemId: $itemId, column: 'state'))) {
            throw new RuntimeException(
                Text::sprintf('COM_ALFA_ITEM_STOCK_NOT_APPLIED', Text::_('COM_ALFA_ITEM_STOCK_REASON_ARCHIVED')),
            );
        }

        // Variants next: a variant product's figure is a read-only aggregate,
        // whatever the parent row's manage_stock flag happens to say.
        if (
            $this->countCombinations(itemId: $itemId) > 0
            || (int) $this->loadItemColumn(itemId: $itemId, column: 'manage_stock') !== 1
        ) {
            throw new RuntimeException(Text::_('COM_ALFA_INLINE_STOCK_NOT_EDITABLE'));
        }
    }

    /**
     * The Studio popup's own save: persists ONE combination atomically -
     * identity, logistics overrides, sparse text translations, the price
     * editor rows and the variant's own gallery (#__alfa_media,
     * origin 'combination'). Redirects back to the popup layout; the layout
     * notifies the parent item screen via postMessage.
     *
     *
     * @since  1.3.0
     */
    public function saveCombination(): void
    {
        $this->persistCombination(closeAfter: 1);
    }

    /**
     * EXPLICIT combination generation: the admin picks
     * values per variant field in the grid's Generate panel; only the missing
     * tuples are created: additive and idempotent, no orphan handling.
     * Called via AJAX; the page then re-renders through item.reload so any
     * unsaved item-form state survives the round-trip.
     *
     * @return void Emits JSON {created: N} and closes the application.
     *
     * @since  1.3.0
     */
    public function generateCombinations(): void
    {
        $this->checkToken();

        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $valuesByField = [];

        foreach ((array) $this->input->get('values', [], 'array') as $fieldId => $valueIds) {
            $valuesByField[(int) $fieldId] = array_map('intval', (array) $valueIds);
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();

        try {
            $itemId = $this->input->getInt('id', 0);

            // Dimension order is editable in the popup ONLY while the set is fresh
            // (no combinations yet); once created it's locked; reorder from the
            // live grid. Capture the pre-generation state to gate the persist.
            $wasFresh = ItemFieldsAdminHelper::getCombinationFieldIds(itemId: $itemId) === [];

            $created = ItemFieldsAdminHelper::generateCombinations(
                itemId: $itemId,
                valuesByField: $valuesByField,
            );

            // Persist the variant dimension order chosen in the popup (drag whole
            // fields); the payload key order is the chosen order. Reported back so
            // the UI reloads even when no NEW rows were created (pure reorder).
            $reordered = $wasFresh
                && ItemFieldsAdminHelper::applyGeneratorFieldOrder(
                    itemId: $itemId,
                    orderedVariantIds: array_keys($valuesByField),
                );

            // Newly generated variants contribute titles to the search document and
            // values to the facet projection; without this they exist but are
            // invisible to both until something else re-saves the item.
            (new ProjectionManager())->syncItem($itemId);

            echo new \Joomla\CMS\Response\JsonResponse(['created' => $created, 'reordered' => $reordered]);
        } catch (RuntimeException $e) {
            // Depth lock and friends: surfaced verbatim in the generator UI.
            echo new \Joomla\CMS\Response\JsonResponse($e);
        }

        $app->close();
    }

    /**
     * Lazy popup bodies (AJAX): the generator / Options / Informational
     * editors render NOTHING on item load; their HTML is fetched on first
     * open (same zero-page-weight idea as the Studio iframe).
     *
     * @return void Emits JSON {html} and closes the application.
     *
     * @since  1.3.0
     */
    public function fieldsPopup(): void
    {
        $this->checkToken();

        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $itemId = $this->input->getInt('id', 0);
        $type = $this->input->getCmd('type', 'generator');

        $fields = ItemFieldsAdminHelper::getFieldsPopupData(
            itemId: $itemId,
            categoryIds: ItemFieldsAdminHelper::getItemCategoryIds(itemId: $itemId),
        );
        $comboFieldIds = ItemFieldsAdminHelper::getCombinationFieldIds(itemId: $itemId);

        $choiceFields = array_values(array_filter(
            $fields,
            static fn ($f) => (int) $f->informational !== 1 && !empty($f->values),
        ));

        // A field the item still uses after being unpublished or re-scoped stays
        // in the PICKERS (with only the values its saved rows depend on), so the
        // merchant can still clean those rows up. The GENERATOR never sees it:
        // nothing new may be built on a dimension the catalogue no longer offers.
        $liveChoiceFields = array_values(array_filter($choiceFields, static fn ($f) => empty($f->retired)));

        $layout = new \Joomla\CMS\Layout\FileLayout(
            'combinations.' . (['generator' => 'generator', 'options' => 'options_editor', 'info' => 'info_editor', 'visibility' => 'visibility_editor'][$type] ?? 'generator'),
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );

        $html = $layout->render(match ($type) {
            'options' => [
                'optionFields' => array_values(array_filter(
                    $choiceFields,
                    static fn ($f) => !in_array((int) $f->id, $comboFieldIds, true),
                )),
                'itemId' => $itemId,
            ],
            'info' => [
                'infoFields' => array_values(array_filter(
                    $fields,
                    static fn ($f) => (int) $f->informational === 1 && !empty($f->values),
                )),
                'itemId' => $itemId,
            ],
            'visibility' => ItemFieldsAdminHelper::getShowonPopupData(
                itemId: $itemId,
                fieldId: $this->input->getInt('field_id', 0),
            ) ?? ['field' => null, 'itemId' => $itemId, 'rule' => ''],
            default => [
                'choiceFields' => $liveChoiceFields,
                'comboFieldIds' => $comboFieldIds,
                'itemId' => $itemId,
            ],
        });

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo new \Joomla\CMS\Response\JsonResponse(['html' => $html]);
        $app->close();
    }

    /**
     * GATED delivery of studio (admin authoring) JavaScript. The studio bundle is
     * NOT a public static asset: it is served ONLY here, behind session + ACL, so
     * a buyer (or anyone not an authenticated admin) receives ZERO bytes of admin
     * code: not "hidden", but never delivered. The files also sit behind an
     * .htaccess deny; this controller reads them from disk, bypassing that.
     *
     * mode/composition on the client is a UI hint; THIS is the real boundary,
     * alongside checkToken + authorise on every admin action.
     *
     * @return void Streams `text/javascript` to an admin; 403/404 otherwise.
     *
     * @since  1.6.0
     */
    public function studioAsset(): void
    {
        $app = Factory::getApplication();

        // Admin only. A script READ, but gated exactly like the studio it powers.
        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            $app->setHeader('status', 403, true)->sendHeaders();
            echo '/* forbidden */';
            $app->close();
        }

        // Whitelist: studio/*.js by a safe relative name; no traversal, no other tree.
        $file = (string) $app->getInput()->getCmd('file', '');
        $base = JPATH_ROOT . '/media/plg_alfa-item-fields_configurator3d/js/studio/';
        $path = ($file !== '' && preg_match('#^[a-z0-9][a-z0-9/_-]*$#i', $file) && strpos($file, '..') === false)
            ? realpath($base . $file . '.js')
            : false;

        if ($path === false || strpos($path, realpath($base) . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            $app->setHeader('status', 404, true)->sendHeaders();
            echo '/* not found */';
            $app->close();
        }

        $app->setHeader('Content-Type', 'text/javascript; charset=utf-8', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHeader('Cache-Control', 'private, no-cache, must-revalidate', true)
            ->sendHeaders();
        readfile($path);
        $app->close();
    }

    /**
     * Execute a data-submitting item-field action (a save) and return its JSON
     * result. Actions that open a UI go through the action page (view=action)
     * and run once there; this endpoint is only for the command side: a form
     * submission carrying a payload, like the configurator's "configure_save".
     *
     *   1. Read the JSON body (id = FIELD id, action, data)
     *   2. Resolve the scope (item|combination|option|order) + ids
     *   3. Refuse a request that is not a POST, unless the field's plugin
     *      offers the action read-only for that row
     *   4. Boot the field plugin, dispatch onExecuteFieldAction
     *   5. Return the event's JSON response (message / refresh)
     *
     * @return void Emits JSON and closes the application.
     *
     * @since  1.3.0
     */
    public function executeFieldAction(): void
    {
        $app = Factory::getApplication();

        // JSON body (the normal save path), or multipart/form-data when the
        // action carries FILES (e.g. the configurator's asset upload): then the
        // fields arrive as regular POST vars and the files via the input.
        $json = json_decode(file_get_contents('php://input'), true) ?: [];
        $fieldId = (int) ($json['id'] ?? $app->getInput()->getInt('id', 0));
        $action = (string) ($json['action'] ?? $app->getInput()->getString('action', ''));
        $data = (array) ($json['data'] ?? $app->getInput()->post->get('data', [], 'array'));

        try {
            if (!\Joomla\CMS\Session\Session::checkToken('request')) {
                throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
            }

            // Scope first (input only). Every field action can write the product's field config:
            // an order-scope save lands on the item (ItemFieldsPlugin::saveConfig), so editing
            // items is always required; an order-scope action is also work on the order.
            $scope = in_array($data['scope'] ?? '', ['item', 'combination', 'option', 'order'], true)
                ? $data['scope'] : 'item';
            $identity = $app->getIdentity();
            $mayAct = AlfaHelper::can(section: 'items', action: 'edit', user: $identity)
                && ($scope !== 'order' || AlfaHelper::can(section: 'orders', action: 'edit', user: $identity));

            if (!$mayAct) {
                throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
            }

            $db = Factory::getContainer()->get('DatabaseDriver');

            // Load field
            $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->qn('#__alfa_item_fields'))
                    ->where($db->qn('id') . ' = ' . $fieldId),
            );
            $field = $db->loadObject();

            if (!$field) {
                throw new RuntimeException("Item field #{$fieldId} not found.");
            }

            // Resolve the scope row
            $scopeId = (int) ($data['scope_id'] ?? 0);
            $itemId = (int) ($data['item_id'] ?? 0);

            // An action changes state unless the field's plugin offers it
            // read-only for this row: such an action answers only a POST, and
            // is refused here before the plugin is asked.
            if (strtoupper((string) $app->getInput()->getMethod()) !== 'POST'
                && ActionRegistry::actionMutates(
                    offered: ActionRegistry::getItemFieldActions(field: $field, scope: $scope, scopeId: $scopeId, itemId: $itemId),
                    actionId: $action,
                )) {
                throw new RuntimeException(Text::_('COM_ALFA_PANEL_TASK_POST_ONLY'), 405);
            }

            // Boot plugin + dispatch
            $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');

            if (!$plugin || !method_exists($plugin, 'onExecuteFieldAction')) {
                throw new RuntimeException("Item-field plugin \"{$field->type}\" not found or outdated.");
            }

            $event = new ExecuteFieldActionEvent('onExecuteFieldAction', [
                'action' => $action,
                'scope' => $scope,
                'scopeId' => $scopeId,
                'itemId' => $itemId,
                'field' => $field,
                'data' => $data,
            ]);

            $plugin->onExecuteFieldAction($event);

            header('Content-Type: application/json; charset=utf-8');
            echo $event->toResponseJson();

            $app->close();
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            $app->close();
        }
    }

    /**
     * ADD option values (AJAX, picker popup): only missing rows are created,
     * with no delta: additive and idempotent like the generator. The page
     * then re-renders through item.reload.
     *
     * @return void Emits JSON {added: N} and closes the application.
     *
     * @since  1.3.0
     */
    public function addOptions(): void
    {
        $this->checkToken();

        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $itemId = $this->input->getInt('id', 0);

        $added = ItemFieldsAdminHelper::addItemOptions(
            itemId: $itemId,
            valueIds: array_map('intval', (array) $this->input->get('values', [], 'array')),
        );

        // These rows are committed by THIS request and feed the item-field value
        // index, which the search document reads. The follow-up item.reload is core
        // FormController::reload; it never calls save(), so abandoning the edit form
        // afterwards would otherwise leave them permanently unprojected.
        if ($added > 0 && $itemId > 0) {
            (new ProjectionManager())->syncItem($itemId);
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo new \Joomla\CMS\Response\JsonResponse(['added' => $added]);
        $app->close();
    }

    /**
     * ADD informational values (AJAX, picker popup): only missing rows are
     * created: additive and idempotent like the generator. The page then
     * re-renders through item.reload.
     *
     * @return void Emits JSON {added: N} and closes the application.
     *
     * @since  1.3.0
     */
    public function addInfoValues(): void
    {
        $this->checkToken();

        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $itemId = $this->input->getInt('id', 0);

        $added = ItemFieldsAdminHelper::addItemInfoValues(
            itemId: $itemId,
            valueIds: array_map('intval', (array) $this->input->get('values', [], 'array')),
        );

        // These rows are committed by THIS request and feed the item-field value
        // index, which the search document reads. The follow-up item.reload is core
        // FormController::reload; it never calls save(), so abandoning the edit form
        // afterwards would otherwise leave them permanently unprojected.
        if ($added > 0 && $itemId > 0) {
            (new ProjectionManager())->syncItem($itemId);
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo new \Joomla\CMS\Response\JsonResponse(['added' => $added]);
        $app->close();
    }

    /**
     * SAVE a field's per-item ShowOn (visibility) rule (AJAX, visibility popup).
     * A clean dedicated command (no plugin dispatch) mirroring addOptions: the
     * rule rides as a raw JSON string and the helper merges it into the field's
     * item-scoped config.
     *
     * @return void Emits JSON {saved: bool} and closes the application.
     *
     * @since  1.3.0
     */
    public function saveVisibility(): void
    {
        $this->checkToken();

        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $itemId = $this->input->getInt('id', 0);
        $fieldId = $this->input->getInt('field_id', 0);

        $saved = ItemFieldsAdminHelper::saveItemShowon(
            itemId: $itemId,
            fieldId: $fieldId,
            rule: (string) $this->input->get('showon', '', 'raw'),
        );

        // Return the new inline display (state + human summary) so the editor swaps
        // it in place: no full reload, no JS re-implementation of the wording.
        $fields = ItemFieldsAdminHelper::getFieldsPopupData(
            itemId: $itemId,
            categoryIds: ItemFieldsAdminHelper::getItemCategoryIds(itemId: $itemId),
        );
        $display = ItemFieldsAdminHelper::getShowonDisplay(itemId: $itemId, fields: $fields)[$fieldId]
            ?? ['state' => 'none', 'summary' => ''];

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo new \Joomla\CMS\Response\JsonResponse([
            'saved' => $saved,
            'state' => $display['state'],
            'summary' => $display['summary'],
        ]);
        $app->close();
    }

    /**
     * LIVE price-preview refresh for the prices editor: returns the engine
     * coefficients (final = a*net + b), the tax factor and the applied
     * tax/discount names for ONE row's dimensions, called by prices.js when
     * a Currency/Country/Group/User select or quantity tier changes, so the
     * editor never shows a stale context.
     *
     * @return void Emits JSON and closes the application.
     *
     * @since  1.3.0
     */
    public function pricePreview(): void
    {
        $this->checkToken();

        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $preview = \Alfa\Component\Alfa\Administrator\Field\AlfapricesField::rowPreview(
            itemId: $this->input->getInt('id', 0),
            row: [
                'currency_id' => $this->input->getInt('currency_id', 0),
                'country_id' => $this->input->getInt('country_id', 0),
                'usergroup_id' => $this->input->getInt('usergroup_id', 0),
                'user_id' => $this->input->getInt('user_id', 0),
                'quantity_start' => $this->input->getString('quantity_start', ''),
                'quantity_end' => $this->input->getString('quantity_end', ''),
            ],
        );

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo new \Joomla\CMS\Response\JsonResponse($preview);
        $app->close();
    }

    /**
     * Studio Apply: save the combination and STAY in the dialog.
     *
     *
     * @since  1.3.0
     */
    public function applyCombination(): void
    {
        $this->persistCombination(closeAfter: 0);
    }

    /**
     * Shared Studio save body.
     *
     * The stock section is planned first and every refusal it can know of is
     * reported before anything is written. The combination is then saved in one
     * transaction, a tracking switch and its opening figures included. Changed
     * tracked figures are applied after the save against the figures the Studio
     * rendered, so stock that moved while the Studio was open is not undone.
     *
     * @param int $closeAfter 1 = the popup tells the parent to close after saving.
     *
     *
     * @since  1.3.0
     */
    protected function persistCombination(int $closeAfter): void
    {
        $this->checkToken();

        $app = Factory::getApplication();
        $itemId = $this->input->getInt('id', 0);
        $combinationId = $this->input->getInt('combination_id', 0);

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        // 'raw' keeps editor HTML and the per-language flat keys intact.
        $jform = $this->input->post->get('jform', [], 'raw');
        $row = (array) (($jform['combinations'] ?? [])[$combinationId] ?? []);
        $priceRows = (array) (($jform['combination_prices'] ?? [])[$combinationId] ?? []);

        // The Studio's stock section: tracked per-location figures with the figures
        // they were rendered with, or opening figures while tracking is switched on.
        $postedStock = (array) ($jform['combination_inventory_stock'] ?? []);
        $postedBaseline = (array) ($jform['combination_inventory_stock_baseline'] ?? []);
        $postedOpening = (array) ($jform['combination_opening_stock'] ?? []);

        // EVERYTHING KNOWABLE IS REFUSED BEFORE ANYTHING IS WRITTEN: a tracking
        // switch without its opening figures or over open reservations, figures
        // that disagree with the stock records, a stock target the inventory
        // service would refuse. A refused save writes no column, no media and no
        // stock, and says what is required.
        $plan = ItemFieldsAdminHelper::studioStockPlan(
            itemId:         $itemId,
            combinationId:  $combinationId,
            row:            $row,
            postedStock:    $postedStock,
            postedBaseline: $postedBaseline,
            postedOpening:  $postedOpening,
        );

        if ($plan['refusals'] !== []) {
            foreach ($plan['refusals'] as $refusal) {
                $app->enqueueMessage($refusal, 'error');
            }

            $this->redirectToStudio(itemId: $itemId, combinationId: $combinationId, saved: false, closeAfter: $closeAfter);

            return;
        }

        // Switching tracking on books the opening figures inside the save's own
        // transaction, with the switch itself: they commit or roll back together.
        $opening = $plan['transition'] === 'on';
        $saved = false;

        try {
            $saved = ItemFieldsAdminHelper::saveSingleCombination(
                itemId:         $itemId,
                combinationId:  $combinationId,
                row:            $row,
                priceRows:      $priceRows,
                openingTargets: $opening ? $plan['targets'] : null,
            );

            if (!$saved) {
                $app->enqueueMessage(Text::_('COM_ALFA_COMBINATION_SAVE_FAILED'), 'error');
            }
        } catch (RuntimeException $e) {
            // The save rolled back as a whole; nothing after it may run.
            Log::add(
                entry: sprintf('Combination %d of item %d was not saved: %s', $combinationId, $itemId, $e->getMessage()),
                priority: Log::ERROR,
                category: 'com_alfa.catalog',
            );

            $app->enqueueMessage($e->getMessage(), 'error');
        }

        if ($saved) {
            // The variant's own gallery: same machinery as the item's media,
            // keyed by the 'combination' origin.
            MediaHelper::saveMedia(
                mediaData:      $jform['media'] ?? [],
                droppedMedia:   $this->input->files->get('jform')['uploads'] ?? [],
                itemId:         $combinationId,
                mediaOrigin:    'combination',
                customFileName: (string) ($row['gtin'] ?? ''),
            );

            // Changed tracked figures converge after the save. A refusal here is
            // not a failed save: the combination is stored, and the operator is
            // told its stock did not change and why.
            if (!$opening && $postedStock !== []) {
                try {
                    $notApplied = ItemFieldsAdminHelper::applyCombinationLocationStock(
                        itemId:        $itemId,
                        combinationId: $combinationId,
                        posted:        $postedStock,
                        baseline:      $postedBaseline,
                    );

                    foreach ($notApplied as $warning) {
                        $app->enqueueMessage($warning, 'warning');
                    }
                } catch (InventoryIntegrityException $e) {
                    Log::add(
                        entry: sprintf('Combination %d of item %d saved, but its stock was refused: %s', $combinationId, $itemId, $e->getMessage()),
                        priority: Log::ERROR,
                        category: 'com_alfa.inventory',
                    );

                    $app->enqueueMessage(self::ledgerMissingMessage(exception: $e), 'error');
                } catch (Throwable $e) {
                    Log::add(
                        entry: sprintf('Combination %d of item %d saved, but its stock was refused: %s', $combinationId, $itemId, $e->getMessage()),
                        priority: Log::ERROR,
                        category: 'com_alfa.inventory',
                    );

                    // Every refusal that reaches here is a complete sentence, so it
                    // follows the saved notice whole.
                    $app->enqueueMessage(Text::sprintf('COM_ALFA_ITEM_SAVED_STOCK_NOT_CHANGED', $e->getMessage()), 'error');
                }
            }

            // Tracking switched off keeps the stock records as they are; say which
            // quantities are no longer counted for sale.
            $tracking = ItemFieldsAdminHelper::lastTrackingChange();

            if ((int) ($tracking['kept_rows'] ?? 0) > 0) {
                $app->enqueueMessage(
                    Text::sprintf(
                        'COM_ALFA_INVENTORY_TRACKING_OFF_STOCK_KEPT',
                        (int) $tracking['kept_rows'],
                        InventoryAvailabilityService::formatQuantity(value: (float) ($tracking['kept_on_hand'] ?? 0.0)),
                    ),
                    'warning',
                );
            }

            // Variant downloads metadata (title/limits/state) rides the
            // Studio form like every other surface; bytes go via AJAX.
            Entitlements::saveItemDownloadsMeta(
                itemId:  $itemId,
                payload: (array) ($jform['item_downloads'] ?? []),
            );

            // Variant titles and identity are part of the item's search document,
            // so a Studio save must rebuild it; otherwise the renamed variant is
            // unfindable and the old title keeps matching.
            (new ProjectionManager())->syncItem($itemId);

            $app->enqueueMessage(Text::_('COM_ALFA_COMBINATION_SAVED'));
        }

        $this->redirectToStudio(itemId: $itemId, combinationId: $combinationId, saved: $saved, closeAfter: $closeAfter);
    }

    /**
     * Send the Studio popup back to its own layout, which reports the outcome to
     * the item screen.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination.
     * @param bool $saved Whether the combination was saved.
     * @param int $closeAfter 1 = the popup tells the parent to close.
     *
     *
     * @since  1.10.0
     */
    private function redirectToStudio(int $itemId, int $combinationId, bool $saved, int $closeAfter): void
    {
        $this->setRedirect(Route::_(
            'index.php?option=com_alfa&view=item&layout=edit_combination&tmpl=component'
            . '&id=' . $itemId . '&combination_id=' . $combinationId
            . '&alfasaved=' . ($saved ? '1' : '0') . '&close_after=' . $closeAfter,
            false,
        ));
    }
}
