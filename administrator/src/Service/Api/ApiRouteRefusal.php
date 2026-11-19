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
 * A route an alfa plugin declared and core refused (2.2.2): the rule it broke, for the log and for Tools, Security.
 *
 * @since  2.2.2
 */
final class ApiRouteRefusal
{
    /**
     * The owner is not a plugin that may own routes.
     *
     * @since  2.2.2
     */
    public const OWNER = 'owner';

    /**
     * The pattern is not under the plugin's prefix, or a segment or a variable breaks the rules.
     *
     * @since  2.2.2
     */
    public const PATTERN = 'pattern';

    /**
     * A variable has no rule, or a rule core does not take.
     *
     * @since  2.2.2
     */
    public const RULES = 'rules';

    /**
     * A method is not one of GET, POST, PATCH, PUT and DELETE.
     *
     * @since  2.2.2
     */
    public const METHODS = 'methods';

    /**
     * The controller is not <element>.<task>.
     *
     * @since  2.2.2
     */
    public const CONTROLLER = 'controller';

    /**
     * A default is missing, has a value core does not take, or is a key core does not take.
     *
     * @since  2.2.2
     */
    public const DEFAULTS = 'defaults';

    /**
     * The plugin declared the same method and pattern twice.
     *
     * @since  2.2.2
     */
    public const DUPLICATE = 'duplicate';

    /**
     * @param string $element The plugin's element.
     * @param string $methods The route's methods, as declared, joined.
     * @param string $pattern The route's pattern, as declared.
     * @param string $rule The rule it broke (one of the constants).
     * @param string $detail What exactly, in English, for the log and the listing.
     *
     * @since  2.2.2
     */
    public function __construct(
        public readonly string $element,
        public readonly string $methods,
        public readonly string $pattern,
        public readonly string $rule,
        public readonly string $detail,
    ) {
    }
}
