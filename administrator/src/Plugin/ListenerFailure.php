<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Throwable;

\defined('_JEXEC') or die;

/**
 * One listener of an isolated delivery that threw (2.2.0, design 2.15.3): named as the helper names listeners
 * (Class::method, an invokable's class, a function name, else closure), or the plugin's identity in a selection loop.
 *
 * @since  2.2.0
 */
final class ListenerFailure
{
    /**
     * @param string $listener The listener's name.
     * @param Throwable $error What it threw.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly string $listener,
        public readonly Throwable $error,
    ) {
    }
}
