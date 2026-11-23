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

/**
 * The routes core accepted from alfa plugins in this request, kept by the registrar at route time so that
 * ExtController reads a route's permission, answer kind and body limit without asking the plugin again. It lives for the
 * request only.
 *
 * @since  2.2.2
 */
final class ApiRouteRegistry
{
    /**
     * The accepted declarations, by element and key.
     *
     * @var array<string, array<string, ApiRouteDeclaration>>
     *
     * @since  2.2.2
     */
    private static array $routes = [];

    /**
     * Keep a plugin's accepted declarations, replacing what was kept for it.
     *
     * @param string $element The plugin's element.
     * @param list<ApiRouteDeclaration> $declarations Its accepted routes.
     *
     * @since  2.2.2
     */
    public static function put(string $element, array $declarations): void
    {
        self::$routes[$element] = [];

        foreach ($declarations as $declaration) {
            self::$routes[$element][$declaration->key] = $declaration;
        }
    }

    /**
     * One accepted declaration, or null when none is kept under that element and key.
     *
     * @param string $element The plugin's element.
     * @param string $key The route's key.
     *
     * @since  2.2.2
     */
    public static function get(string $element, string $key): ?ApiRouteDeclaration
    {
        return self::$routes[$element][$key] ?? null;
    }

    /**
     * Forget every declaration (a process that routes twice, the suites).
     *
     * @since  2.2.2
     */
    public static function clear(): void
    {
        self::$routes = [];
    }
}
