<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Api\IdempotencyKeys;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAdjustment;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryCommands;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Document\JsonapiDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Serializer\JoomlaSerializer;
use Joomla\Database\DatabaseInterface;
use Tobscure\JsonApi\Resource;

/**
 * POST v1/alfa/inventory/adjustments: stock set or corrected over the REST API, through the one way stock is
 * adjusted ({@see InventoryCommands}), with the stock editors' rules, once under the client's Idempotency-Key.
 *
 * @since  2.3.0
 */
class InventoryadjustmentsController extends ApiController
{
    use HasApiGuards;

    /**
     * The keys an adjustment's body takes.
     *
     * @since  2.3.0
     */
    private const BODY_KEYS = ['reason', 'lines'];

    /**
     * The content type of the answer.
     *
     * @var string
     * @since  2.3.0
     */
    protected $contentType = 'inventoryadjustments';

    /**
     * The default view.
     *
     * @var string
     * @since  2.3.0
     */
    protected $default_view = 'inventoryadjustments';

    /**
     * Stock is the products' own: an adjustment asks the permission the stock editors ask, alfa.items.edit.
     *
     * @since  2.3.0
     */
    protected function aclSection(): string
    {
        return 'items';
    }

    /**
     * Apply an adjustment: {"reason": "Stock take", "lines": [{"sku": "SMX-WHT-256", "location_id": 1, "set": "7"},
     * {"item_id": 42, "combination_id": 301, "delta": "-2"}]} with an Idempotency-Key header. Every line is applied in
     * one transaction or none: 200 with each line's figure before and after, the change and its operation; a refusal
     * answers 409 or 422 with the line's JSON pointer, nothing changed. The same request again with the same key
     * answers the first one's lines with meta.replayed and applies nothing. The body is read from the request's JSON
     * alone, never from data a query string can set.
     *
     * The route's task is edit, so the permission it asks is the section's edit permission on every guard.
     *
     * @return static
     *
     * @throws Save When the body is no JSON object, or the Idempotency-Key is missing or malformed (422).
     *
     * @since  2.3.0
     */
    public function edit()
    {
        $body = json_decode((string) $this->input->json->getRaw(), true);

        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new Save('Send a JSON object, {"reason": "Stock take", "lines": [{"sku": "SMX-WHT-256", "location_id": 1, "set": "7"}]}. Nothing was changed.', 422);
        }

        $key = trim((string) $this->input->server->getString('HTTP_IDEMPOTENCY_KEY', ''));

        if (!IdempotencyKeys::wellFormed(key: $key)) {
            throw new Save('Idempotency-Key: send a header of 1 to ' . IdempotencyKeys::MAX_LENGTH . ' printable characters naming this request, the same'
                . ' key when you send it again. Nothing was changed.', 422);
        }

        $reason = $body['reason'] ?? '';

        if (!\is_string($reason) || mb_strlen($reason) > 255) {
            throw new Save('reason: send a sentence of at most 255 characters, or leave it out. Nothing was changed.', 422);
        }

        $userId = (int) $this->app->getIdentity()->id;
        $outcome = InventoryCommands::adjust()
            ->lines(lines: \is_array($body['lines'] ?? null) ? $body['lines'] : [])
            ->source(type: 'api')
            ->by(context: new InventoryContext(
                userId: $userId,
                correlationId: \strlen($key) <= 64 ? $key : hash('sha256', $key),
                note: trim($reason),
            ))
            ->once(userId: $userId, key: $key, request: $body)
            ->apply();
        $warnings = $outcome->warnings;

        foreach (array_diff(array_map('strval', array_keys($body)), self::BODY_KEYS) as $unknown) {
            $warnings[] = ['code' => 'not_a_field', 'title' => $unknown . ' is not a key of an adjustment; it was left out.', 'pointer' => '/' . $unknown];
        }

        $document = $this->app->getDocument();

        if (!$document instanceof JsonapiDocument) {
            throw new Save('The answer could not be written.', 500);
        }

        if (!$outcome->applied) {
            return $this->answerRefusals(document: $document, refusals: $outcome->refusals);
        }

        $document->setData(new Resource((object) ['id' => $key, 'lines' => $this->withFigures(outcome: $outcome)], new JoomlaSerializer($this->contentType)));

        // The meta the item write answers with: a replay said, the keys left out named.
        if ($outcome->replayed) {
            $this->app->getDocument()->addMeta('replayed', true);
        }

        if ($warnings !== []) {
            $this->app->getDocument()->addMeta('warnings', $warnings);
        }

        $this->app->setHeader('status', '200', true);

        return $this;
    }

    /**
     * The applied lines with each unit's available quantity as it stands now.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.3.0
     */
    private function withFigures(InventoryAdjustment $outcome): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $lines = [];

        foreach ($outcome->lines as $line) {
            $combinationId = (int) $line['combination_id'];
            $available = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('available_quantity'))
                    ->from($db->quoteName($combinationId > 0 ? '#__alfa_item_combinations' : '#__alfa_items'))
                    ->where($db->quoteName('id') . ' = ' . ($combinationId > 0 ? $combinationId : (int) $line['item_id'])),
            )->loadResult();
            $lines[] = $line + ['available_quantity' => $available === null ? null : (float) $available];
        }

        return $lines;
    }

    /**
     * Answer the refusals: the first refusal's status, one error each with its JSON pointer; a request to send again
     * later carries Retry-After.
     *
     * @param list<Refusal> $refusals The refusals.
     *
     * @return static
     *
     * @since  2.3.0
     */
    private function answerRefusals(JsonapiDocument $document, array $refusals)
    {
        $errors = [];

        foreach ($refusals as $refusal) {
            $errors[] = ['title' => $refusal->placed(), 'code' => $refusal->status] + ($refusal->pointer === '' ? [] : ['source' => ['pointer' => $refusal->pointer]]);
        }

        $document->setErrors($errors);
        $this->app->setHeader('status', (string) ($refusals[0]->status ?? 422), true);

        if (\in_array($refusals[0]->code ?? '', ['busy', 'key_busy'], true)) {
            $this->app->setHeader('Retry-After', '2', true);
        }

        return $this;
    }
}
