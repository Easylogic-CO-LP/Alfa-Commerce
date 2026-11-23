<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Model\ItemModel as AdminItemModel;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CommerceChange;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemWriteService;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedLinksRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SkuInUseException;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\Database\DatabaseInterface;

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
        displayItem as private guardedDisplayItem;
    }

    /**
     * Body keys that state a stock figure. Core backfills every table column a
     * PATCH omits, so an omitted `stock` would come back as the stored projection
     * and read as a figure the client never sent.
     *
     * @var string[]
     * @since  2.0.0
     */
    private const STOCK_INTENT_KEYS = ['stock', 'inventory_stock'];

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
        $warnings = [];

        foreach ($this->itemModels as $model) {
            foreach ($model->written()->warnings ?? [] as $warning) {
                $warnings[] = $warning['message'];
            }
        }

        if ($warnings !== []) {
            $this->app->getDocument()->addMeta('warnings', $warnings);
        }

        return $answer;
    }

    /**
     * Save the write: a SKU another product or variant holds answers 409 with the refusal's sentence, and related
     * links the product cannot take answer 422 with each refusal led by its row (related[n]); nothing is saved.
     *
     * @param int|null $recordKey The record's key.
     *
     * @return int The record's id.
     *
     * @throws Save When the write's SKU is another unit's (409), its related links are refused (422), or the save was
     *              refused otherwise.
     *
     * @since  2.3.0
     */
    protected function save($recordKey = null)
    {
        try {
            return parent::save($recordKey);
        } catch (Save $e) {
            foreach ($this->itemModels as $model) {
                foreach ($model->getErrors() as $error) {
                    if ($error instanceof SkuInUseException) {
                        throw new Save($error->refusal->placed(), $error->refusal->status, $e);
                    }

                    if ($error instanceof RelatedLinksRefusedException) {
                        throw new Save($error->placed(), $error->getCode(), $e);
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
     * model applies it as it does for the screen (stock, in, low or out, and fields, the item field values).
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
     * An item's options, combinations, item field values and downloads, which the API does not write: the four keys
     * the item read publishes, then the keys the item edit form's grids post for the same writes and for the downloads
     * grid, which the item save reads from the request as the form posts them. A list sent in the read's shape was
     * taken by its positions, as value or combination ids. Its related products (`related`) are written with the item,
     * as the Related tab writes them.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function unwrittenKeys(): array
    {
        return ['fields', 'options', 'combinations', 'informational', 'combinations_default', 'deleted_combination_ids',
            'deleted_option_value_ids', 'deleted_info_value_ids', 'info_values', 'field_order', 'field_required', 'item_downloads'];
    }

    /**
     * Where they are written instead.
     *
     * @since  2.2.1
     */
    protected function unwrittenReason(): string
    {
        return 'an item\'s options, combinations, item field values and downloads are edited on its page in the administrator.'
            . ' Send the item without them; nothing was saved.';
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
        $body = $this->requestBody();

        foreach (self::STOCK_INTENT_KEYS as $key) {
            if (!\array_key_exists($key, $body)) {
                unset($data[$key]);
            }
        }

        unset($data['available_quantity'], $data['inventory_stock_baseline']);

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
