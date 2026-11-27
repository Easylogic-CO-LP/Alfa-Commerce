<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Card\CardRegistry;
use Alfa\Component\Alfa\Administrator\Event\Catalog\CombinationsChangedEvent;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\ItemModel;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * The one way a product's combination (a variant) is created, changed or removed: the variant window, the REST API
 * and an extension's code build the same write.
 *
 *     $result = CombinationService::create(itemId: 42)
 *         ->values([5 => 12, 6 => 23])
 *         ->set('sku', 'SMX-WHT-512')
 *         ->prices([['_mode' => 'adjust', 'operation' => '+', 'value' => '250.00']])
 *         ->save();
 *
 *     $result = CombinationService::change(itemId: 42, combinationId: 305)->set('sku', 'SMX-WHT-512-EU')->save();
 *
 *     $result = CombinationService::remove(itemId: 42, combinationIds: [305]);
 *
 * The fields are the variant window's (sku, gtin, mpn, the overrides, the quantity rules, manage_stock, a name by its
 * language key name_en_gb), plus state and default. A new combination is made by its values, one value of each of the
 * product's variant dimensions, on the generator's rules (an eligible field, not an option of the product, a published
 * value, the dimensions the product's combinations already have); a combination's values are what it is and never
 * change. The save runs the window's own rules and refusals in one transaction: the stock section's plan (a tracking
 * switch and its opening figures, figures that disagree with the stock records), the quantity rules, the price rows;
 * then changed stock figures converge as the window converges them (a refusal there is a warning, the save stands).
 * onAlfaCombinationsChanged is raised once, after the commit. The caller checks its own permission.
 *
 * @api CHANGE CATALOGUE: create, change or remove a product's combination with the variant window's rules.
 *
 * @since  2.3.0
 */
final class CombinationService
{
    /**
     * The product.
     *
     * @since  2.3.0
     */
    private int $itemId;

    /**
     * The combination changed; 0 for a new one.
     *
     * @since  2.3.0
     */
    private int $combinationId;

    /**
     * A new combination's values: field id => value id.
     *
     * @var array<int, int>
     * @since  2.3.0
     */
    private array $values = [];

    /**
     * The window's fields.
     *
     * @var array<string, mixed>
     * @since  2.3.0
     */
    private array $row = [];

    /**
     * The price rows, null to leave them as they are.
     *
     * @var list<array>|null
     * @since  2.3.0
     */
    private ?array $prices = null;

    /**
     * Tracked stock figures by location and the figures they were read with.
     *
     * @var array<int, string>
     * @since  2.3.0
     */
    private array $stock = [];

    /**
     * @var array<int, string>
     * @since  2.3.0
     */
    private array $stockBaseline = [];

    /**
     * Opening stock by location: of a switch of tracking on, or a new combination's first stock when its product
     * already tracks stock.
     *
     * @var array<int, string>
     * @since  2.3.0
     */
    private array $opening = [];

    /**
     * Where the write comes from, for onAlfaCombinationsChanged: admin, api or sync.
     *
     * @since  2.3.0
     */
    private string $origin = 'admin';

    /**
     * @since  2.3.0
     */
    private function __construct(int $itemId, int $combinationId)
    {
        $this->itemId = max(0, $itemId);
        $this->combinationId = max(0, $combinationId);
    }

    /**
     * A new combination of a product, made by its values ({@see self::values()}).
     *
     * @param int $itemId The product.
     *
     * @since  2.3.0
     */
    public static function create(int $itemId): self
    {
        return new self(itemId: $itemId, combinationId: 0);
    }

    /**
     * A change of a combination.
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination.
     *
     * @since  2.3.0
     */
    public static function change(int $itemId, int $combinationId): self
    {
        return new self(itemId: $itemId, combinationId: $combinationId);
    }

    /**
     * Remove combinations of a product as the variants' grid removes them: refused while one holds stock; one that
     * order history names is archived (kept as the stock identity that history is fulfilled against), the others are
     * deleted with their value links, price rows, cart lines, media and field settings.
     *
     * @param int $itemId The product.
     * @param list<int> $combinationIds The combinations.
     * @param string $origin admin, api or sync.
     *
     * @since  2.3.0
     */
    public static function remove(int $itemId, array $combinationIds, string $origin = 'admin'): CombinationWriteResult
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $combinationIds), static fn (int $id): bool => $id > 0)));
        $current = array_map('intval', array_keys(self::currentRows(itemId: $itemId)));
        $foreign = array_diff($ids, $current);

        if ($ids === [] || $foreign !== []) {
            return new CombinationWriteResult(saved: false, refusals: [new Refusal(code: 'not_found', sentence: Text::sprintf(
                'COM_ALFA_COMBINATION_NOT_CURRENT',
                InventoryAvailabilityService::formatQuantity(value: (float) ($foreign === [] ? 0 : reset($foreign))),
            ), status: Refusal::NOT_FOUND)]);
        }

        $refusals = ItemFieldsAdminHelper::combinationSaveRefusals(itemId: $itemId, payload: [], deletedCombinationIds: implode(',', $ids));

        if ($refusals !== []) {
            return new CombinationWriteResult(saved: false, refusals: array_map(
                static fn (string $sentence): Refusal => new Refusal(code: 'stock_held', sentence: $sentence, status: Refusal::CONFLICT),
                $refusals,
            ));
        }

        $db = self::db();
        $skusBefore = SkuIndex::skusOf(itemId: $itemId);
        $nested = TransactionState::isOpen(db: $db);
        $change = ProjectionManager::markChange(itemId: $itemId);

        try {
            $db->transactionStart(true);

            try {
                ItemFieldsAdminHelper::saveCombinations(itemId: $itemId, payload: [], defaultId: '', deletedCombinationIds: implode(',', $ids));
                // A variant removed or archived is one the product no longer sells: a change filter[changed_since] sees.
                CommerceChange::mark(db: $db, itemIds: [$itemId]);
                ItemFieldsAdminHelper::ensureOnePublishedDefault(itemId: $itemId);
                SkuIndex::keep(itemId: $itemId, before: $skusBefore);
                $left = self::currentRows(itemId: $itemId) + self::archivedRows(itemId: $itemId, ids: $ids);
                $archived = array_values(array_intersect($ids, array_map('intval', array_keys($left))));
                $removed = array_values(array_diff($ids, $archived));
                self::announce(db: $db, itemId: $itemId, created: [], changed: [], archived: $archived, removed: $removed, origin: $origin);
                TransactionState::commitLevel(db: $db);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $db, failure: $e);

                throw $e;
            }
        } catch (RuntimeException $e) {
            // A failure that ended the caller's transaction is the caller's to hear.
            if ($nested && !TransactionState::isOpen(db: $db)) {
                throw $e;
            }

            return new CombinationWriteResult(saved: false, refusals: [self::refusalOf(failure: $e)]);
        } finally {
            self::afterChange(change: $change, nested: $nested);
        }

        return new CombinationWriteResult(saved: true, removed: $ids, archived: $archived);
    }

    /**
     * A new combination's values, one of each of the product's variant dimensions.
     *
     * @param array<int|string, int|string> $values field id => value id
     *
     * @since  2.3.0
     */
    public function values(array $values): self
    {
        foreach ($values as $fieldId => $valueId) {
            $this->values[(int) $fieldId] = (int) $valueId;
        }

        return $this;
    }

    /**
     * One field, by the variant window's name (a name by its language key, name_en_gb), or state (1 published, 0 not)
     * or default (true: the combination the product opens on).
     *
     * @param string $field The field.
     * @param mixed $value Its value.
     *
     * @since  2.3.0
     */
    public function set(string $field, mixed $value): self
    {
        $this->row[$field] = $value;

        return $this;
    }

    /**
     * Several fields, by the variant window's names.
     *
     * @param array<string, mixed> $fields The fields.
     *
     * @since  2.3.0
     */
    public function with(array $fields): self
    {
        $this->row = $fields + $this->row;

        return $this;
    }

    /**
     * The combination's price rows, in the window's keys (_mode price or adjust, operation, value, override_value,
     * override_type, adjust_target for an adjust, price_status, the scope and the quantity band): rows kept by id, rows
     * not sent removed.
     *
     * @param list<array> $rows The rows.
     *
     * @since  2.3.0
     */
    public function prices(array $rows): self
    {
        $this->prices = array_values($rows);

        return $this;
    }

    /**
     * Tracked stock figures by location, each with the figure it was read with: a figure equal to it is no command.
     *
     * @param array<int, string> $figures location id => figure
     * @param array<int, string> $read location id => the figure read
     *
     * @since  2.3.0
     */
    public function stock(array $figures, array $read): self
    {
        $this->stock = $figures;
        $this->stockBaseline = $read;

        return $this;
    }

    /**
     * Opening stock by location: the opening figures of a switch of tracking on, or a new combination's first stock
     * when its product already tracks stock (applied after the save, as changed figures are).
     *
     * @param array<int, string> $figures location id => figure
     *
     * @since  2.3.0
     */
    public function opening(array $figures): self
    {
        $this->opening = $figures;

        return $this;
    }

    /**
     * Where the write comes from, for onAlfaCombinationsChanged.
     *
     * @param string $origin admin, api or sync.
     *
     * @since  2.3.0
     */
    public function from(string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    /**
     * What the save would refuse before anything is written: the stock section's plan (a tracking switch without
     * its opening figures or over open reservations, figures that disagree with the stock records, a stock target the
     * inventory service would refuse); for a new combination, its values; the price rows sent, judged as the item
     * screen judges the product's own (a figure the columns cannot store, a period whose end is not after its start).
     * Nothing is written.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    public function refusals(): array
    {
        if ($this->combinationId === 0) {
            $refusals = $this->valueRefusals();
        } elseif (!isset(self::currentRows(itemId: $this->itemId)[$this->combinationId])) {
            return [new Refusal(
                code: 'not_found',
                sentence: Text::sprintf('COM_ALFA_COMBINATION_NOT_CURRENT', (string) $this->combinationId),
                status: Refusal::NOT_FOUND,
            )];
        } else {
            $refusals = array_map(
                static fn (string $sentence): Refusal => new Refusal(code: 'refused', sentence: $sentence, status: Refusal::CONFLICT),
                $this->plan(combinationId: $this->combinationId)['refusals'],
            );
        }

        foreach ($this->prices === null ? [] : ItemModel::priceRefusals(rows: $this->prices) as $sentence) {
            $refusals[] = new Refusal(code: 'price_row', sentence: $sentence, pointer: '/prices');
        }

        return $refusals;
    }

    /**
     * Save the combination.
     *
     * @since  2.3.0
     */
    public function save(): CombinationWriteResult
    {
        $refusals = $this->refusals();

        if ($refusals !== []) {
            return new CombinationWriteResult(saved: false, id: $this->combinationId, refusals: $refusals);
        }

        $db = self::db();
        $nested = TransactionState::isOpen(db: $db);
        $change = ProjectionManager::markChange(itemId: $this->itemId);
        $combinationId = $this->combinationId;
        $warnings = [];

        try {
            $db->transactionStart(true);

            try {
                if ($combinationId === 0) {
                    $combinationId = $this->make(db: $db);

                    // A new combination whose stock is already tracked (its product tracks stock, and the save does not
                    // switch it on) takes its opening figures as its first stock, converged after the save.
                    if ($this->opening !== [] && $this->plan(combinationId: $combinationId)['transition'] !== 'on') {
                        $this->stock = $this->stock !== [] ? $this->stock : $this->opening;
                        $this->stockBaseline = array_fill_keys(array_keys($this->stock), '0');
                        $this->opening = [];
                    }
                }

                $plan = $this->plan(combinationId: $combinationId);

                if ($plan['refusals'] !== []) {
                    throw new RuntimeException(implode(' ', $plan['refusals']));
                }

                $opening = $plan['transition'] === 'on';
                $saved = ItemFieldsAdminHelper::saveSingleCombination(
                    itemId: $this->itemId,
                    combinationId: $combinationId,
                    row: $this->row,
                    priceRows: $this->prices ?? self::storedPriceRows(itemId: $this->itemId, combinationId: $combinationId),
                    openingTargets: $opening ? $plan['targets'] : null,
                );

                if (!$saved) {
                    throw new RuntimeException(Text::sprintf('COM_ALFA_COMBINATION_NOT_CURRENT', (string) $combinationId));
                }

                $this->applyStateAndDefault(combinationId: $combinationId);
                self::announce(
                    db: $db,
                    itemId: $this->itemId,
                    created: $this->combinationId === 0 ? [$combinationId] : [],
                    changed: $this->combinationId === 0 ? [] : [$combinationId],
                    archived: [],
                    removed: [],
                    origin: $this->origin,
                );
                TransactionState::commitLevel(db: $db);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $db, failure: $e);

                throw $e;
            }

            // Changed tracked figures converge after the save, as the window converges them: a refusal is not a failed
            // save; the combination is stored, and the caller is told its stock did not change and why.
            if (!$opening && $this->stock !== []) {
                $warnings = $this->convergeStock(combinationId: $combinationId);
            }

            // Tracking switched off keeps the stock records as they are: which quantities are no longer counted.
            $tracking = ItemFieldsAdminHelper::lastTrackingChange();

            if ((int) ($tracking['kept_rows'] ?? 0) > 0) {
                $warnings[] = ['message' => Text::sprintf(
                    'COM_ALFA_INVENTORY_TRACKING_OFF_STOCK_KEPT',
                    (int) $tracking['kept_rows'],
                    InventoryAvailabilityService::formatQuantity(value: (float) ($tracking['kept_on_hand'] ?? 0.0)),
                ), 'type' => 'warning'];
            }
        } catch (RuntimeException $e) {
            // A failure that ended the caller's transaction is the caller's to hear: a refusal would let it commit work
            // the server has already discarded.
            if ($nested && !TransactionState::isOpen(db: $db)) {
                throw $e;
            }

            Log::add(
                entry: sprintf('Combination %d of item %d was not saved: %s', $combinationId, $this->itemId, ErrorText::of($e)),
                priority: Log::ERROR,
                category: 'com_alfa.catalog',
            );

            return new CombinationWriteResult(saved: false, id: $this->combinationId, refusals: [self::refusalOf(failure: $e)]);
        } finally {
            self::afterChange(change: $change, nested: $nested);
        }

        return new CombinationWriteResult(saved: true, id: $combinationId, warnings: $warnings);
    }

    /**
     * A new combination's values judged on the generator's rules, each refusal pointing at its value.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function valueRefusals(): array
    {
        $db = self::db();
        $itemState = $db->setQuery('SELECT ' . $db->quoteName('state') . ' FROM ' . $db->quoteName('#__alfa_items') . ' WHERE '
            . $db->quoteName('id') . ' = ' . $this->itemId)->loadResult();

        if ($itemState === null) {
            return [new Refusal(code: 'not_found', sentence: Text::_('COM_ALFA_ITEM_STOCK_REASON_GONE'), status: Refusal::NOT_FOUND)];
        }

        if ((int) $itemState === CombinationState::ARCHIVED) {
            return [new Refusal(code: 'item_archived', sentence: Text::_('COM_ALFA_COMBI_GENERATE_ITEM_ARCHIVED'), status: Refusal::CONFLICT)];
        }

        $eligible = array_map('intval', ItemFieldsAdminHelper::getEligibleChoiceFieldIds(itemId: $this->itemId));
        $dimensions = array_map('intval', ItemFieldsAdminHelper::getCombinationFieldIds(itemId: $this->itemId));
        $options = array_map('intval', $db->setQuery(
            'SELECT DISTINCT v.item_field_id FROM ' . $db->quoteName('#__alfa_item_option_values', 'ov') . ' INNER JOIN '
            . $db->quoteName('#__alfa_item_field_values', 'v') . ' ON v.id = ov.value_id WHERE ov.item_id = ' . $this->itemId,
        )->loadColumn() ?: []);
        $refusals = [];

        foreach ($this->values as $fieldId => $valueId) {
            $pointer = '/values/' . $fieldId;
            $field = self::fieldName(fieldId: $fieldId);

            if (\in_array($fieldId, $options, true)) {
                $refusals[] = new Refusal(code: 'field_is_option', sentence: Text::sprintf('COM_ALFA_COMBINATION_FIELD_IS_OPTION', $field), pointer: $pointer);
            } elseif (!\in_array($fieldId, $eligible, true)) {
                $refusals[] = new Refusal(code: 'field_not_eligible', sentence: Text::sprintf('COM_ALFA_COMBINATION_FIELD_NOT_ELIGIBLE', $field), pointer: $pointer);
            } elseif ((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_field_values') . ' WHERE id = ' . $valueId
                . ' AND item_field_id = ' . $fieldId . ' AND state = 1')->loadResult() === 0) {
                $refusals[] = new Refusal(code: 'value_invalid', sentence: Text::sprintf('COM_ALFA_COMBINATION_VALUE_INVALID', $field), pointer: $pointer);
            }
        }

        $chosen = array_keys($this->values);
        sort($chosen);
        sort($dimensions);

        if ($refusals === [] && ($chosen === [] || ($dimensions !== [] && $chosen !== $dimensions))) {
            $refusals[] = new Refusal(code: 'dimensions', sentence: Text::sprintf(
                'COM_ALFA_COMBINATION_DIMENSIONS',
                implode(', ', array_map(static fn (int $id): string => self::fieldName(fieldId: $id), $dimensions === [] ? $chosen : $dimensions)),
            ), pointer: '/values');
        }

        if ($refusals === []) {
            $signature = $this->values;
            sort($signature);
            $holder = array_values(array_filter(self::currentRows(itemId: $this->itemId), static fn (object $row): bool => $row->signature === implode('-', $signature)));

            if ($holder !== []) {
                $refusals[] = new Refusal(code: 'combination_exists', sentence: Text::sprintf(
                    'COM_ALFA_COMBINATION_EXISTS',
                    InventoryUnitException::labelFor(itemId: $this->itemId, combinationId: (int) $holder[0]->id),
                    (int) $holder[0]->id,
                ), pointer: '/values', status: Refusal::CONFLICT);
            }
        }

        return $refusals;
    }

    /**
     * Make the new combination by the generator, inside the save's transaction: an archived one with these values
     * comes back, as the generator brings it back.
     *
     * @return int The combination.
     *
     * @since  2.3.0
     */
    private function make(DatabaseDriver $db): int
    {
        ItemFieldsAdminHelper::generateCombinations(
            itemId: $this->itemId,
            valuesByField: array_map(static fn (int $valueId): array => [$valueId], $this->values),
        );
        $signature = $this->values;
        sort($signature);

        foreach (self::currentRows(itemId: $this->itemId) as $id => $row) {
            if ($row->signature === implode('-', $signature)) {
                return (int) $id;
            }
        }

        throw new RuntimeException(Text::_('COM_ALFA_COMBINATION_NOT_MADE'));
    }

    /**
     * The window's stock plan for the write.
     *
     * @return array{transition: ?string, targets: array<int, float>, refusals: string[]}
     *
     * @since  2.3.0
     */
    private function plan(int $combinationId): array
    {
        return ItemFieldsAdminHelper::studioStockPlan(
            itemId: $this->itemId,
            combinationId: $combinationId,
            row: $this->row,
            postedStock: $this->stock,
            postedBaseline: $this->stockBaseline,
            postedOpening: $this->opening,
        );
    }

    /**
     * The changed tracked figures, converged as the window converges them; what could not apply, as warnings.
     *
     * @return list<array{message: string, type: string}>
     *
     * @since  2.3.0
     */
    private function convergeStock(int $combinationId): array
    {
        try {
            return array_map(
                static fn (string $warning): array => ['message' => $warning, 'type' => 'warning'],
                ItemFieldsAdminHelper::applyCombinationLocationStock(
                    itemId: $this->itemId,
                    combinationId: $combinationId,
                    posted: $this->stock,
                    baseline: $this->stockBaseline,
                ),
            );
        } catch (InventoryIntegrityException $e) {
            Log::add(sprintf('Combination %d of item %d saved, but its stock was refused: %s', $combinationId, $this->itemId, ErrorText::of($e)), Log::ERROR, 'com_alfa.inventory');

            return [['message' => $e->operatorMessage(), 'type' => 'error']];
        } catch (Throwable $e) {
            Log::add(sprintf('Combination %d of item %d saved, but its stock was refused: %s', $combinationId, $this->itemId, ErrorText::of($e)), Log::ERROR, 'com_alfa.inventory');

            // Every refusal that reaches here is a complete sentence, so it follows the saved notice whole.
            return [['message' => Text::sprintf('COM_ALFA_ITEM_SAVED_STOCK_NOT_CHANGED', ErrorText::of($e)), 'type' => 'error']];
        }
    }

    /**
     * Publication through the inventory service, which keeps the stock read models with it; the combination the
     * product opens on, one a product.
     *
     * @since  2.3.0
     */
    private function applyStateAndDefault(int $combinationId): void
    {
        if (\array_key_exists('state', $this->row)) {
            $state = (int) $this->row['state'] === 1 ? 1 : 0;
            $stored = (int) (self::currentRows(itemId: $this->itemId)[$combinationId]->state ?? $state);

            if ($stored !== $state) {
                (new InventoryService())->changeCombinationState(itemId: $this->itemId, states: [$combinationId => $state], context: InventoryContext::ambient());
            }
        }

        if (!empty($this->row['default'])) {
            $db = self::db();
            $db->setQuery('UPDATE ' . $db->quoteName('#__alfa_item_combinations') . ' SET ' . $db->quoteName('default_on') . ' = (CASE WHEN '
                . $db->quoteName('id') . ' = ' . $combinationId . ' THEN 1 ELSE 0 END) WHERE ' . $db->quoteName('item_id') . ' = ' . $this->itemId
                . ' AND ' . CombinationState::currentSql(column: $db->quoteName('state')))->execute();
        }
    }

    /**
     * The product's catalogue after the write: rebuilt now, or, inside a caller's transaction (a product's whole write
     * through ItemWriteService), left marked for the caller to rebuild once after its commit.
     *
     * @param array{item: int, lock: \Alfa\Component\Alfa\Administrator\Service\Projection\IndexRebuildLock, held: int[]} $change What ProjectionManager::markChange() answered.
     *
     * @since  2.3.0
     */
    private static function afterChange(array $change, bool $nested): void
    {
        if ($nested) {
            ProjectionManager::leaveChanged(change: $change);

            return;
        }

        ProjectionManager::rebuildChanged(change: $change);
    }

    /**
     * Once the write commits: the dashboard cards' cache dropped, and onAlfaCombinationsChanged to each listener on its
     * own.
     *
     * @param list<int> $created
     * @param list<int> $changed
     * @param list<int> $archived
     * @param list<int> $removed
     *
     * @since  2.3.0
     */
    private static function announce(DatabaseDriver $db, int $itemId, array $created, array $changed, array $archived, array $removed, string $origin): void
    {
        PostCommit::defer(db: $db, label: 'the combinations of #' . $itemId, work: static function () use ($itemId, $created, $changed, $archived, $removed, $origin): void {
            // The dashboard's cards count combinations (low stock, the catalogue's units): their cache goes with the write.
            CardRegistry::invalidateFor(entity: 'combination');

            try {
                $plugins = AlfaPluginHelper::get();
                $plugins->importGroup('alfa');
                $plugins->dispatchIsolated(new CombinationsChangedEvent(CombinationsChangedEvent::NAME, [
                    'itemId' => $itemId, 'created' => $created, 'changed' => $changed, 'archived' => $archived, 'removed' => $removed, 'origin' => $origin,
                ]));
            } catch (Throwable $e) {
                Log::add('onAlfaCombinationsChanged listeners failed for item ' . $itemId . ': ' . ErrorText::of($e), Log::WARNING, 'com_alfa.catalog');
            }
        });
    }

    /**
     * A failure of the write as its refusal: the window's sentence, 409.
     *
     * @since  2.3.0
     */
    private static function refusalOf(RuntimeException $failure): Refusal
    {
        if ($failure instanceof SkuInUseException) {
            return $failure->refusal;
        }

        return new Refusal(code: 'refused', sentence: ErrorText::of($failure), status: Refusal::CONFLICT);
    }

    /**
     * A product's current combinations (published and unpublished), by id.
     *
     * @return array<int, object>
     *
     * @since  2.3.0
     */
    private static function currentRows(int $itemId): array
    {
        $db = self::db();

        return $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'signature', 'state'])) . ' FROM ' . $db->quoteName('#__alfa_item_combinations')
            . ' WHERE ' . $db->quoteName('item_id') . ' = ' . $itemId . ' AND ' . CombinationState::currentSql(column: $db->quoteName('state')),
        )->loadObjectList('id') ?: [];
    }

    /**
     * Some combinations of a product as archived rows, by id.
     *
     * @param list<int> $ids The combinations.
     *
     * @return array<int, object>
     *
     * @since  2.3.0
     */
    private static function archivedRows(int $itemId, array $ids): array
    {
        $db = self::db();

        return $ids === [] ? [] : ($db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'signature', 'state'])) . ' FROM ' . $db->quoteName('#__alfa_item_combinations')
            . ' WHERE ' . $db->quoteName('item_id') . ' = ' . $itemId . ' AND ' . $db->quoteName('state') . ' = ' . CombinationState::ARCHIVED
            . ' AND ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')',
        )->loadObjectList('id') ?: []);
    }

    /**
     * A combination's price rows as stored, in the window's shape, for a save that leaves them as they are.
     *
     * @return list<array>
     *
     * @since  2.3.0
     */
    private static function storedPriceRows(int $itemId, int $combinationId): array
    {
        $db = self::db();
        $rows = [];

        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__alfa_items_prices') . ' WHERE ' . $db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . $db->quoteName('combination_id') . ' = ' . $combinationId . ' ORDER BY ' . $db->quoteName('id'))->loadAssocList() ?: [] as $row) {
            $row['price_status'] = (string) $row['state'];
            $row['_mode'] = $row['mode'];
            $row['adjust_target'] = $row['override_type'];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A field's name in the language read.
     *
     * @since  2.3.0
     */
    private static function fieldName(int $fieldId): string
    {
        $texts = MultilingualHelper::getMultilingualDataFlat(currentId: $fieldId, primaryColumnName: 'id_item_field', tableName: '#__alfa_item_fields');

        foreach ([Factory::getApplication()->getLanguage()->getTag(), MultilingualHelper::getDefaultLanguageTag()] as $tag) {
            $name = trim((string) ($texts[MultilingualHelper::buildLangFieldKey(field: 'name', tag: $tag)] ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return '#' . $fieldId;
    }

    /**
     * The connection.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseDriver
    {
        return TransactionState::driver(db: Factory::getContainer()->get(DatabaseInterface::class));
    }
}
