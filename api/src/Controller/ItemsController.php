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
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\Save;

/**
 * The Items controller
 *
 * @since  1.0.0
 */
class ItemsController extends ApiController
{
    use HasApiGuards {
        preprocessSaveData as private guardedPreprocessSaveData;
        add as private guardedAdd;
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
     * Body keys of an item's options, combinations and item field values, which the API does not write: the four the
     * item read publishes, then the administrator form's keys of the same writes.
     *
     * @var string[]
     * @since  2.2.1
     */
    private const UNWRITTEN_KEYS = ['fields', 'options', 'combinations', 'informational', 'combinations_default',
        'deleted_combination_ids', 'deleted_option_value_ids', 'deleted_info_value_ids', 'info_values', 'field_order',
        'field_required'];

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
        ];
    }

    /**
     * Items list view amended to add filtering of data
     *
     * Each filter goes onto the model state under the name the items screen's filter form gives it, and the list
     * model applies it as it does for the screen (2.2.1: stock, in, low or out, and fields, the item field values).
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
     * Create an item, refused first when the body carries what the API does not write.
     *
     * @return void
     *
     * @since  2.2.1
     */
    public function add()
    {
        $this->refuseUnwrittenKeys();

        $this->guardedAdd();
    }

    /**
     * Update an item, refused first when the body carries what the API does not write: before core's edit checks the
     * item out, so a refused PATCH leaves it as it was.
     *
     * @return static
     *
     * @since  2.2.1
     */
    public function edit()
    {
        $this->refuseUnwrittenKeys();

        return parent::edit();
    }

    /**
     * Answer 422, naming them, when the body carries an item's options, combinations or item field values: the keys
     * the item read publishes for them, and the administrator form's keys of the same writes. The item save reads
     * those from the request too, as the form posts them (MultilingualHelper::mergeValidatedFormData() keeps every
     * undeclared key), so a body that carried them reached the option and combination writers in a shape they do not
     * read (a list in the read's shape is keyed by its positions, not by value or combination ids). Until the API
     * writes them, they are refused before anything is saved.
     *
     * @throws Save When the body carries one of them (422).
     *
     * @since  2.2.1
     */
    private function refuseUnwrittenKeys(): void
    {
        $carried = array_values(array_intersect(self::UNWRITTEN_KEYS, array_keys($this->requestBody())));

        if ($carried !== []) {
            throw new Save(
                implode(', ', $carried) . ' cannot be written through the API: an item\'s options, combinations and item field '
                . 'values are edited on its page in the administrator. Send the item without them; nothing was saved.',
                422,
            );
        }
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
     * The request body exactly as core's save() reads it.
     *
     *
     * @since   2.0.0
     */
    private function requestBody(): array
    {
        $body = $this->input->get(
            name: 'data',
            default: json_decode((string) $this->input->json->getRaw(), true),
            filter: 'array',
        );

        return \is_array($body) ? $body : [];
    }
}
