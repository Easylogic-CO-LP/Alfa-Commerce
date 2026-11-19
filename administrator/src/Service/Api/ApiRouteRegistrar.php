<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Api;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Api\ApiRequestEvent;
use Alfa\Component\Alfa\Administrator\Event\Api\ApiRoutesEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;
use RuntimeException;
use Throwable;

/**
 * The routes alfa plugins add to the REST API (2.2.2, P3-445): the bridge from Joomla's onBeforeApiRoute, which only the
 * webservices and system groups hear, to the alfa plugin an address names.
 *
 * At route time ({@see self::registerFor()}, called by Alfa's webservices plugin after core's own routes) a request
 * under v1/alfa/ext/<element>/ boots that plugin alone and asks it, with the targeted event onAlfaBeforeApiRoute, to
 * write its routes on a router of its own; each route that keeps the rules of {@see self::accept()} is put on the
 * application's router, its controller rewritten to ext.dispatch, and kept in {@see ApiRouteRegistry} for
 * ExtController. Every other API request pays one string comparison. Tools, Security lists what the plugins declare
 * through {@see self::collect()}, the same reading, and core's own routes through {@see self::collectCore()}, which asks
 * the webservices plugin for its list, so the listing and the router cannot disagree.
 *
 * A plugin adds routes of its own under its prefix; it never replaces, removes or shadows a core route: its routes are
 * put after core's, under v1/alfa/ext/, where no core route is registered.
 *
 * @since  2.2.2
 */
final class ApiRouteRegistrar
{
    /**
     * The start of every address an extension adds; no core route is ever registered under it.
     *
     * @since  2.2.2
     */
    public const PREFIX = 'v1/alfa/ext/';

    /**
     * The element of Alfa's plugin of the webservices group, which registers core's routes.
     *
     * @since  2.2.2
     */
    public const WEBSERVICES_PLUGIN = 'alfa';

    /**
     * The log category of the routes extensions add.
     *
     * @since  2.2.2
     */
    public const LOG = 'com_alfa.api';

    /**
     * The largest body a route takes when it declares none, in bytes.
     *
     * @since  2.2.2
     */
    public const DEFAULT_MAX_BODY = 1048576;

    /**
     * The largest body a route may declare, in bytes.
     *
     * @since  2.2.2
     */
    public const MAX_BODY_LIMIT = 8388608;

    /**
     * How long a caller waits before it asks again an extension that cannot be loaded, in seconds.
     *
     * @since  2.2.2
     */
    public const RETRY_AFTER = 600;

    /**
     * The JSON:API media type, the default of a route that names none.
     *
     * @since  2.2.2
     */
    public const JSONAPI = 'application/vnd.api+json';

    /**
     * The plain JSON media type.
     *
     * @since  2.2.2
     */
    public const JSON = 'application/json';

    /**
     * The methods a route may answer.
     *
     * @since  2.2.2
     */
    private const METHODS = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

    /**
     * The rules a variable may have, after one outer pair of parentheses is taken off.
     *
     * @since  2.2.2
     */
    private const VARIABLE_RULES = ['\d+', '[A-Za-z0-9_-]{1,64}'];

    /**
     * The defaults a route may carry.
     *
     * @since  2.2.2
     */
    private const DEFAULT_KEYS = ['component', 'alfa_permission', 'format', 'alfa_max_body', 'public'];

    /**
     * The most segments a route may have after its prefix.
     *
     * @since  2.2.2
     */
    private const MAX_SEGMENTS = 6;

    /**
     * Put the routes of the alfa plugin the request's address names on the application's router (2.2.2): called once,
     * by Alfa's webservices plugin at the end of onBeforeApiRoute, after core's routes.
     *
     * Nothing is loaded or asked unless the address starts with v1/alfa/ext/; under it a request with no Accept header
     * takes the route's one format. In maintenance (Clear everything) one
     * public route takes every address under the prefix, so Alfa's API dispatcher answers 503 with no plugin asked. A
     * plugin that is not installed, not enabled, or declares no route leaves the address to Joomla's 404; one that is
     * enabled but cannot be loaded, or whose declaration throws, is answered 503 by ExtController.
     *
     * @param ApiRouter $router The application's router.
     *
     * @since  2.2.2
     */
    public static function registerFor(ApiRouter $router): void
    {
        $path = trim((string) $router->getRoutePath(), '/');

        if (!str_starts_with($path . '/', self::PREFIX)) {
            return;
        }

        $element = explode('/', substr($path, \strlen(self::PREFIX)))[0];

        if (preg_match('/^[a-z0-9_-]+$/', $element) !== 1) {
            return;
        }

        // A request with no Accept header takes the route's one format: an integrator's server that sends none (Base's
        // may) is answered in the route's format instead of Joomla's 406. A header sent is negotiated as Joomla does.
        $server = Factory::getApplication()->getInput()->server;

        if (trim((string) $server->getString('HTTP_ACCEPT', '')) === '') {
            $server->set('HTTP_ACCEPT', '*/*');
        }

        if (MaintenanceState::active()) {
            $router->addRoute(new Route(self::METHODS, rtrim(self::PREFIX, '/') . '/*', 'ext.dispatch', [], [
                'component' => 'com_alfa',
                'public' => true,
                'format' => [self::JSONAPI, self::JSON],
            ]));

            return;
        }

        // The helper boots com_alfa (its log categories with it), as every Alfa API request does at dispatch.
        $plugins = AlfaPluginHelper::get();

        $id = PluginId::from(PanelDispatcher::PLATFORM_GROUP, $element);

        // Nothing is logged at route time, before the token is checked (any caller could add a line): a reserved element
        // and the refused declarations are said on Tools, Security (collect()), a plugin that cannot answer after the
        // login (ExtController).
        if (self::reservedElement($element) || !$plugins->isAvailable($id)) {
            return;
        }

        try {
            $plugin = $plugins->bootSingle($id);
        } catch (PluginUnavailable $e) {
            self::unavailable($router, $element, $e->reason, $e->getMessage());

            return;
        }

        if (!$plugins->declares($plugin, 'onAlfaBeforeApiRoute')) {
            return;
        }

        try {
            $own = new ApiRouter(Factory::getApplication());
            $plugins->dispatchTo($plugin, new ApiRoutesEvent($own, $element));
        } catch (Throwable $e) {
            self::unavailable($router, $element, PluginUnavailable::UNLOADABLE, 'it threw while it declared its routes, ' . $e::class . ': ' . $e->getMessage());

            return;
        }

        $verdict = self::acceptAll($id, $own->getRoutes());

        foreach ($verdict['accepted'] as $declaration) {
            $router->addRoute(new Route($declaration->methods, $declaration->pattern, 'ext.dispatch', $declaration->rules, [
                'component' => 'com_alfa',
                'public' => false,
                'format' => [$declaration->mediaType],
                'alfa_ext' => $element,
                'alfa_ext_task' => $declaration->task,
                'alfa_ext_route' => $declaration->key,
            ]));
        }

        ApiRouteRegistry::put($element, $verdict['accepted']);
    }

    /**
     * What every enabled alfa plugin that declares routes declares (2.2.2), for Tools, Security: the accepted routes,
     * the refused ones with their rule, and a plugin that cannot be loaded with the reason. The same reading as
     * {@see self::registerFor()}; it boots those plugins, on that screen only.
     *
     * @return list<array{element: string, name: string, accepted: list<ApiRouteDeclaration>, refused: list<ApiRouteRefusal>, unavailable: string}>
     *
     * @since  2.2.2
     */
    public static function collect(): array
    {
        $plugins = AlfaPluginHelper::get();
        $listing = [];

        foreach ($plugins->available(PanelDispatcher::PLATFORM_GROUP) as $id) {
            $entry = ['element' => $id->element, 'name' => $plugins->name($id), 'accepted' => [], 'refused' => [], 'unavailable' => ''];

            if (self::reservedElement($id->element)) {
                self::warn('The extension ' . $id . ' is named like a core permission section: it owns no routes.');
            }

            try {
                $plugin = $plugins->bootSingle($id);
            } catch (PluginUnavailable $e) {
                $listing[] = ['unavailable' => $e->reason] + $entry;

                continue;
            }

            if (!$plugins->declares($plugin, 'onAlfaBeforeApiRoute')) {
                continue;
            }

            try {
                $own = new ApiRouter(Factory::getApplication());
                $plugins->dispatchTo($plugin, new ApiRoutesEvent($own, $id->element));
            } catch (Throwable $e) {
                Log::add('Tools: the extension ' . $id . ' threw while it declared its routes (' . $e::class . ': ' . $e->getMessage() . ').', Log::ERROR, self::LOG);
                $listing[] = ['unavailable' => PluginUnavailable::UNLOADABLE] + $entry;

                continue;
            }

            $verdict = self::acceptAll($id, $own->getRoutes());

            foreach ($verdict['refused'] as $refusal) {
                self::warn('The route ' . $refusal->methods . ' ' . $refusal->pattern . ' of the extension ' . $id->element
                    . ' was not added: ' . $refusal->rule . ', ' . $refusal->detail . '. Its other routes are added.');
            }

            $listing[] = ['accepted' => $verdict['accepted'], 'refused' => $verdict['refused']] + $entry;
        }

        return $listing;
    }

    /**
     * The routes core puts on the REST API (2.2.2), for Tools, Security: Alfa's webservices plugin is asked for them on
     * a router of its own, through the handler that registers them at route time, so this reads the one list the
     * router serves; each route with what its controller's task asks ({@see ApiTaskAccess}, the declaration the
     * controller's own check enforces), null for a task the controller does not map.
     *
     * Building it boots that plugin and makes each REST controller once, on that screen only; no route under
     * v1/alfa/ext/ is in it (the request is not one).
     *
     * @return list<array{route: Route, access: ApiTaskAccess|null}>
     *
     * @throws RuntimeException When the webservices plugin does not register routes.
     *
     * @since  2.2.2
     */
    public static function collectCore(): array
    {
        $app = Factory::getApplication();
        $plugin = $app->bootPlugin(self::WEBSERVICES_PLUGIN, 'webservices');
        $handler = $plugin instanceof SubscriberInterface ? ($plugin::getSubscribedEvents()['onBeforeApiRoute'] ?? null) : null;
        $handler = \is_array($handler) ? ($handler[0] ?? null) : $handler;

        if (!\is_string($handler) || !method_exists($plugin, $handler)) {
            throw new RuntimeException('The plugin webservices/' . self::WEBSERVICES_PLUGIN . ' does not register the REST API routes.');
        }

        $router = new ApiRouter($app);
        $plugin->$handler(new BeforeApiRouteEvent('onBeforeApiRoute', ['subject' => $app, 'router' => $router]));

        $factory = $app->bootComponent('com_alfa')->getMVCFactory();
        $controllers = [];
        $listing = [];

        foreach ($router->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), self::PREFIX)) {
                continue;
            }

            [$name, $task] = explode('.', (string) $route->getController(), 2) + ['', ''];

            if (!\array_key_exists($name, $controllers)) {
                $controllers[$name] = $name !== '' ? $factory->createController($name, 'Api', [], $app, $app->getInput()) : null;
            }

            $controller = $controllers[$name];
            $listing[] = [
                'route' => $route,
                'access' => $controller !== null && method_exists($controller, 'taskAccess') ? $controller->taskAccess($task) : null,
            ];
        }

        return $listing;
    }

    /**
     * Check one route an alfa plugin made against the rules (2.2.2): the owner, the pattern under the plugin's prefix,
     * the variables and their rules, the methods, the controller and the defaults. A route that keeps them becomes its
     * declaration; one that breaks a rule becomes the refusal that names it.
     *
     * @param PluginId $owner The plugin that made the route.
     * @param Route $route The route.
     * @param string $key The route's key within the plugin.
     *
     * @since  2.2.2
     */
    public static function accept(PluginId $owner, Route $route, string $key = '0'): ApiRouteDeclaration|ApiRouteRefusal
    {
        $element = $owner->element;
        $methods = $route->getMethods();
        $pattern = $route->getPattern();
        $refuse = static fn (string $rule, string $detail): ApiRouteRefusal => new ApiRouteRefusal(
            element: $element,
            methods: implode(',', array_map('strval', $methods)),
            pattern: $pattern,
            rule: $rule,
            detail: $detail,
        );

        if ($owner->group !== PanelDispatcher::PLATFORM_GROUP) {
            return $refuse(ApiRouteRefusal::OWNER, 'only a plugin of the alfa group owns routes');
        }

        if (self::reservedElement($element)) {
            return $refuse(ApiRouteRefusal::OWNER, 'the element is the name of a core permission section');
        }

        // The pattern: the prefix, then one to six segments, each a lower-case word or a variable.
        $prefix = self::PREFIX . $element . '/';

        if (!str_starts_with($pattern, $prefix)) {
            return $refuse(ApiRouteRefusal::PATTERN, 'it does not start with ' . $prefix);
        }

        $segments = explode('/', substr($pattern, \strlen($prefix)));

        if (\count($segments) > self::MAX_SEGMENTS) {
            return $refuse(ApiRouteRefusal::PATTERN, 'more than ' . self::MAX_SEGMENTS . ' segments after the prefix');
        }

        $variables = [];

        foreach ($segments as $segment) {
            if (preg_match('/^:([a-z][a-zA-Z0-9]{0,31})$/', $segment, $match) === 1) {
                if (isset($variables[$match[1]])) {
                    return $refuse(ApiRouteRefusal::PATTERN, 'the variable :' . $match[1] . ' twice');
                }

                $variables[$match[1]] = true;

                continue;
            }

            if (preg_match('/^[a-z0-9-]+$/', $segment) !== 1) {
                return $refuse(ApiRouteRefusal::PATTERN, 'the segment "' . $segment . '" (lower-case a to z, 0 to 9 and -, or a variable :name)');
            }
        }

        // Each variable has one of the two rules core takes; no rule names no variable.
        $rules = [];

        foreach ($route->getRules() as $name => $rule) {
            $name = (string) $name;
            $rule = (string) $rule;

            if (str_starts_with($rule, '(') && str_ends_with($rule, ')')) {
                $rule = substr($rule, 1, -1);
            }

            if (!isset($variables[$name])) {
                return $refuse(ApiRouteRefusal::RULES, 'a rule for ' . $name . ', which the pattern does not have');
            }

            if (!\in_array($rule, self::VARIABLE_RULES, true)) {
                return $refuse(ApiRouteRefusal::RULES, 'the rule of :' . $name . ' (\d+ or [A-Za-z0-9_-]{1,64})');
            }

            $rules[$name] = $rule;
        }

        foreach (array_keys($variables) as $name) {
            if (!isset($rules[$name])) {
                return $refuse(ApiRouteRefusal::RULES, 'the variable :' . $name . ' has no rule');
            }
        }

        // The methods.
        if ($methods === []) {
            return $refuse(ApiRouteRefusal::METHODS, 'no method');
        }

        foreach ($methods as $method) {
            if (!\in_array($method, self::METHODS, true)) {
                return $refuse(ApiRouteRefusal::METHODS, 'the method ' . $method . ' (GET, POST, PATCH, PUT or DELETE)');
            }
        }

        // The controller: the plugin's own element and a task.
        $controller = $route->getController();

        if (!\is_string($controller) || preg_match('/^' . preg_quote($element, '/') . '\.([a-zA-Z][a-zA-Z0-9_]{0,63})$/', $controller, $task) !== 1) {
            return $refuse(ApiRouteRefusal::CONTROLLER, 'the controller must be ' . $element . '.<task>');
        }

        // The defaults: only the keys core takes, each with a value core takes.
        $defaults = $route->getDefaults();

        foreach (array_keys($defaults) as $name) {
            if (!\in_array($name, self::DEFAULT_KEYS, true)) {
                return $refuse(ApiRouteRefusal::DEFAULTS, 'the key ' . $name . ' (component, alfa_permission, format, alfa_max_body and public only)');
            }
        }

        if (($defaults['component'] ?? null) !== 'com_alfa') {
            return $refuse(ApiRouteRefusal::DEFAULTS, 'component must be com_alfa');
        }

        $permission = $defaults['alfa_permission'] ?? null;

        if (!\is_string($permission) || preg_match('/^alfa\.' . preg_quote($element, '/') . '\.[a-z][a-z0-9_.]{0,63}$/', $permission) !== 1) {
            return $refuse(ApiRouteRefusal::DEFAULTS, 'alfa_permission must be alfa.' . $element . '.<word>');
        }

        if (\array_key_exists('public', $defaults) && $defaults['public'] !== false) {
            return $refuse(ApiRouteRefusal::DEFAULTS, 'public may only be false: every route takes the Joomla API token');
        }

        $mediaType = self::JSONAPI;

        if (\array_key_exists('format', $defaults)) {
            $format = $defaults['format'];

            if (!\is_array($format) || \count($format) !== 1 || !\is_string($format[array_key_first($format)])
                || preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $format[array_key_first($format)]) !== 1) {
                return $refuse(ApiRouteRefusal::DEFAULTS, 'format must be a list of one media type, type/subtype in lower case');
            }

            $mediaType = $format[array_key_first($format)];
        }

        $maxBody = self::DEFAULT_MAX_BODY;

        if (\array_key_exists('alfa_max_body', $defaults)) {
            $maxBody = $defaults['alfa_max_body'];

            if (!\is_int($maxBody) || $maxBody < 1 || $maxBody > self::MAX_BODY_LIMIT) {
                return $refuse(ApiRouteRefusal::DEFAULTS, 'alfa_max_body must be a whole number of bytes from 1 to ' . self::MAX_BODY_LIMIT);
            }
        }

        return new ApiRouteDeclaration(
            element: $element,
            key: $key,
            methods: array_values($methods),
            pattern: $pattern,
            rules: $rules,
            task: $task[1],
            permission: $permission,
            mediaType: $mediaType,
            kind: match ($mediaType) {
                self::JSONAPI => ApiRequestEvent::KIND_JSONAPI,
                self::JSON => ApiRequestEvent::KIND_JSON,
                default => ApiRequestEvent::KIND_RAW,
            },
            maxBody: $maxBody,
        );
    }

    /**
     * Whether an element is the name of a core permission section (or a screen or entity that maps to one), so that
     * alfa.<element>.<word> could be a core permission: such an element owns no routes.
     *
     * @param string $element The element.
     *
     * @since  2.2.2
     */
    public static function reservedElement(string $element): bool
    {
        static $reserved = null;

        if ($reserved === null) {
            $reserved = array_merge(
                array_keys(AlfaHelper::PERMISSION_SECTIONS),
                array_values(AlfaHelper::PERMISSION_SECTIONS),
                AlfaHelper::LIST_SCREENS,
            );

            // The sections access.xml declares, read as Joomla reads them.
            $actions = Access::getActionsFromFile(JPATH_ADMINISTRATOR . '/components/com_alfa/access.xml') ?: [];

            foreach ($actions as $action) {
                $parts = explode('.', (string) ($action->name ?? ''));

                if (($parts[0] ?? '') === 'alfa' && isset($parts[1])) {
                    $reserved[] = $parts[1];
                }
            }

            $reserved = array_fill_keys(array_unique($reserved), true);
        }

        return isset($reserved[$element]);
    }

    /**
     * Check every route a plugin made: each accepted or refused, a second route of the same method and pattern refused.
     *
     * @param PluginId $owner The plugin.
     * @param list<Route> $routes The routes on its router.
     *
     * @return array{accepted: list<ApiRouteDeclaration>, refused: list<ApiRouteRefusal>}
     *
     * @since  2.2.2
     */
    private static function acceptAll(PluginId $owner, array $routes): array
    {
        $accepted = [];
        $refused = [];
        $seen = [];

        foreach (array_values($routes) as $index => $route) {
            $verdict = self::accept($owner, $route, (string) $index);

            if ($verdict instanceof ApiRouteDeclaration) {
                foreach ($verdict->methods as $method) {
                    if (isset($seen[$method . ' ' . $verdict->pattern])) {
                        $verdict = new ApiRouteRefusal($owner->element, implode(',', $verdict->methods), $verdict->pattern, ApiRouteRefusal::DUPLICATE, 'the method ' . $method . ' and this pattern are declared twice');

                        break;
                    }
                }
            }

            if ($verdict instanceof ApiRouteRefusal) {
                $refused[] = $verdict;

                continue;
            }

            foreach ($verdict->methods as $method) {
                $seen[$method . ' ' . $verdict->pattern] = true;
            }

            $accepted[] = $verdict;
        }

        return ['accepted' => $accepted, 'refused' => $refused];
    }

    /**
     * One route for every address of a plugin that cannot be loaded: ExtController answers it 503 with Retry-After,
     * after the token's login, and raises the one alert.
     *
     * @param ApiRouter $router The application's router.
     * @param string $element The plugin's element.
     * @param string $reason The PluginUnavailable reason.
     * @param string $detail What happened, for the log after the login.
     *
     * @since  2.2.2
     */
    private static function unavailable(ApiRouter $router, string $element, string $reason, string $detail): void
    {
        $router->addRoute(new Route(self::METHODS, self::PREFIX . $element . '/*', 'ext.dispatch', [], [
            'component' => 'com_alfa',
            'public' => false,
            'format' => [self::JSONAPI, self::JSON],
            'alfa_ext' => $element,
            'alfa_ext_unavailable' => $reason,
            // What happened, for the log line the controller writes after the token's login (never at route time).
            'alfa_ext_detail' => mb_substr($detail, 0, 500),
        ]));
    }

    /**
     * One warning in the routes' log, written when Tools lists the routes (collect()), never while a request is routed.
     *
     * @param string $message What to say.
     *
     * @since  2.2.2
     */
    private static function warn(string $message): void
    {
        // Tools reads the routes without booting the component: the category's file is registered here too.
        AlfaLog::register();
        Log::add($message, Log::WARNING, self::LOG);
    }
}
