<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

\defined('_JEXEC') or die;

/**
 * What core knows of a rendered result set before the provider runs (2.2.0): the order it is shown in and the global
 * position of its first row, minus one. Carried on the SearchQuery of the one read that backs a rendered result set (the
 * request-driven listing on every page, the search endpoint's intent=search read), never on a facet re-read, a
 * type-ahead lookup or a programmatic read: a query without it backs no rendered result set and gets no handle.
 *
 * @since  2.2.0
 */
final class ResultSetContext
{
    /**
     * The order shown when relevance applies.
     *
     * @since  2.2.0
     */
    public const RELEVANCE = 'relevance';

    /**
     * @param string $order `relevance`, or `sort:<key>:<direction>` (direction asc or desc, the key from core's closed
     *                      map of the sortable columns): never a raw ordering string.
     * @param int $offset The global position of the first row, minus one (the page's start).
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly string $order = self::RELEVANCE,
        public readonly int $offset = 0,
    ) {
    }

    /**
     * The context as a memo key part.
     *
     * @since  2.2.0
     */
    public function key(): string
    {
        return $this->order . '@' . $this->offset;
    }
}
