<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use RuntimeException;
use Throwable;

\defined('_JEXEC') or die;

/**
 * A targeted call that cannot reach its plugin (2.2.0, design 2.15.2 and 2.15.5): each caller maps the reason to its
 * own contract (a refused placement, a 503 for a webhook, a neutral page, a staff refusal).
 *
 * @since  2.2.0
 */
final class PluginUnavailable extends RuntimeException
{
    /** No plugin of that identity is installed, or its files are gone (Joomla answers a DummyPlugin). */
    public const MISSING = 'missing';

    /** Installed but not enabled. */
    public const DISABLED = 'disabled';

    /**
     * Enabled, but Joomla does not load it for this request's user: its Access is a view level that user does not hold,
     * so Joomla cannot build it with its configuration (its provider reads the request's plugin list).
     */
    public const NOT_LOADED = 'not_loaded';

    /** Enabled, but its boot threw, or its import failed earlier in this request. */
    public const UNLOADABLE = 'unloadable';

    /** Booted, but not of the family its group requires. */
    public const WRONG_FAMILY = 'wrong_family';

    /** Booted, but it declares no targeted handler for the event or operation. */
    public const NO_HANDLER = 'no_handler';

    /** No Joomla application in this process. */
    public const NO_APPLICATION = 'no_application';

    /**
     * @param string $reason One of the constants.
     * @param PluginId|null $plugin The plugin asked for; null for NO_APPLICATION.
     * @param string $message What happened, in English, for the log.
     * @param Throwable|null $previous What a failing boot threw.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?PluginId $plugin,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
