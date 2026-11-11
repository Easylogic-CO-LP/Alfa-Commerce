<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use RuntimeException;
use Throwable;

/**
 * A failure of the shop's own background work, as a language key and its arguments: stored so ({@see Tasks::failureLine()})
 * and said at display in the reader's language ({@see Tasks::errorText()}; 2.2.0, UX-09). Its message, for the log, is
 * the key and the arguments.
 *
 * @since  2.2.0
 */
final class TaskError extends RuntimeException
{
    /**
     * @param string $key A key of Tasks::ERROR_KEY_PREFIX.
     * @param list<int|string> $arguments Its arguments.
     * @param Throwable|null $previous What it was raised from.
     *
     * @since  2.2.0
     */
    public function __construct(public readonly string $key, public readonly array $arguments = [], ?Throwable $previous = null)
    {
        parent::__construct($key . ($arguments === [] ? '' : ' ' . json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, $previous);
    }
}
