<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CombinationReader;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CombinationService;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SkuIndex;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Document\JsonapiDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Serializer\JoomlaSerializer;
use Joomla\CMS\Uri\Uri;
use Tobscure\JsonApi\Collection;
use Tobscure\JsonApi\Resource;

/**
 * A product's combinations over the REST API, through the one way a combination is written
 * ({@see CombinationService}) and read ({@see CombinationReader}):
 *
 * - GET v1/alfa/items/:id/combinations, GET v1/alfa/items/:id/combinations/:combinationId: the current combinations
 *   (published and unpublished), with values, price rows, stock records, overrides and names;
 * - POST v1/alfa/items/:id/combinations: a new combination by its values, with its fields;
 * - PATCH v1/alfa/items/:id/combinations/:combinationId: a change of its fields (never of its values);
 * - DELETE v1/alfa/items/:id/combinations/:combinationId: removed as the variants' grid removes it;
 * - GET v1/alfa/combinations: every product's combinations, flat, filter[sku], filter[item_id], filter[state].
 *
 * Writes ask alfa.items.edit (the task is edit for each of them), reads alfa.items.view.
 *
 * @since  2.3.0
 */
class CombinationsController extends ApiController
{
    use HasApiGuards;

    /**
     * The keys a combination's body takes.
     *
     * @since  2.3.0
     */
    private const BODY_KEYS = ['values', 'sku', 'gtin', 'mpn', 'state', 'default', 'prices', 'manage_stock', 'opening_stock', 'stock', 'overrides', 'translations'];

    /**
     * @var string
     * @since  2.3.0
     */
    protected $contentType = 'combinations';

    /**
     * @var string
     * @since  2.3.0
     */
    protected $default_view = 'combinations';

    /**
     * A combination is its product's: the product's permissions.
     *
     * @since  2.3.0
     */
    protected function aclSection(): string
    {
        return 'items';
    }

    /**
     * A product's combinations, or every product's (flat) when the route names none.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function displayList()
    {
        $itemId = $this->routeId(name: 'id');

        if ($itemId > 0) {
            $this->requireItem(itemId: $itemId);

            return $this->answer(data: new Collection(array_map(static fn (array $row): object => (object) $row, CombinationReader::of(itemId: $itemId)), new JoomlaSerializer($this->contentType)));
        }

        $filter = $this->input->get('filter', [], 'array');
        $page = $this->input->get('page', [], 'array');
        $sku = \array_key_exists('sku', $filter) ? trim((string) RequestScalar::member($filter, 'sku', 'STRING', 'filter')) : '';
        $whole = static fn (mixed $value): ?int => \is_string($value) && preg_match('/^[0-9]{1,10}$/', $value) === 1 ? (int) $value : null;
        $state = \array_key_exists('state', $filter) ? $whole(RequestScalar::member($filter, 'state', 'STRING', 'filter')) : null;
        $limit = \array_key_exists('limit', $page) ? ($whole(RequestScalar::member($page, 'limit', 'STRING', 'page')) ?? 0) : 100;
        $after = \array_key_exists('after', $page) ? ($whole(RequestScalar::member($page, 'after', 'STRING', 'page')) ?? -1) : 0;
        $item = \array_key_exists('item_id', $filter) ? ($whole(RequestScalar::member($filter, 'item_id', 'STRING', 'filter')) ?? -1) : 0;

        if (\array_key_exists('sku', $filter) && !SkuIndex::wellFormed(sku: $sku)) {
            throw new Save('filter[sku] must be a SKU of 1 to 255 characters without control characters.', 400);
        }

        if (($state !== null && !\in_array($state, [0, 1, 2], true)) || (\array_key_exists('state', $filter) && $state === null)) {
            throw new Save('filter[state] must be 1 (published), 0 (unpublished) or 2 (archived).', 400);
        }

        if ($limit < 1 || $limit > 1000 || $after < 0 || $item < 0) {
            throw new Save('page[limit] must be 1 to 1000, page[after] a combination\'s id and filter[item_id] a product\'s id.', 400);
        }

        $result = CombinationReader::page(sku: $sku, itemId: $item, state: $state, limit: $limit, after: $after);

        if ($result['next'] !== null) {
            $current = Uri::getInstance();
            $query = $current->getQuery(true);
            $query['page'] = ['after' => (string) $result['next']] + (\is_array($query['page'] ?? null) ? $query['page'] : []);
            $next = clone $current;
            $next->setQuery($query);
            $this->app->getDocument()->addLink('next', (string) $next);
        }

        return $this->answer(data: new Collection(array_map(static fn (array $row): object => (object) $row, $result['rows']), new JoomlaSerializer($this->contentType)));
    }

    /**
     * One combination of a product.
     *
     * @param int|null $id Unused: the route names the product and the combination.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function displayItem($id = null)
    {
        $itemId = $this->routeId(name: 'id');
        $this->requireItem(itemId: $itemId);
        $combination = CombinationReader::one(itemId: $itemId, combinationId: $this->routeId(name: 'combinationId'));

        if ($combination === null) {
            throw new ResourceNotFound(Text::sprintf('COM_ALFA_COMBINATION_NOT_CURRENT', (string) $this->routeId(name: 'combinationId')), 404);
        }

        return $this->answer(data: new Resource((object) $combination, new JoomlaSerializer($this->contentType)));
    }

    /**
     * POST creates a combination by its values; PATCH changes one; DELETE removes one. The task is edit for the three,
     * so each asks the product's edit permission.
     *
     * @return static
     *
     * @throws Save When the body cannot be read (422).
     *
     * @since  2.3.0
     */
    public function edit()
    {
        $itemId = $this->routeId(name: 'id');
        $combinationId = $this->routeId(name: 'combinationId');
        $method = strtoupper($this->input->getMethod());
        $this->requireItem(itemId: $itemId);

        if ($method === 'DELETE') {
            return $this->remove(itemId: $itemId, combinationId: $combinationId);
        }

        $body = json_decode((string) $this->input->json->getRaw(), true);

        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new Save('Send a JSON object of the combination\'s fields, {"values": {"5": 12}, "sku": "SMX-WHT-512"}. Nothing was saved.', 422);
        }

        $creating = $method === 'POST' && $combinationId === 0;

        if (!$creating && \array_key_exists('values', $body)) {
            return $this->refuse(refusals: [new Refusal(code: 'values_fixed', sentence: Text::_('COM_ALFA_COMBINATION_VALUES_FIXED'), pointer: '/values')]);
        }

        if ($creating && (!\is_array($body['values'] ?? null) || $body['values'] === [])) {
            return $this->refuse(refusals: [new Refusal(code: 'values_missing', sentence: 'send values, an object of field id to value id, {"5": 12, "6": 23}.', pointer: '/values')]);
        }

        [$write, $refusals, $warnings] = $this->writeOf(
            write: $creating ? CombinationService::create(itemId: $itemId) : CombinationService::change(itemId: $itemId, combinationId: $combinationId),
            body: $body,
            itemId: $itemId,
            combinationId: $combinationId,
        );

        if ($refusals !== []) {
            return $this->refuse(refusals: $refusals);
        }

        $result = $write->from(origin: 'api')->save();

        if (!$result->saved) {
            return $this->refuse(refusals: $result->refusals);
        }

        foreach ($result->warnings as $warning) {
            $warnings[] = ['code' => 'not_applied', 'title' => $warning['message']];
        }

        $this->answer(data: new Resource((object) CombinationReader::one(itemId: $itemId, combinationId: $result->id), new JoomlaSerializer($this->contentType)), status: $creating ? 201 : 200);

        if ($creating) {
            $this->app->setHeader('Location', Uri::root() . 'api/index.php/v1/alfa/items/' . $itemId . '/combinations/' . $result->id, true);
        }

        if ($warnings !== []) {
            $this->app->getDocument()->addMeta('warnings', $warnings);
        }

        return $this;
    }

    /**
     * Remove a combination: 204 when it is deleted, 200 with the archived combination and meta.note when order history
     * names it, 409 while it holds stock.
     *
     * @return static
     *
     * @since  2.3.0
     */
    private function remove(int $itemId, int $combinationId)
    {
        $result = CombinationService::remove(itemId: $itemId, combinationIds: [$combinationId], origin: 'api');

        if (!$result->saved) {
            return $this->refuse(refusals: $result->refusals);
        }

        if ($result->archived !== []) {
            $this->answer(data: new Resource((object) CombinationReader::one(itemId: $itemId, combinationId: $combinationId, archived: true), new JoomlaSerializer($this->contentType)));
            $this->app->getDocument()->addMeta('note', 'Order history names this combination: it is archived, kept as the stock identity its orders are fulfilled against, not deleted.');

            return $this;
        }

        $this->app->setHeader('status', '204', true);

        return $this;
    }

    /**
     * The write a body states, in the variant window's names: its fields, prices, stock and opening stock; the
     * refusals of keys that cannot be read; the warnings of keys it does not take.
     *
     * @return array{0: CombinationService, 1: list<Refusal>, 2: list<array<string, string>>}
     *
     * @since  2.3.0
     */
    private function writeOf(CombinationService $write, array $body, int $itemId, int $combinationId): array
    {
        $refusals = [];
        $warnings = [];

        foreach (array_diff(array_map('strval', array_keys($body)), self::BODY_KEYS) as $key) {
            $warnings[] = ['code' => 'not_a_field', 'title' => $key . ' is not a key of a combination; it was left out.', 'pointer' => '/' . $key];
        }

        if (\is_array($body['values'] ?? null)) {
            $write->values(values: $body['values']);
        }

        foreach (['sku', 'gtin', 'mpn'] as $key) {
            if (\array_key_exists($key, $body)) {
                if (!\is_string($body[$key]) || mb_strlen($body[$key]) > 255 || preg_match('/[\x00-\x1F\x7F]/', $body[$key]) === 1) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send text of at most 255 characters, without control characters.', pointer: '/' . $key);
                } else {
                    $write->set(field: $key, value: trim($body[$key]));
                }
            }
        }

        foreach (['state' => [0, 1], 'manage_stock' => [0, 1, null]] as $key => $allowed) {
            if (\array_key_exists($key, $body)) {
                if (!\in_array($body[$key], $allowed, true)) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send ' . implode(' or ', array_map(static fn ($v): string => json_encode($v), $allowed)) . '.', pointer: '/' . $key);
                } else {
                    $write->set(field: $key, value: $body[$key] === null ? '' : (string) $body[$key]);
                }
            }
        }

        if (\array_key_exists('default', $body)) {
            if ($body['default'] !== true) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send true to make this the combination the product opens on; another one is made so by its own write.', pointer: '/default');
            } else {
                $write->set(field: 'default', value: true);
            }
        }

        foreach ((array) ($body['overrides'] ?? []) as $key => $value) {
            if (!\array_key_exists((string) $key, ItemFieldsAdminHelper::COMBINATION_OVERRIDE_CASTS) || $key === 'manage_stock') {
                $warnings[] = ['code' => 'not_a_field', 'title' => 'overrides.' . $key . ' is not an override of a combination; it was left out.', 'pointer' => '/overrides/' . $key];
            } elseif ($value !== null && !\is_scalar($value)) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send a value, or null to inherit the product\'s.', pointer: '/overrides/' . $key);
            } else {
                $write->set(field: (string) $key, value: $value === null ? '' : (string) $value);
            }
        }

        foreach ((array) ($body['translations'] ?? []) as $tag => $texts) {
            if (!\is_array($texts) || !\in_array(MultilingualHelper::normaliseTag(tag: (string) $tag), array_map(static fn (string $t): string => MultilingualHelper::normaliseTag(tag: $t), MultilingualHelper::getLanguageTags()), true)) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send the texts of a content language of the shop, {"en_gb": {"name": "White"}}.', pointer: '/translations/' . $tag);

                continue;
            }

            if (\array_key_exists('name', $texts)) {
                $write->set(field: 'name_' . MultilingualHelper::normaliseTag(tag: (string) $tag), value: (string) $texts['name']);
            }
        }

        if (\array_key_exists('prices', $body)) {
            if (!\is_array($body['prices']) || !array_is_list($body['prices'])) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send a list of price rows, [] for none.', pointer: '/prices');
            } else {
                $write->prices(rows: array_map(static function ($row): array {
                    $row = (array) $row;
                    $mode = ($row['mode'] ?? 'price') === 'adjust' ? 'adjust' : 'price';

                    return ['_mode' => $mode, 'price_status' => (string) ($row['state'] ?? '1'),
                        $mode === 'adjust' ? 'adjust_target' : 'override_type' => $row['override_type'] ?? 'start'] + $row;
                }, $body['prices']));
            }
        }

        foreach (['stock' => 'stock', 'opening_stock' => 'opening'] as $key => $kind) {
            if (!\array_key_exists($key, $body)) {
                continue;
            }

            $figures = [];

            foreach ((array) $body[$key] as $n => $line) {
                $location = (int) ($line['location_id'] ?? 0);
                $quantity = $line['quantity'] ?? null;

                if ($location <= 0 || !(\is_string($quantity) || \is_int($quantity) || \is_float($quantity)) || !is_numeric((string) $quantity)) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send {"location_id": 1, "quantity": "5"}.', pointer: '/' . $key . '/' . $n);

                    continue;
                }

                $figures[$location] = (string) $quantity;
            }

            if ($kind === 'opening') {
                $write->opening(figures: $figures);
            } else {
                // The figures read are the stock records' now: every figure sent is a command.
                $read = [];

                foreach (CombinationReader::one(itemId: $itemId, combinationId: $combinationId)['stock_by_location'] ?? [] as $record) {
                    $read[(int) $record['location_id']] = (string) $record['quantity'];
                }

                $write->stock(figures: $figures, read: array_replace(array_fill_keys(array_keys($figures), '0'), array_intersect_key($read, $figures)));
            }
        }

        return [$write, $refusals, $warnings];
    }

    /**
     * Answer 404 for a product that does not exist.
     *
     * @throws ResourceNotFound When it does not (404).
     *
     * @since  2.3.0
     */
    private function requireItem(int $itemId): void
    {
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        if ($itemId <= 0 || (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE id = ' . $itemId)->loadResult() === 0) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }
    }

    /**
     * A route's id.
     *
     * @since  2.3.0
     */
    private function routeId(string $name): int
    {
        return max(0, (int) RequestScalar::read($this->input, $name, 0, 'int'));
    }

    /**
     * Put the data in the answer.
     *
     * @return static
     *
     * @since  2.3.0
     */
    private function answer(Resource|Collection $data, int $status = 200)
    {
        $document = $this->app->getDocument();

        if (!$document instanceof JsonapiDocument) {
            throw new Save('The answer could not be written.', 500);
        }

        $document->setData($data);
        $this->app->setHeader('status', (string) $status, true);

        return $this;
    }

    /**
     * Answer the refusals: the first refusal's status, one error each with its JSON pointer; nothing was written.
     *
     * @param list<Refusal> $refusals The refusals.
     *
     * @return static
     *
     * @since  2.3.0
     */
    private function refuse(array $refusals)
    {
        $document = $this->app->getDocument();

        if (!$document instanceof JsonapiDocument) {
            throw new Save('The answer could not be written.', 500);
        }

        $errors = [];

        foreach ($refusals as $refusal) {
            $sentence = $refusal->placed();
            $errors[] = ['title' => str_ends_with($sentence, 'Nothing was saved.') ? $sentence : $sentence . ' Nothing was saved.', 'code' => $refusal->status]
                + ($refusal->pointer === '' ? [] : ['source' => ['pointer' => $refusal->pointer]]);
        }

        $document->setErrors($errors);
        $this->app->setHeader('status', (string) ($refusals[0]->status ?? 422), true);

        return $this;
    }
}
