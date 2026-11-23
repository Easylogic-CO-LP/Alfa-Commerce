<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Api;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\User\User;
use LogicException;

/**
 * A request on a route an alfa plugin declared (onAlfaApiRequest): addressed to the plugin that owns the route,
 * after Joomla authenticated the token and core checked the route's permission and the body's size. Its handler is the
 * plugin's public method of the event's name; a plugin without it takes no part, and its addresses answer 404.
 *
 * The plugin reads the request through the read methods and answers with exactly one of respondData(), respondList(),
 * respondJson(), respondRaw() or refuse(); header() adds a header of the few the answer may carry. The respond method
 * must fit the route's format: respondData() and respondList() a JSON:API route (the default), respondJson() a route
 * whose format is application/json, respondRaw() a route of any other media type. A second answer, or one that does
 * not fit the route, throws LogicException: the plugin's mistake, which core answers 500 and logs.
 *
 *   public function onAlfaApiRequest(ApiRequestEvent $event): void
 *   {
 *       if ($event->getTask() === 'status') {
 *           $event->respondData('base-status', ['version' => '1.0.0'], 1);
 *       }
 *   }
 *
 * @since  2.2.2
 */
final class ApiRequestEvent extends AbstractEvent
{
    /**
     * The answer kind of a JSON:API route.
     *
     * @since  2.2.2
     */
    public const KIND_JSONAPI = 'jsonapi';

    /**
     * The answer kind of an application/json route.
     *
     * @since  2.2.2
     */
    public const KIND_JSON = 'json';

    /**
     * The answer kind of a route of any other media type.
     *
     * @since  2.2.2
     */
    public const KIND_RAW = 'raw';

    /**
     * The statuses refuse() takes.
     *
     * @since  2.2.2
     */
    public const REFUSALS = [400, 403, 404, 405, 409, 422, 429];

    /**
     * The statuses the respond methods take.
     *
     * @since  2.2.2
     */
    public const SUCCESSES = [200, 201, 202];

    /**
     * The headers header() sets.
     *
     * @since  2.2.2
     */
    public const HEADERS = ['location', 'retry-after', 'etag', 'cache-control', 'content-disposition'];

    /**
     * The headers getHeader() never gives: the credential stays Joomla's.
     *
     * @since  2.2.2
     */
    private const HIDDEN_HEADERS = ['x-joomla-token', 'authorization'];

    /**
     * The answer, once given.
     *
     * @var array{kind: string, status: int, type?: string, attributes?: array, id?: int|string|null, rows?: array, meta?: array, payload?: array, body?: string, title?: string}|null
     *
     * @since  2.2.2
     */
    private ?array $answer = null;

    /**
     * The headers the plugin set, by lower-case name.
     *
     * @var array<string, array{name: string, value: string}>
     *
     * @since  2.2.2
     */
    private array $headers = [];

    /**
     * @param string $task The task of the route's controller.task.
     * @param string $method The HTTP method.
     * @param string $kind The route's answer kind (KIND_JSONAPI, KIND_JSON or KIND_RAW).
     * @param array<string, int|string> $variables The path's variables by name.
     * @param array<string, mixed> $query The query string, decoded.
     * @param array<string, string> $requestHeaders The request's headers by lower-case name.
     * @param string $rawBody The body as sent.
     * @param array<string, mixed> $form The form fields of a form-encoded body.
     * @param User $identity The token's user.
     *
     * @since  2.2.2
     */
    public function __construct(
        private readonly string $task,
        private readonly string $method,
        private readonly string $kind,
        private readonly array $variables,
        private readonly array $query,
        private readonly array $requestHeaders,
        private readonly string $rawBody,
        private readonly array $form,
        private readonly User $identity,
    ) {
        parent::__construct('onAlfaApiRequest', ['task' => $task, 'method' => $method]);
    }

    /**
     * The task of the route's controller.task: what the plugin wrote after the dot (connector for base.connector;
     * displayList, displayItem, add, edit and delete for the five routes createCRUDRoutes() makes).
     *
     * @since  2.2.2
     */
    public function getTask(): string
    {
        return $this->task;
    }

    /**
     * The HTTP method, upper case.
     *
     * @since  2.2.2
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * The path's variables by name: a variable whose rule is \d+ as an int, any other as a string.
     *
     * @return array<string, int|string>
     *
     * @since  2.2.2
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * The query string, decoded.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * One request header, '' when it was not sent. The token's headers (X-Joomla-Token, Authorization) always answer ''.
     *
     * @param string $name The header's name, in any case.
     *
     * @since  2.2.2
     */
    public function getHeader(string $name): string
    {
        $name = strtolower(trim($name));

        return \in_array($name, self::HIDDEN_HEADERS, true) ? '' : (string) ($this->requestHeaders[$name] ?? '');
    }

    /**
     * The body as it was sent.
     *
     * @since  2.2.2
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * The body decoded as JSON, null when it is not a JSON object or list.
     *
     * @return array<mixed>|null
     *
     * @since  2.2.2
     */
    public function getJsonBody(): ?array
    {
        if (trim($this->rawBody) === '') {
            return null;
        }

        $decoded = json_decode($this->rawBody, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The form fields of a form-encoded body, as sent, without the keys Joomla's router writes beside them.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    public function getForm(): array
    {
        return $this->form;
    }

    /**
     * The account whose token the request carries.
     *
     * @since  2.2.2
     */
    public function getIdentity(): User
    {
        return $this->identity;
    }

    /**
     * Answer one JSON:API resource.
     *
     * @param string $type The resource type.
     * @param array<string, mixed> $attributes Its attributes.
     * @param int|string|null $id Its id.
     * @param array<string, mixed> $meta The document's meta.
     * @param int $status 200, 201 or 202.
     *
     * @throws LogicException When the route is not a JSON:API route, an answer was already given, or the status is
     *                        not one of 200, 201 and 202.
     *
     * @since  2.2.2
     */
    public function respondData(string $type, array $attributes, int|string|null $id = null, array $meta = [], int $status = 200): void
    {
        $this->answer(self::KIND_JSONAPI, $status, ['type' => $type, 'attributes' => $attributes, 'id' => $id, 'meta' => $meta]);
    }

    /**
     * Answer a JSON:API list: each row's `id` becomes the resource's id.
     *
     * @param string $type The resources' type.
     * @param list<array<string, mixed>> $rows The rows.
     * @param array<string, mixed> $meta The document's meta.
     * @param int $status 200, 201 or 202.
     *
     * @throws LogicException When the route is not a JSON:API route, an answer was already given, or the status is
     *                        not one of 200, 201 and 202.
     *
     * @since  2.2.2
     */
    public function respondList(string $type, array $rows, array $meta = [], int $status = 200): void
    {
        $this->answer(self::KIND_JSONAPI, $status, ['type' => $type, 'rows' => array_values($rows), 'meta' => $meta]);
    }

    /**
     * Answer plain JSON in the plugin's own shape (a dialect such as Base's), Content-Type application/json.
     *
     * @param array<mixed> $payload The answer.
     * @param int $status 200, 201 or 202.
     *
     * @throws LogicException When the route is not an application/json route, an answer was already given, or the
     *                        status is not one of 200, 201 and 202.
     *
     * @since  2.2.2
     */
    public function respondJson(array $payload, int $status = 200): void
    {
        $this->answer(self::KIND_JSON, $status, ['payload' => $payload]);
    }

    /**
     * Answer bytes of the route's own media type.
     *
     * @param string $body The bytes.
     * @param int $status 200, 201 or 202.
     *
     * @throws LogicException When the route is a JSON:API or application/json route, an answer was already given, or
     *                        the status is not one of 200, 201 and 202.
     *
     * @since  2.2.2
     */
    public function respondRaw(string $body, int $status = 200): void
    {
        $this->answer(self::KIND_RAW, $status, ['body' => $body]);
    }

    /**
     * Refuse the request with a status and one sentence the caller reads: JSON:API errors on a JSON:API route,
     * {"errors":[{"code":...,"title":...}]} on an application/json route, the sentence as text/plain otherwise.
     *
     * @param int $status 400, 403 (a permission the plugin asks inside), 404, 405, 409, 422 or 429.
     * @param string $sentence What the caller reads.
     *
     * @throws LogicException When an answer was already given or the status is not one refuse() takes.
     *
     * @since  2.2.2
     */
    public function refuse(int $status, string $sentence): void
    {
        if (!\in_array($status, self::REFUSALS, true)) {
            throw new LogicException('refuse() takes ' . implode(', ', self::REFUSALS) . '; given ' . $status . '.');
        }

        $this->give(['kind' => $this->kind, 'status' => $status, 'title' => $sentence, 'refused' => true]);
    }

    /**
     * Add a header to the answer: Location, Retry-After, ETag, Cache-Control or Content-Disposition only.
     *
     * @param string $name The header's name.
     * @param string $value Its value, one line.
     *
     * @throws LogicException When the header is not one of those, or its value holds a line break.
     *
     * @since  2.2.2
     */
    public function header(string $name, string $value): void
    {
        $key = strtolower(trim($name));

        if (!\in_array($key, self::HEADERS, true) || preg_match('/[\r\n]/', $value) === 1) {
            throw new LogicException('header() sets Location, Retry-After, ETag, Cache-Control or Content-Disposition, on one line; given ' . $name . '.');
        }

        $this->headers[$key] = ['name' => trim($name), 'value' => $value];
    }

    /**
     * The answer the plugin gave, or null when it gave none (core's to read).
     *
     * @return array<string, mixed>|null
     *
     * @since  2.2.2
     */
    public function answerGiven(): ?array
    {
        return $this->answer;
    }

    /**
     * The headers the plugin set (core's to read).
     *
     * @return list<array{name: string, value: string}>
     *
     * @since  2.2.2
     */
    public function headersGiven(): array
    {
        return array_values($this->headers);
    }

    /**
     * The route's answer kind (core's to read).
     *
     * @since  2.2.2
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * Record a respond method's answer after its checks.
     *
     * @param string $kind The kind the method answers.
     * @param int $status Its status.
     * @param array<string, mixed> $answer What it answers.
     *
     * @throws LogicException When the route is of another kind or the status is not a success.
     *
     * @since  2.2.2
     */
    private function answer(string $kind, int $status, array $answer): void
    {
        if ($kind !== $this->kind) {
            throw new LogicException('This route answers ' . $this->kind . '; the plugin answered ' . $kind . '.');
        }

        if (!\in_array($status, self::SUCCESSES, true)) {
            throw new LogicException('An answer takes 200, 201 or 202; given ' . $status . '.');
        }

        $this->give(['kind' => $kind, 'status' => $status] + $answer);
    }

    /**
     * Keep the one answer.
     *
     * @param array<string, mixed> $answer The answer.
     *
     * @throws LogicException When an answer was already given.
     *
     * @since  2.2.2
     */
    private function give(array $answer): void
    {
        if ($this->answer !== null) {
            throw new LogicException('The plugin answered twice; a request takes one answer.');
        }

        $this->answer = $answer;
    }
}
