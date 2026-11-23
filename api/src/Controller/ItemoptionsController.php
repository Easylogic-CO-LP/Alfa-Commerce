<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemFieldValueService;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemOptionService;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Alfa\Component\Alfa\Api\Helper\ApiInclude;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * A product's options and specifications over the REST API, through the one way they are written
 * ({@see ItemOptionService}):
 *
 * - GET v1/alfa/items/:id/options: the product's options in their order, each with its value and price change;
 * - GET v1/alfa/items/:id/options/:valueId: one option;
 * - POST v1/alfa/items/:id/options: a value made an option, {"value_id": 71, "operation": "+", "amount": "49.00"};
 * - PATCH v1/alfa/items/:id/options/:valueId: its price change (the parts sent; the others kept) and its place (ordering);
 * - DELETE v1/alfa/items/:id/options/:valueId: removed, with its cart selections on the product's lines;
 * - GET v1/alfa/items/:id/informational: the product's specifications in their order;
 * - PUT v1/alfa/items/:id/informational: {"value_ids": [80, 81]} replaces them, in that order.
 *
 * An option or a specification reads its value's name in the request's language (lang, else the shop's default
 * content language) and, with include=translations, the value's every language. Writes ask alfa.items.edit (the task
 * is edit), reads alfa.items.view.
 *
 * @since  2.3.0
 */
class ItemoptionsController extends ApiController
{
    use HasApiGuards;
    use AnswersFromServices;

    /**
     * The keys an option's body takes.
     *
     * @since  2.3.0
     */
    private const BODY_KEYS = ['value_id', 'operation', 'amount', 'apply_on', 'ordering'];

    /**
     * @var string
     * @since  2.3.0
     */
    protected $contentType = 'itemoptions';

    /**
     * @var string
     * @since  2.3.0
     */
    protected $default_view = 'itemoptions';

    /**
     * An option is its product's: the product's permissions.
     *
     * @since  2.3.0
     */
    protected function aclSection(): string
    {
        return 'items';
    }

    /**
     * The product's options, or its specifications on the informational route.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function displayList()
    {
        $itemId = $this->requireItem();

        if ($this->informationalRoute()) {
            $this->contentType = 'iteminformational';

            return $this->answer(data: self::shown(itemId: $itemId, rows: ItemOptionService::specifications(itemId: $itemId)));
        }

        return $this->answer(data: self::shown(itemId: $itemId, rows: ItemOptionService::options(itemId: $itemId)));
    }

    /**
     * One option of the product.
     *
     * @param int|null $id Unused: the route names the product and the option's value.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function displayItem($id = null)
    {
        $itemId = $this->requireItem();

        return $this->answer(data: $this->requireOption(itemId: $itemId, valueId: $this->routeId(name: 'valueId')));
    }

    /**
     * POST adds an option; PATCH changes one; DELETE removes one; PUT on the informational route replaces the
     * specifications. The task is edit for all four, so each asks the product's edit permission.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function edit()
    {
        $itemId = $this->requireItem();
        $valueId = $this->routeId(name: 'valueId');
        $method = strtoupper($this->input->getMethod());

        if ($this->informationalRoute()) {
            return $this->replaceInformational(itemId: $itemId);
        }

        if ($method === 'DELETE') {
            $result = ItemOptionService::of(itemId: $itemId)->remove(valueIds: [$valueId])->save();

            if (!$result->saved) {
                return $this->refuse(refusals: $result->refusals);
            }

            $this->answer(data: ['id' => $itemId . '-' . $valueId, 'item_id' => $itemId, 'value_id' => $valueId, 'removed' => true]);
            $this->app->getDocument()->addMeta('cart_selections_removed', $result->cartSelectionsRemoved);

            return $this;
        }

        $creating = $method === 'POST' && $valueId === 0;
        $body = $this->bodyObject(example: '{"value_id": 71, "operation": "+", "amount": "49.00", "apply_on": "final"}');
        $warnings = $this->unknownKeys(body: $body, keys: $creating ? array_diff(self::BODY_KEYS, ['ordering']) : array_diff(self::BODY_KEYS, ['value_id']), what: 'an option');
        $refusals = [];
        $delta = [];

        foreach (['operation', 'amount', 'apply_on'] as $key) {
            if (\array_key_exists($key, $body)) {
                if ($body[$key] !== null && !\is_string($body[$key]) && !\is_int($body[$key]) && !\is_float($body[$key])) {
                    $refusals[] = new Refusal(code: 'invalid', sentence: 'send text, or null for none.', pointer: '/' . $key);
                } else {
                    $delta[$key] = $body[$key] === null || $body[$key] === '' ? null : (string) $body[$key];
                }
            }
        }

        if ($creating && !\is_int($body['value_id'] ?? null)) {
            $refusals[] = new Refusal(code: 'value_missing', sentence: 'send value_id, the id of the value the product offers as an option.', pointer: '/value_id');
        }

        $ordering = null;

        if (!$creating && \array_key_exists('ordering', $body)) {
            if (!\is_int($body['ordering']) || $body['ordering'] < 0) {
                $refusals[] = new Refusal(code: 'invalid', sentence: 'send the option\'s place in the product\'s list, a whole number from 0.', pointer: '/ordering');
            } else {
                $ordering = $body['ordering'];
            }
        }

        if ($refusals !== []) {
            return $this->refuse(refusals: $refusals);
        }

        $write = ItemOptionService::of(itemId: $itemId);

        if ($creating) {
            $valueId = (int) $body['value_id'];
            $write->add(valueId: $valueId, operation: $delta['operation'] ?? null, amount: $delta['amount'] ?? null, applyOn: $delta['apply_on'] ?? null);
        } else {
            // A PATCH changes the parts it sends; the others keep what the option holds.
            $stored = array_column(ItemOptionService::options(itemId: $itemId), null, 'value_id')[$valueId] ?? ['operation' => null, 'amount' => null, 'apply_on' => null];
            $delta += ['operation' => $stored['operation'], 'amount' => $stored['amount'], 'apply_on' => $stored['apply_on']];
            $write->change(valueId: $valueId, operation: $delta['operation'], amount: $delta['amount'], applyOn: $delta['apply_on']);

            if ($ordering !== null) {
                $order = array_values(array_diff(array_column(ItemOptionService::options(itemId: $itemId), 'value_id'), [$valueId]));
                array_splice($order, min($ordering, \count($order)), 0, [$valueId]);
                $write->order(valueIds: $order);
            }
        }

        $result = $write->save();

        if (!$result->saved) {
            return $this->refuse(refusals: $result->refusals);
        }

        $this->answer(data: $this->requireOption(itemId: $itemId, valueId: $valueId), status: $creating ? 201 : 200, warnings: $warnings);

        if ($creating) {
            $this->app->setHeader('Location', Uri::root() . 'api/index.php/v1/alfa/items/' . $itemId . '/options/' . $valueId, true);
        }

        return $this;
    }

    /**
     * PUT v1/alfa/items/:id/informational: the product's specifications replaced by the values sent, in that order.
     *
     * @return static
     *
     * @since  2.3.0
     */
    private function replaceInformational(int $itemId)
    {
        $this->contentType = 'iteminformational';
        $body = $this->bodyObject(example: '{"value_ids": [80, 81]}');
        $warnings = $this->unknownKeys(body: $body, keys: ['value_ids'], what: 'a product\'s specifications');

        if (!\is_array($body['value_ids'] ?? null) || !array_is_list($body['value_ids']) || array_filter($body['value_ids'], static fn ($id): bool => !\is_int($id) || $id <= 0) !== []) {
            return $this->refuse(refusals: [new Refusal(code: 'invalid', sentence: 'send value_ids, the list of the values the product shows, in order; [] for none.', pointer: '/value_ids')]);
        }

        $result = ItemOptionService::of(itemId: $itemId)->informational(valueIds: $body['value_ids'], pointer: '/value_ids')->save();

        if (!$result->saved) {
            return $this->refuse(refusals: $result->refusals);
        }

        return $this->answer(data: self::shown(itemId: $itemId, rows: ItemOptionService::specifications(itemId: $itemId)), warnings: $warnings);
    }

    /**
     * Options or specifications as a read shows them: each with its value's field, code and name in the request's
     * language, and the value's translations when asked.
     *
     * @param list<array> $rows Rows of ItemOptionService::options() or specifications().
     *
     * @return list<array>
     *
     * @since  2.3.0
     */
    private static function shown(int $itemId, array $rows): array
    {
        $values = ItemFieldValueService::describe(valueIds: array_column($rows, 'value_id'));
        $language = MultilingualHelper::normaliseTag(tag: MultilingualHelper::getCurrentLanguageTag());
        $translations = ApiInclude::asks(ApiInclude::TRANSLATIONS);
        $shown = [];

        foreach ($rows as $row) {
            $value = $values[$row['value_id']] ?? ['field_name' => '', 'code' => null, 'translations' => []];
            $shown[] = ['id' => $itemId . '-' . $row['value_id'], 'item_id' => $itemId] + $row
                + ['field_name' => $value['field_name'], 'code' => $value['code'], 'name' => (string) ($value['translations'][$language]['name'] ?? '')]
                + ($translations ? ['translations' => $value['translations']] : []);
        }

        return $shown;
    }

    /**
     * An option of the product as read; 404 when the value is not one of its options.
     *
     * @throws ResourceNotFound When it is not (404).
     *
     * @since  2.3.0
     */
    private function requireOption(int $itemId, int $valueId): array
    {
        foreach (self::shown(itemId: $itemId, rows: ItemOptionService::options(itemId: $itemId)) as $row) {
            if ($row['value_id'] === $valueId) {
                return $row;
            }
        }

        throw new ResourceNotFound(Text::sprintf('COM_ALFA_OPTION_NOT_FOUND', (string) $valueId), 404);
    }

    /**
     * The route's product; 404 for one that does not exist.
     *
     * @throws ResourceNotFound When it does not (404).
     *
     * @since  2.3.0
     */
    private function requireItem(): int
    {
        $itemId = $this->routeId(name: 'id');
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if ($itemId <= 0 || (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE id = ' . $itemId)->loadResult() === 0) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        return $itemId;
    }

    /**
     * Whether the request is on the informational route.
     *
     * @since  2.3.0
     */
    private function informationalRoute(): bool
    {
        return (string) $this->input->get('part', '', 'cmd') === 'informational';
    }
}
