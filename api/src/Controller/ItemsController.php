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
use Alfa\Component\Alfa\Administrator\Service\Catalog\PricesState;
use Alfa\Component\Alfa\Administrator\Service\Catalog\PricesStateChanged;
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
    }

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
     * The prices_state the update sent with its prices, or null when it sent none.
     *
     * @since  2.2.2
     */
    private ?string $pricesState = null;

    /**
     * Relations the admin model clears when the key is absent from a PATCH.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    protected function preserveOnPatch(): array
    {
        return [
            'categories',
            'manufacturers',
            'allowedUsers',
            'allowedUserGroups',
            'prices',
            'robots',
            'related',
        ];
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
     * An item's options, combinations, item field values and downloads, and its related products (`related`, set on
     * the product's Related tab), which the API does not write: the four keys the item read publishes, then the keys the item edit form's grids post for the same writes and for the downloads
     * grid, which the item save reads from the request as the form posts them. A list sent in the read's shape was
     * taken by its positions, as value or combination ids.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function unwrittenKeys(): array
    {
        return ['fields', 'options', 'combinations', 'informational', 'combinations_default', 'deleted_combination_ids',
            'deleted_option_value_ids', 'deleted_info_value_ids', 'info_values', 'field_order', 'field_required', 'item_downloads',
            'related'];
    }

    /**
     * Where they are written instead.
     *
     * @since  2.2.1
     */
    protected function unwrittenReason(): string
    {
        return 'an item\'s options, combinations, item field values, downloads and related products are edited on its page in the administrator.'
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
        // The item model's own rule: a stock figure counts only when the body names it.
        $data = AdminItemModel::withoutUnsentStock(data: $data, sent: array_keys($this->requestBody()));

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
     * The model of a write, told the prices_state the update sent, so its save refuses prices changed since.
     *
     * @param string $name The model name.
     * @param string $prefix The class prefix.
     * @param array $config The configuration.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel|false
     *
     * @since  2.2.2
     */
    public function getModel($name = '', $prefix = '', $config = [])
    {
        $model = parent::getModel($name, $prefix, $config);

        if ($this->pricesState !== null && $model instanceof AdminItemModel) {
            $model->expectPricesState(state: $this->pricesState);
        }

        return $model;
    }

    /**
     * Save the write: a write of prices changed since they were read answers 409, its sentence naming prices_state.
     *
     * @param int|null $recordKey The record's key.
     *
     * @return int The record's id.
     *
     * @throws Save When the prices changed since they were read (409).
     *
     * @since  2.2.2
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

            throw new Save($e->getMessage(), 409, $e);
        }
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
