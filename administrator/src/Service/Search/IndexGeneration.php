<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * An opaque handle to one writable generation of an index.
 *
 * INVARIANT PROTECTED: core can never construct, parse or guess an engine's index
 * name. A generation is whatever the engine says it is: an index behind an
 * alias, a staged index awaiting an atomic swap, a table suffix, a
 * column value. Core only ever passes back the token it was handed. The moment
 * core builds "products_v2" itself, it has learned an engine's naming scheme and
 * the abstraction is over.
 *
 * The handle is the reason a rebuild can be atomic: write into a fresh
 * generation, activate it in one step, drop the old one. An engine without that
 * ability returns the serving generation instead, and the rebuild runner degrades to
 * writing in place.
 *
 * @since  1.5.0
 */
final class IndexGeneration
{
    /**
     * @param string $handle Engine-issued token. Opaque to core; never parsed.
     * @param bool $active Whether this generation is the one currently serving reads.
     *
     * @since  1.5.0
     */
    public function __construct(
        public readonly string $handle,
        public readonly bool $active = false,
    ) {
    }

    /**
     * @return string The opaque handle.
     *
     * @since  1.5.0
     */
    public function __toString(): string
    {
        return $this->handle;
    }
}
