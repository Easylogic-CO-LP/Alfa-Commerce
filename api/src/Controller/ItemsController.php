<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Model\ItemModel as AdminItemModel;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CommerceChange;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemSaveBusyException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemWriteService;
use Alfa\Component\Alfa\Administrator\Service\Catalog\PricesState;
use Alfa\Component\Alfa\Administrator\Service\Catalog\PricesStateChanged;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedLinksRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SkuInUseException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\UnitBatch;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Closure;
use Joomla\CMS\Document\JsonapiDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Serializer\JoomlaSerializer;
use Joomla\Database\DatabaseInterface;
use Tobscure\JsonApi\Collection;

/**
 * The Items controller
 *
 * @since  1.0.0
 */
class ItemsController extends ApiController
{
    use HasApiGuards {
        preprocessSaveData as private guardedPreprocessSaveData;
        refuseUnwrittenKeys as private guardedRefuseUnwrittenKeys;
        taskActions as private crudTaskActions;
        displayItem as private guardedDisplayItem;
        add as private guardedAdd;
        edit as private guardedEdit;
    }
    use AnswersFromServices;
    use ReadsCatalogBodies;

    /**
     * The keys of a product's graph a write takes with it, written in one transaction with the product
     * ({@see ItemWriteService}): item fields' values, options, specifications and combinations with their stock.
     *
     * @var string[]
     * @since  2.3.0
     */
    private const GRAPH_KEYS = ['field_values', 'options', 'informational', 'combinations'];

    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.0.0
     */
    protected $contentType = 'items';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.0.0
     */
    protected $default_view = 'items';

    /**
     * The item models this request made, so the answer can say what their saves did not apply.
     *
     * @var list<AdminItemModel>
     * @since  2.3.0
     */
    private array $itemModels = [];

    /**
     * The prices_state the update sent with its prices, or null when it sent none.
     *
     * @since  2.2.2
     */
    private ?string $pricesState = null;

    /**
     * The steps of the product's graph the request sends, laid on the item model's write; null for a write without one.
     *
     * @var (Closure(ItemWriteService): void)|null
     * @since  2.3.0
     */
    private ?Closure $graph = null;

    /**
     * The warnings of the graph's keys a part does not take.
     *
     * @var list<array<string, string>>
     * @since  2.3.0
     */
    private array $graphWarnings = [];

    /**
     * The tasks of the items and what each asks: the batch of units writes prices and stock, so it asks edit, as a
     * product's write does.
     *
     * @return array<string, list<string>>
     *
     * @since  2.2.2
     */
    protected function taskActions(): array
    {
        return ['units' => ['edit']] + $this->crudTaskActions();
    }

    /**
     * PATCH v1/alfa/items/units: the prices and stock of many units, products without combinations and combinations,
     * in one request ({@see UnitBatch}). Each unit is written in its own transaction and answered on its own, in the
     * request's order: 200 with its prices_state, stock and available_quantity, or the status it was refused with and
     * why, named by its JSON pointer (/units/3/prices_state); the other units are written. The answer is 200 whenever
     * the request could be read, each unit's status in its answer; a body that is not {"units": [...]} of 1 to
     * UnitBatch::MAX_UNITS units is refused 422 whole, nothing written.
     *
     * @return static
     *
     * @throws Save When the body is not a list of 1 to UnitBatch::MAX_UNITS units (422).
     *
     * @since  2.2.2
     */
    public function units()
    {
        $body = $this->requestBody();
        $units = $body['units'] ?? null;

        if (array_diff(array_keys($body), ['units']) !== [] || !\is_array($units) || !array_is_list($units) || $units === []) {
            throw new Save('units: send {"units": [...]}, a list of units, each {"item_id": 12, "combination_id": 0, "prices": [...], "prices_state": "...", "stock": "5"}. Nothing was saved.', 422);
        }

        if (\count($units) > UnitBatch::MAX_UNITS) {
            throw new Save('units: send at most ' . UnitBatch::MAX_UNITS . ' units a request; ' . \count($units) . ' were sent. Nothing was saved.', 422);
        }

        $answers = (new UnitBatch())->write(units: $units);
        $document = $this->app->getDocument();

        if (!$document instanceof JsonapiDocument) {
            throw new Save('The answer could not be written. The units were written as their answers would have said.', 500);
        }

        $rows = [];

        foreach ($answers as $index => $answer) {
            $rows[] = (object) (['id' => (string) $index] + $answer);
        }

        $document->setData(new Collection($rows, new JoomlaSerializer('units')));
        $this->app->setHeader('status', '200', true);

        return $this;
    }

    /**
     * Relations the admin model clears when the key is absent from a PATCH: the item write's own list.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    protected function preserveOnPatch(): array
    {
        return ItemWriteService::KEPT_WHEN_ABSENT;
    }

    /**
     * Core's model, kept when it is the item model, for the answer's warnings.
     *
     * @param string $name The model.
     * @param string $prefix Its prefix.
     * @param array $config Its configuration.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel|false
     *
     * @since  2.3.0
     */
    public function getModel($name = '', $prefix = '', $config = [])
    {
        $model = parent::getModel($name, $prefix, $config);

        if ($model instanceof AdminItemModel) {
            if ($this->graph !== null) {
                $model->expectGraph(steps: $this->graph);
            }

            if ($this->pricesState !== null) {
                $model->expectPricesState(state: $this->pricesState);
            }

            $this->itemModels[] = $model;
        }

        return $model;
    }

    /**
     * Answer one product; after a save applied in part (stock or variants refused once the product was written, an
     * address shared with another product), meta.warnings names each thing the save did not apply.
     *
     * @param int|null $id The product.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function displayItem($id = null)
    {
        $answer = $this->guardedDisplayItem($id);
        $warnings = $this->graphWarnings;
        $written = [];

        foreach ($this->itemModels as $model) {
            foreach ($model->written()->warnings ?? [] as $warning) {
                $warnings[] = ['code' => 'not_applied', 'title' => $warning['message']];
            }

            $written = $model->written()->written ?? [] ?: $written;
        }

        if ($warnings !== []) {
            $this->app->getDocument()->addMeta('warnings', $warnings);
        }

        // What a write with the product's graph wrote, part by part: each with its place in the request, its id and
        // whether it was created.
        if ($written !== []) {
            $this->app->getDocument()->addMeta('written', $written);
        }

        return $answer;
    }

    /**
     * Save the write: prices changed since they were read answer 409 naming prices_state, a SKU another product or variant
     * holds answers 409 with the refusal's sentence, related links the product cannot take answer 422 with each refusal
     * led by its row (related[n]), a refused graph answers its refusals; nothing saved.
     *
     * @param int|null $recordKey The record's key.
     *
     * @return int The record's id.
     *
     * @throws Save When the write's SKU is another unit's (409), its related links are refused (422), another write
     *              held its rows (409, Retry-After), or the save was refused otherwise.
     *
     * @since  2.3.0
     */
    protected function save($recordKey = null)
    {
        try {
            return parent::save($recordKey);
        } catch (PricesStateChanged $e) {
            // The write checked the product out before its save; a refused write gives it back as it found it.
            $model = $this->getModel('item', '', ['ignore_request' => true]);

            if ($model instanceof AdminItemModel) {
                $model->checkin($e->itemId);
            }

            throw new Save(ErrorText::of($e), 409, $e);
        } catch (Save $e) {
            foreach ($this->itemModels as $model) {
                if ($this->graph !== null && ($model->written()->refused ?? []) !== []) {
                    // The write checked the product out before its save; a refused write gives it back as it found it.
                    $model->checkin(RequestScalar::int($this->input, 'id'));

                    if (\in_array('busy', array_column($model->written()->refused, 'code'), true)) {
                        $this->app->setHeader('Retry-After', (string) ItemSaveBusyException::RETRY_AFTER, true);
                    }

                    throw new ItemGraphRefusedException(refusals: $model->written()->refused);
                }

                foreach ($model->getErrors() as $error) {
                    if ($error instanceof SkuInUseException) {
                        throw new Save($error->refusal->placed(), $error->refusal->status, $e);
                    }

                    if ($error instanceof RelatedLinksRefusedException) {
                        throw new Save($error->placed(), $error->getCode(), $e);
                    }

                    if ($error instanceof ItemSaveBusyException) {
                        $this->app->setHeader('Retry-After', (string) ItemSaveBusyException::RETRY_AFTER, true);

                        throw new Save($error->refusal->placed(), $error->refusal->status, $e);
                    }
                }
            }

            throw $e;
        }
    }

    /**
     * Items list view amended to add filtering of data
     *
     * Each filter goes onto the model state under the name the items screen's filter form gives it, and the list
     * model applies it as it does for the screen (stock: in, low or out; fields: the item field values).
     *
     * @return static A BaseController object to support chaining.
     *
     * @since  2.0.0
     */
    public function displayList()
    {
        $apiFilterInfo = $this->input->get('filter', [], 'array');
        $filter = InputFilter::getInstance();

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', RequestScalar::member($apiFilterInfo, 'search', 'STRING', 'filter'));
        }

        if (\array_key_exists('state', $apiFilterInfo)) {
            $this->modelState->set('filter.state', RequestScalar::member($apiFilterInfo, 'state', 'INT', 'filter'));
        }

        if (\array_key_exists('sku', $apiFilterInfo)) {
            $sku = trim((string) RequestScalar::member($apiFilterInfo, 'sku', 'STRING', 'filter'));

            if ($sku === '' || mb_strlen($sku) > 255 || preg_match('/[\x00-\x1F\x7F]/', $sku) === 1) {
                throw new Save('filter[sku] must be a SKU of 1 to 255 characters without control characters.', 400);
            }

            $this->modelState->set('filter.sku', $sku);
        }

        if (\array_key_exists('category', $apiFilterInfo)) {
            $this->modelState->set('filter.category', $filter->clean($apiFilterInfo['category'], 'INT'));
        }

        if (\array_key_exists('manufacturer', $apiFilterInfo)) {
            $this->modelState->set('filter.manufacturer', $filter->clean($apiFilterInfo['manufacturer'], 'INT'));
        }

        if (\array_key_exists('stock', $apiFilterInfo)) {
            $this->modelState->set('filter.stock', RequestScalar::member($apiFilterInfo, 'stock', 'CMD', 'filter'));
        }

        if (\array_key_exists('fields', $apiFilterInfo)) {
            $this->modelState->set('filter.fields', $this->fieldsFilter($apiFilterInfo['fields']));
        }

        // The products whose stock, prices or own fields changed at or after a time (commerce_changed_at): UTC, as every
        // date the API publishes, a bound with Z or an offset in its own zone.
        if (\array_key_exists('changed_since', $apiFilterInfo)) {
            $bound = CommerceChange::bound(bound: (string) RequestScalar::member($apiFilterInfo, 'changed_since', 'STRING', 'filter'));

            if ($bound === null) {
                throw new Save('filter[changed_since] must be a date and time, 2026-11-20T10:00:00Z (UTC when it states no zone).', 400);
            }

            $this->modelState->set('filter.changed_since', $bound);
        }

        $apiListInfo = $this->input->get('list', [], 'array');

        if (\array_key_exists('ordering', $apiListInfo)) {
            $this->modelState->set('list.ordering', RequestScalar::member($apiListInfo, 'ordering', 'STRING', 'list'));
        }

        if (\array_key_exists('direction', $apiListInfo)) {
            $this->modelState->set('list.direction', RequestScalar::member($apiListInfo, 'direction', 'STRING', 'list'));
        }

        return parent::displayList();
    }

    /**
     * The item field filter as the items screen hands it to the model: field id => the value ids asked for (any of
     * them; none asks which items use the field at all), from filter[fields][<field id>][]=<value id> or
     * filter[fields][<field id>]=<value id>. A field id that is not a positive whole number, and a value that is not
     * one value, is left out; a filter[fields] that is not a map narrows nothing, as on the screen.
     *
     * @param mixed $fields The filter's value as the request carries it.
     *
     * @return array<int, int[]>
     *
     * @since  2.2.1
     */
    private function fieldsFilter(mixed $fields): array
    {
        if (!\is_array($fields)) {
            return [];
        }

        $map = [];

        foreach ($fields as $fieldId => $valueIds) {
            $fieldId = (int) $fieldId;

            if ($fieldId <= 0) {
                continue;
            }

            $values = array_map('intval', array_filter((array) $valueIds, 'is_scalar'));
            $map[$fieldId] = array_values(array_filter($values, static fn (int $valueId): bool => $valueId > 0));
        }

        return $map;
    }

    /**
     * The keys the API does not write: the item read's `fields`, and the keys the item edit form's grids and lists post
     * (the variants' grid, the option and specification lists, the field order, the downloads grid), which the item
     * save reads from the request as the form posts them. Options, specifications, combinations and field values are
     * written as the API's own lists, and the related products (`related`) with the item, as the Related tab writes
     * them.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function unwrittenKeys(): array
    {
        return ['fields', 'combinations_default', 'deleted_combination_ids',
            'deleted_option_value_ids', 'deleted_info_value_ids', 'info_values', 'field_order', 'field_required', 'item_downloads'];
    }

    /**
     * Where they are written instead.
     *
     * @since  2.2.1
     */
    protected function unwrittenReason(): string
    {
        return 'the item screen\'s own lists (the variants\' grid, the option and specification lists, the field order, the'
            . ' downloads) are not keys of the API; options, informational, combinations and field_values are sent as the'
            . ' API\'s lists. Nothing was saved.';
    }

    /**
     * Keep only the stock figures the client actually sent, then apply the shared guards.
     *
     * A stock figure is an instruction to the inventory service, so it counts only
     * when it is in the request body: the value core backfilled from the stored
     * projection is dropped. `available_quantity` is derived by the inventory
     * service and `inventory_stock_baseline` describes a form rendering, so a client
     * can send neither.
     *
     * @param array $data The save data after core's PATCH backfill.
     *
     *
     * @since   2.0.0
     */
    protected function preprocessSaveData(array $data): array
    {
        // The item model's own rule: a stock figure counts only when the body names it.
        $data = AdminItemModel::withoutUnsentStock(data: $data, sent: array_keys($this->requestBody()));

        // The graph's keys are the write's steps ({@see self::readGraph()}), never the item form's fields.
        foreach (self::GRAPH_KEYS as $key) {
            unset($data[$key]);
        }

        return $this->guardedPreprocessSaveData(data: $data);
    }

    /**
     * The guards' refusal of the keys the write does not take, then the refusal of a stock shape the item does not
     * take ({@see self::refuseStockShapes()}): both before core's edit checks the record out and before anything
     * is saved.
     *
     * @param bool $update Whether the write is an update (PATCH) rather than a create (POST).
     *
     * @throws Save When the body carries an unwritten key or a stock shape the item does not take (422).
     *
     * @since  2.2.2
     */
    protected function refuseUnwrittenKeys(bool $update): void
    {
        $this->guardedRefuseUnwrittenKeys(update: $update);
        $this->refuseStockShapes(body: $this->requestBody());
        $this->pricesState = $this->sentPricesState(body: $this->requestBody(), update: $update);
        $this->readGraph(body: $this->requestBody());
    }

    /**
     * The state of the product's own prices the client read and sends back with its prices ({@see PricesState}): the
     * update is refused 409 when the prices changed since, and nothing of it is saved. Without it the prices are
     * written as sent.
     *
     * @param array $body The request body.
     * @param bool $update Whether the write is an update (PATCH).
     *
     * @return string|null The state sent, or null.
     *
     * @throws Save When prices_state is sent on a create, without prices, or in another shape (422).
     *
     * @since  2.2.2
     */
    private function sentPricesState(array $body, bool $update): ?string
    {
        if (!\array_key_exists('prices_state', $body) || $body['prices_state'] === null) {
            return null;
        }

        if (!$update) {
            throw new Save('prices_state: send it on an update, with the prices it was read with. Nothing was saved.', 422);
        }

        if (!\array_key_exists('prices', $body)) {
            throw new Save('prices_state: send it with prices, the state the product\'s prices were read in. Nothing was saved.', 422);
        }

        if (!\is_string($body['prices_state']) || preg_match(PricesState::PATTERN, $body['prices_state']) !== 1) {
            throw new Save('prices_state: send it as the item read gives it, 32 hexadecimal digits. Nothing was saved.', 422);
        }

        return $body['prices_state'];
    }

    /**
     * POST creates a product, with its graph when the body sends one; a refused graph answers its refusals, each with
     * its JSON pointer, nothing saved.
     *
     * @return void
     *
     * @since  2.3.0
     */
    public function add()
    {
        try {
            $this->guardedAdd();
        } catch (ItemGraphRefusedException $e) {
            $this->refuse(refusals: $e->refusals);
        }
    }

    /**
     * PATCH changes a product, with its graph when the body sends one; a refused graph answers its refusals, each with
     * its JSON pointer, nothing saved.
     *
     * @return mixed What core's edit answers, or this controller with the refusals.
     *
     * @since  2.3.0
     */
    public function edit()
    {
        try {
            return $this->guardedEdit();
        } catch (ItemGraphRefusedException $e) {
            return $this->refuse(refusals: $e->refusals);
        }
    }

    /**
     * The product's graph the body sends, read into the write's steps in the order of resolution (values, options and
     * specifications, combinations with their stock; the product itself between the values and the options): each part
     * read as its own route reads it, a value or an option named by its id or by its field and code, a combination's
     * values by field (id or field_name) to value (id or code).
     *
     * @param array $body The request body.
     *
     * @throws ItemGraphRefusedException When a part cannot be read (422, each refusal at its JSON pointer).
     *
     * @since  2.3.0
     */
    private function readGraph(array $body): void
    {
        $sent = array_intersect_key($body, array_flip(self::GRAPH_KEYS));

        if ($sent === []) {
            return;
        }

        $steps = [];
        $refusals = [];
        $warnings = [];
        $list = static function (mixed $value, string $key) use (&$refusals): array {
            if (!\is_array($value) || !array_is_list($value)) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send a list.', pointer: '/' . $key);

                return [];
            }

            return $value;
        };
        $reference = static function (mixed $entry, string $at) use (&$refusals): int|array|null {
            if (\is_int($entry) && $entry > 0) {
                return $entry;
            }

            if (\is_array($entry) && (\is_int($entry['field'] ?? null) || \is_string($entry['field'] ?? null)) && \is_string($entry['code'] ?? null)) {
                return ['field' => $entry['field'], 'code' => $entry['code']];
            }

            $refusals[] = new Refusal(code: 'invalid', sentence: 'name a value by its id, or by {"field": "colour", "code": "silver"}.', pointer: $at);

            return null;
        };

        foreach ($list($sent['field_values'] ?? [], 'field_values') as $n => $entry) {
            $at = '/field_values/' . $n;

            if (!\is_array($entry) || !(\is_int($entry['field'] ?? null) || \is_string($entry['field'] ?? null))) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send {"field": "colour", "code": "silver", "translations": {"en_gb": {"name": "Silver"}}}.', pointer: $at);

                continue;
            }

            [$fields, $partRefusals, $partWarnings] = $this->valueFields(body: $entry, pointer: $at, alsoTaken: ['field']);
            array_push($refusals, ...$partRefusals);
            array_push($warnings, ...$partWarnings);
            $field = $entry['field'];
            $steps[] = static fn (ItemWriteService $write) => $write->value(field: $field, fields: $fields, pointer: $at);
        }

        foreach ($list($sent['options'] ?? [], 'options') as $n => $entry) {
            $at = '/options/' . $n;
            $entry = \is_array($entry) ? $entry : [];
            array_push($warnings, ...$this->unknownKeys(body: $entry, keys: ['value_id', 'value', 'operation', 'amount', 'apply_on'], what: 'an option', pointer: $at));
            $value = \array_key_exists('value', $entry) ? $reference($entry['value'], $at . '/value') : $reference($entry['value_id'] ?? null, $at . '/value_id');
            [$delta, $partRefusals] = $this->optionDelta(body: $entry, pointer: $at);
            array_push($refusals, ...$partRefusals);

            if ($value !== null) {
                $steps[] = static fn (ItemWriteService $write) => $write->option(
                    value: $value,
                    operation: $delta['operation'] ?? null,
                    amount: $delta['amount'] ?? null,
                    applyOn: $delta['apply_on'] ?? null,
                    pointer: $at,
                );
            }
        }

        if (\array_key_exists('informational', $sent)) {
            $values = [];

            foreach ($list($sent['informational'], 'informational') as $n => $entry) {
                $values[] = $reference($entry, '/informational/' . $n);
            }

            $steps[] = static fn (ItemWriteService $write) => $write->informational(values: $values, pointer: '/informational');
        }

        foreach ($list($sent['combinations'] ?? [], 'combinations') as $n => $entry) {
            $at = '/combinations/' . $n;

            if (!\is_array($entry) || !\is_array($entry['values'] ?? null) || $entry['values'] === [] || array_is_list($entry['values'])) {
                $refusals[] = new Refusal(code: 'values_missing', sentence: 'send values, an object of field (id or field_name) to value (id or code), {"colour": "silver", "6": 23}.', pointer: $at . '/values');

                continue;
            }

            array_push($warnings, ...$this->unknownKeys(
                body: $entry,
                keys: ['values', 'sku', 'gtin', 'mpn', 'state', 'manage_stock', 'default', 'overrides', 'translations', 'prices', 'stock'],
                what: 'a combination',
                pointer: $at,
            ));
            [$fields, $prices, $partRefusals, $partWarnings] = $this->combinationFields(body: $entry, pointer: $at);
            [$stock, $stockRefusals] = \array_key_exists('stock', $entry) ? $this->stockFigures(lines: $entry['stock'], pointer: $at . '/stock') : [[], []];
            array_push($refusals, ...$partRefusals, ...$stockRefusals);
            array_push($warnings, ...$partWarnings);
            $values = $entry['values'];
            $steps[] = static fn (ItemWriteService $write) => $write->combination(values: $values, fields: $fields, prices: $prices, stock: $stock, pointer: $at);
        }

        if ($refusals !== []) {
            throw new ItemGraphRefusedException(refusals: $refusals);
        }

        $this->graphWarnings = $warnings;
        $this->graph = static function (ItemWriteService $write) use ($steps): void {
            foreach ($steps as $step) {
                $step($write);
            }

            $write->from(origin: 'api');
        };
    }

    /**
     * Answer 422, naming the key and the shape, when the body states stock in a shape the item does not take.
     * `stock` is one figure for a product stocked at one location; `inventory_stock` is an object of location id to
     * figure, {"1": "3", "2": "2"}, each figure absolute, for a product without combinations that tracks stock. A
     * `stock` sent as an object or a list was read as no figure, and an `inventory_stock` keyed by anything but the
     * shop's stock locations wrote nothing: both answered 200. An empty figure ('' or null) is no figure, as before.
     *
     * @param array $body The request body.
     *
     * @throws Save When a stock key has another shape, or inventory_stock names something that is no stock location (422).
     *
     * @since  2.2.2
     */
    private function refuseStockShapes(array $body): void
    {
        $shape = 'for stock per location send inventory_stock, an object of location id to figure, {"1": "3", "2": "2"}.'
            . ' Nothing was saved.';

        if (\array_key_exists('stock', $body) && $body['stock'] !== null && !\is_scalar($body['stock'])) {
            throw new Save('stock: send one figure, a number such as "5", for a product stocked at one location; ' . $shape, 422);
        }

        if (!\array_key_exists('inventory_stock', $body) || $body['inventory_stock'] === null) {
            return;
        }

        $map = $body['inventory_stock'];

        if (!\is_array($map) || ($map !== [] && array_is_list($map))) {
            throw new Save('inventory_stock: send an object of location id to figure, {"1": "3", "2": "2"}, not a list or a single'
                . ' value. Nothing was saved.', 422);
        }

        $ids = [];

        foreach ($map as $key => $figure) {
            if ($figure !== null && !\is_scalar($figure)) {
                throw new Save('inventory_stock.' . $this->shownKey(key: (string) $key) . ': send one figure, a number such as "3", for each location. Nothing was saved.', 422);
            }

            if (preg_match('/^[1-9][0-9]*$/D', (string) $key) !== 1) {
                throw new Save('inventory_stock.' . $this->shownKey(key: (string) $key) . ' is not a stock location: the keys are the ids of the shop\'s stock'
                    . ' locations (GET v1/alfa/locations). Nothing was saved.', 422);
            }

            $ids[] = (int) $key;
        }

        if ($ids === []) {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $known = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_inventory_locations'))
                ->whereIn($db->quoteName('id'), $ids),
        )->loadColumn() ?: []);

        foreach ($ids as $id) {
            if (!\in_array($id, $known, true)) {
                throw new Save('inventory_stock.' . $id . ' is not a stock location: the keys are the ids of the shop\'s stock'
                    . ' locations (GET v1/alfa/locations). Nothing was saved.', 422);
            }
        }
    }

    /**
     * The note of a product the delete archived instead of deleting it: ItemModel::delete() archives a product that order
     * lines were sold as, whose orders are still fulfilled, cancelled and returned against its stock identity, and the
     * delete answers 200 with the product, archived, and this note.
     *
     * @param int $id The product.
     *
     * @since  2.2.1
     */
    protected function keptNote(int $id): string
    {
        return 'Product ' . $id . ' was archived instead of deleted: order lines were sold as it or its combinations. It is no'
            . ' longer offered or sold, and it stays the stock identity those orders are fulfilled, cancelled and returned against.';
    }

    /**
     * A product is deleted from the trash only, as the administrator's list of products deletes it.
     *
     * @since  2.2.1
     */
    protected function deletesFromTrash(): bool
    {
        return true;
    }
}
