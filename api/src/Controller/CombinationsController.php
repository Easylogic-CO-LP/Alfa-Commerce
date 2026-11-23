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
use Alfa\Component\Alfa\Administrator\Service\Catalog\CombinationReader;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CombinationService;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SkuIndex;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Uri\Uri;

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
    use AnswersFromServices;
    use ReadsCatalogBodies;

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

            return $this->answer(data: CombinationReader::of(itemId: $itemId));
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

        return $this->answer(data: $result['rows']);
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

        return $this->answer(data: $combination);
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

        $this->answer(data: (array) CombinationReader::one(itemId: $itemId, combinationId: $result->id), status: $creating ? 201 : 200, warnings: $warnings);

        if ($creating) {
            $this->app->setHeader('Location', Uri::root() . 'api/index.php/v1/alfa/items/' . $itemId . '/combinations/' . $result->id, true);
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
            $this->answer(data: (array) CombinationReader::one(itemId: $itemId, combinationId: $combinationId, archived: true));
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
        $warnings = $this->unknownKeys(body: $body, keys: self::BODY_KEYS, what: 'a combination');

        if (\is_array($body['values'] ?? null)) {
            $write->values(values: $body['values']);
        }

        [$fields, $prices, $refusals, $fieldWarnings] = $this->combinationFields(body: $body);
        array_push($warnings, ...$fieldWarnings);
        $write->with(fields: $fields);

        if ($prices !== null) {
            $write->prices(rows: $prices);
        }

        foreach (['stock' => 'stock', 'opening_stock' => 'opening'] as $key => $kind) {
            if (!\array_key_exists($key, $body)) {
                continue;
            }

            [$lines, $lineRefusals] = $this->stockFigures(lines: $body[$key], pointer: '/' . $key);
            array_push($refusals, ...$lineRefusals);
            $figures = array_column($lines, 'quantity', 'location_id');

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
}
