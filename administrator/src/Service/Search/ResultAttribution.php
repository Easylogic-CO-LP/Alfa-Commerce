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
 * What a rendered result set carries after its read (2.2.0): the handle the provider minted (opaque, int or string, or
 * null), the order core showed, whether the handle belongs to this response alone (responseSpecific: the page must not
 * be shared through a cache), and the configured engine's key. Core carries the handle and renders it with the global
 * position and the order; it never interprets it. With no handle, core renders no attribution metadata at all.
 *
 * THE EXTENSION BOUNDARY, documented and not opened in 2.2.0: provider-specific metadata would attach here as a
 * namespaced map, filled through onAlfaResultSetRender and rendered by the same two view calls. At result-set level
 * only the configured engine that minted the handle could add; per result nobody but core. Each extension would get one
 * namespace from its element (data-alfa-ext-<element>-<key>, the key matching ^[a-z0-9]+(-[a-z0-9]+)*$), never class,
 * id, style, on* or a non-data attribute; scalars only, escaped by core's one helper; a write to a reserved core name
 * refused and logged; every value opaque, and anything a browser echoes back revalidated by the endpoint receiving it.
 *
 * @since  2.2.0
 */
final class ResultAttribution
{
    /**
     * @param int|string|null $handle The provider's handle for this rendered result set; null when none.
     * @param string $order The shown order (ResultSetContext::$order).
     * @param bool $responseSpecific Whether the handle belongs to this response or visitor alone.
     * @param string $engine The configured engine's key.
     *
     * @since  2.2.0
     */
    public function __construct(
        public readonly int|string|null $handle = null,
        public readonly string $order = ResultSetContext::RELEVANCE,
        public readonly bool $responseSpecific = false,
        public readonly string $engine = '',
    ) {
    }

    /**
     * Whether a handle was minted: null decides, never truthiness, so a handle 0 or "0" counts.
     *
     * @since  2.2.0
     */
    public function hasHandle(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Whether the response must not be shared through a page cache: a response-specific handle is present.
     *
     * @since  2.2.0
     */
    public function excludesSharing(): bool
    {
        return $this->handle !== null && $this->responseSpecific;
    }
}
