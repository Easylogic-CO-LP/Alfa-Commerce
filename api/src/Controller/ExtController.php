<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Api\ApiRequestEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteDeclaration;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistrar;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistry;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Document\JsonapiDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Router\ApiRouter;
use Joomla\CMS\Serializer\JoomlaSerializer;
use Joomla\CMS\Uri\Uri;
use Throwable;
use Tobscure\JsonApi\Collection;
use Tobscure\JsonApi\Resource;

/**
 * The one controller of every route an alfa plugin adds to the REST API (2.2.2, P3-445): ext.dispatch.
 *
 * The route's owner and task are read from the route the router matched, never from the request (Joomla also writes a
 * route's defaults into the request, where a body or query field of the same name could stand beside them). Before the
 * plugin is asked, Joomla has authenticated the token (401) and this controller has checked the route's permission
 * (403) and the body's size (413); a plugin that cannot be loaded is answered 503 with Retry-After and one alert for
 * staff. The plugin then answers through onAlfaApiRequest ({@see ApiRequestEvent}): JSON:API, plain JSON in its own
 * shape, or bytes of the route's media type. A handler that throws is answered 500 with a sentence that names no
 * detail; the log (com_alfa.api) holds the plugin, the route, the exception's class and its message.
 *
 * @since  2.2.2
 */
class ExtController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type; the routes are the plugins' own, so it names no resource.
     *
     * @var string
     * @since  2.2.2
     */
    protected $contentType = 'ext';

    /**
     * The default view; this controller renders none of its own.
     *
     * @var string
     * @since  2.2.2
     */
    protected $default_view = 'ext';

    /**
     * The matched route's declaration, once the permission passed.
     *
     * @since  2.2.2
     */
    private ?ApiRouteDeclaration $declaration = null;

    /**
     * The matched route's variables (its defaults and the path's values).
     *
     * @var array<string, mixed>
     * @since  2.2.2
     */
    private array $routeVars = [];

    /**
     * The route's own checks, before the task runs: the owner from the matched route, a plugin that cannot be loaded
     * (503), the route's permission (403). Only dispatch is a task here.
     *
     * @param string $task The task about to run.
     *
     * @throws ResourceNotFound When the task is not dispatch or the route names no declaration.
     * @throws NotAllowed When the identity lacks the route's permission.
     * @throws Save When the plugin cannot be loaded (503).
     *
     * @since  2.2.2
     */
    protected function assertAuthorisedForTask(string $task): void
    {
        if ($task !== 'dispatch') {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        $vars = $this->matchedRoute();
        $element = (string) ($vars['alfa_ext'] ?? '');

        if (preg_match('/^[a-z0-9_-]+$/', $element) !== 1) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        if (isset($vars['alfa_ext_unavailable'])) {
            $this->refuseUnavailable($element, (string) $vars['alfa_ext_unavailable']);
        }

        $declaration = ApiRouteRegistry::get($element, (string) ($vars['alfa_ext_route'] ?? ''));

        if ($declaration === null) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        [$section, $action] = $declaration->permissionParts();

        if (!AlfaHelper::can(section: $section, action: $action, user: $this->app->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->declaration = $declaration;
        $this->routeVars = $vars;
    }

    /**
     * Hand the request to the plugin that owns the route and write its answer.
     *
     *
     * @throws Save 413 for a body larger than the route takes; 503 when the plugin cannot be loaded; 500 when the
     *              plugin throws, gives no answer, or gives one that does not fit the route.
     *
     * @since  2.2.2
     */
    public function dispatch(): void
    {
        $declaration = $this->declaration ?? throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        $rawBody = (string) $this->input->json->getRaw();
        $length = $this->input->server->getInt('CONTENT_LENGTH', 0);

        if ($length > $declaration->maxBody || \strlen($rawBody) > $declaration->maxBody) {
            throw new Save(Text::sprintf('COM_ALFA_API_EXT_TOO_LARGE', $declaration->maxBody), 413);
        }

        AlfaHelper::loadPluginLanguage(group: PanelDispatcher::PLATFORM_GROUP, element: $declaration->element);

        $plugins = AlfaPluginHelper::get();
        $id = PluginId::from(PanelDispatcher::PLATFORM_GROUP, $declaration->element);

        try {
            $plugin = $plugins->resolve($id) ?? $plugins->bootSingle($id);
        } catch (PluginUnavailable $e) {
            $this->refuseUnavailable($declaration->element, $e->reason);
        }

        $event = new ApiRequestEvent(
            task: $declaration->task,
            method: strtoupper((string) $this->input->getMethod()),
            kind: $declaration->kind,
            variables: $this->variables($declaration),
            query: Uri::getInstance()->getQuery(true),
            requestHeaders: $this->requestHeaders(),
            rawBody: $rawBody,
            form: $this->form($declaration),
            identity: $this->app->getIdentity(),
        );

        try {
            $plugins->dispatchTo($plugin, $event);
        } catch (Throwable $e) {
            $this->fail($declaration, $e::class . ': ' . $e->getMessage());
        }

        $answer = $event->answerGiven();

        if ($answer === null) {
            Log::add($this->routeName($declaration) . ': the extension gave no answer.', Log::ERROR, ApiRouteRegistrar::LOG);

            throw new Save(Text::_('COM_ALFA_API_EXT_NO_ANSWER'), 500);
        }

        $this->answer($declaration, $event, $answer);
    }

    /**
     * Write the plugin's answer: a JSON:API document the application renders, or plain JSON or bytes sent at once.
     *
     * @param ApiRouteDeclaration $declaration The route.
     * @param ApiRequestEvent $event The answered event.
     * @param array<string, mixed> $answer Its answer.
     *
     *
     * @since  2.2.2
     */
    private function answer(ApiRouteDeclaration $declaration, ApiRequestEvent $event, array $answer): void
    {
        foreach ($event->headersGiven() as $header) {
            $this->app->setHeader($header['name'], $header['value'], true);
        }

        $status = (int) $answer['status'];
        $refused = !empty($answer['refused']);

        if ($declaration->kind === ApiRequestEvent::KIND_JSONAPI) {
            // A refusal is Joomla's JSON:API error: the status and the sentence, as every core refusal reads.
            if ($refused) {
                throw new Save((string) $answer['title'], $status);
            }

            $document = $this->app->getDocument();

            if (!$document instanceof JsonapiDocument) {
                $this->fail($declaration, 'the document of a JSON:API route is not a JSON:API document');
            }

            try {
                $serializer = new JoomlaSerializer((string) $answer['type']);
                $element = isset($answer['rows'])
                    ? new Collection(array_map(static fn (array $row): object => (object) $row, $answer['rows']), $serializer)
                    : new Resource((object) (['id' => $answer['id']] + $answer['attributes']), $serializer);
                $document->setData($element);

                foreach ((array) $answer['meta'] as $key => $value) {
                    $document->addMeta((string) $key, $value);
                }
            } catch (Throwable $e) {
                $this->fail($declaration, $e::class . ': ' . $e->getMessage());
            }

            $this->app->setHeader('status', (string) $status, true);

            return;
        }

        if ($refused) {
            $body = $declaration->kind === ApiRequestEvent::KIND_JSON
                ? $this->encode($declaration, ['errors' => [['title' => (string) $answer['title'], 'code' => $status]]])
                : (string) $answer['title'];
            $type = $declaration->kind === ApiRequestEvent::KIND_JSON ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8';
        } elseif ($declaration->kind === ApiRequestEvent::KIND_JSON) {
            $body = $this->encode($declaration, (array) $answer['payload']);
            $type = 'application/json; charset=utf-8';
        } else {
            $body = (string) $answer['body'];
            $type = $declaration->mediaType;
        }

        // Written as the documents' PDF is: the headers, the bytes, the end of the request.
        $this->app->setHeader('status', (string) $status, true);
        $this->app->setHeader('Content-Type', $type, true);
        $this->app->setHeader('Content-Length', (string) \strlen($body), true);
        $this->app->sendHeaders();

        echo $body;

        $this->app->close();
    }

    /**
     * The plugin's JSON, encoded; one it cannot be encoded (a text that is not UTF-8) is the plugin's failure.
     *
     * @param ApiRouteDeclaration $declaration The route.
     * @param array<mixed> $payload The answer.
     *
     * @since  2.2.2
     */
    private function encode(ApiRouteDeclaration $declaration, array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->fail($declaration, $e::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Answer 500 for the plugin's failure, with a sentence that names no detail; the log holds the detail.
     *
     * @param ApiRouteDeclaration $declaration The route.
     * @param string $detail What failed: the exception's class and message.
     *
     * @throws Save Always (500).
     *
     * @since  2.2.2
     */
    private function fail(ApiRouteDeclaration $declaration, string $detail): never
    {
        Log::add($this->routeName($declaration) . ': the extension could not answer (' . $detail . ').', Log::ERROR, ApiRouteRegistrar::LOG);

        throw new Save(Text::_('COM_ALFA_API_EXT_FAILED'), 500);
    }

    /**
     * Answer 503 with Retry-After for a plugin that is enabled but cannot be loaded, and raise the one alert staff read.
     *
     * @param string $element The plugin's element.
     * @param string $reason The PluginUnavailable reason.
     *
     * @throws Save Always (503).
     *
     * @since  2.2.2
     */
    private function refuseUnavailable(string $element, string $reason): never
    {
        try {
            NotificationHelper::pluginUnavailable(PluginId::from(PanelDispatcher::PLATFORM_GROUP, $element), $reason);
        } catch (Throwable $e) {
            Log::add('The alert for the extension ' . $element . ' could not be written (' . $e::class . ': ' . $e->getMessage() . ').', Log::ERROR, ApiRouteRegistrar::LOG);
        }

        $this->app->setHeader('Retry-After', (string) ApiRouteRegistrar::RETRY_AFTER, true);

        throw new Save(Text::_('COM_ALFA_API_EXT_UNAVAILABLE'), 503);
    }

    /**
     * The variables of the route the router matched: its defaults and the path's values.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    private function matchedRoute(): array
    {
        $route = Factory::getContainer()->get(ApiRouter::class)->parseApiRoute(strtoupper((string) $this->input->getMethod()));

        return (array) ($route['vars'] ?? []);
    }

    /**
     * The path's variables by name, a \d+ one as an int.
     *
     * @param ApiRouteDeclaration $declaration The route.
     *
     * @return array<string, int|string>
     *
     * @since  2.2.2
     */
    private function variables(ApiRouteDeclaration $declaration): array
    {
        $variables = [];

        foreach (array_keys($declaration->rules) as $name) {
            $value = (string) ($this->routeVars[$name] ?? '');
            $variables[$name] = $declaration->isNumeric($name) ? (int) $value : $value;
        }

        return $variables;
    }

    /**
     * The request's headers by lower-case name, from the server's variables.
     *
     * @return array<string, string>
     *
     * @since  2.2.2
     */
    private function requestHeaders(): array
    {
        $headers = [];

        foreach (array_keys($_SERVER) as $key) {
            $key = (string) $key;

            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = $key;
            } else {
                continue;
            }

            $headers[strtolower(str_replace('_', '-', $name))] = $this->input->server->getString($key, '');
        }

        return $headers;
    }

    /**
     * The fields of a form-encoded body, as sent, without the keys Joomla's router writes beside them.
     *
     * @param ApiRouteDeclaration $declaration The route.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    private function form(ApiRouteDeclaration $declaration): array
    {
        $reserved = array_merge(
            ['component', 'public', 'format', 'alfa_ext', 'alfa_ext_task', 'alfa_ext_route', 'alfa_ext_unavailable', 'alfa_permission', 'alfa_max_body'],
            array_keys($declaration->rules),
        );
        $form = [];

        foreach (array_keys($_POST) as $key) {
            if (!\in_array((string) $key, $reserved, true)) {
                $form[(string) $key] = $this->input->post->get((string) $key, null, 'raw');
            }
        }

        return $form;
    }

    /**
     * The route as the log names it: the plugin, the methods and the pattern.
     *
     * @param ApiRouteDeclaration $declaration The route.
     *
     * @since  2.2.2
     */
    private function routeName(ApiRouteDeclaration $declaration): string
    {
        return 'The extension ' . PanelDispatcher::PLATFORM_GROUP . '.' . $declaration->element . ', route '
            . implode(',', $declaration->methods) . ' ' . $declaration->pattern;
    }
}
