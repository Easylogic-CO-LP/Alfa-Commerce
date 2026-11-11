<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;
use Throwable;

/**
 * The one renderer of a result set's attribution metadata (2.2.0): the closed set of core-owned names, each value
 * escaped once, null rendering nothing. Templates never concatenate or escape these attributes themselves: the items
 * view's resultSetAttributes() and resultAttributes() call this, and those two calls are the listing's supported
 * override contract.
 *
 * THE CLOSED SET. Three names, nothing else: the attribution handle and the shown order on the results container, the
 * global position on each result. Any other name is refused and logged, never rendered.
 *
 * @since  2.2.0
 */
final class ResultAttributes
{
    /**
     * The attribution handle, on the results container (the name 2.1 used, kept).
     *
     * @since  2.2.0
     */
    public const HANDLE = 'data-alfa-search-event';

    /**
     * The shown order, on the results container: `relevance` or `sort:<key>:<direction>`.
     *
     * @since  2.2.0
     */
    public const ORDER = 'data-alfa-search-order';

    /**
     * The global position of a result, from 1, on each result.
     *
     * @since  2.2.0
     */
    public const POSITION = 'data-position';

    /**
     * The names this renderer knows.
     *
     * @since  2.2.0
     */
    private const NAMES = [self::HANDLE, self::ORDER, self::POSITION];

    /**
     * Render name => value pairs as attributes, each preceded by one space: a null value renders nothing (null, not
     * truthiness, decides: a handle 0 renders); a value is escaped once (htmlspecialchars, quotes, UTF-8) and never
     * cast; a name outside the closed set is refused and logged.
     *
     * @param array<string, int|string|null> $values The attributes.
     *
     * @return string The attributes, or '' for none.
     *
     * @since  2.2.0
     */
    public static function render(array $values): string
    {
        $html = '';

        foreach ($values as $name => $value) {
            if (!\in_array($name, self::NAMES, true)) {
                try {
                    Log::add('Result attributes: the name "' . $name . '" is not a core attribution name and was not rendered.', Log::WARNING, 'com_alfa.search');
                } catch (Throwable) {
                    // A log that cannot be written must not break the listing.
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $html;
    }
}
