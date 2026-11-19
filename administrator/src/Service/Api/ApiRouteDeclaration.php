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
 * One route an alfa plugin declared and core accepted (2.2.2): what the router answers it with and what ExtController
 * reads to serve it, without asking the plugin again.
 *
 * @since  2.2.2
 */
final class ApiRouteDeclaration
{
    /**
     * @param string $element The owning plugin's element (group alfa).
     * @param string $key The route's key within the plugin, as the application's router carries it.
     * @param list<string> $methods The HTTP methods, upper case.
     * @param string $pattern The full pattern, v1/alfa/ext/<element>/...
     * @param array<string, string> $rules Each variable's rule, without outer parentheses.
     * @param string $task The task of the plugin's controller.task.
     * @param string $permission The route's permission, alfa.<element>.<word>.
     * @param string $mediaType The one media type it answers.
     * @param string $kind ApiRequestEvent::KIND_JSONAPI, KIND_JSON or KIND_RAW.
     * @param int $maxBody The largest body it takes, in bytes.
     *
     * @since  2.2.2
     */
    public function __construct(
        public readonly string $element,
        public readonly string $key,
        public readonly array $methods,
        public readonly string $pattern,
        public readonly array $rules,
        public readonly string $task,
        public readonly string $permission,
        public readonly string $mediaType,
        public readonly string $kind,
        public readonly int $maxBody,
    ) {
    }

    /**
     * The section and the action of the route's permission, as AlfaHelper::can() asks them: alfa.<element>.<word>.
     *
     * @return array{0: string, 1: string}
     *
     * @since  2.2.2
     */
    public function permissionParts(): array
    {
        return [$this->element, substr($this->permission, \strlen('alfa.' . $this->element . '.'))];
    }

    /**
     * Whether a variable's rule reads it as a whole number.
     *
     * @param string $variable The variable's name.
     *
     * @since  2.2.2
     */
    public function isNumeric(string $variable): bool
    {
        return ($this->rules[$variable] ?? '') === '\d+';
    }
}
