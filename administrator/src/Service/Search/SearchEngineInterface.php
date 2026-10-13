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
 * One complete search implementation: its identity, its two halves, and what it
 * can do.
 *
 * INVARIANT PROTECTED: a reader and a writer can never be paired by accident. If
 * core collected providers and indexers separately, nothing would stop it querying
 * through an external engine while indexing into MySQL: two halves that each work
 * perfectly and together return nothing. An engine is the unit of installation and
 * the unit of configuration, so it must also be the unit of registration.
 *
 *     alfa.indexed    -> LIKE provider        + MySQL indexer
 *     alfa.enterprise -> inverted index       + its own indexer
 *     vendor.example  -> ExampleProvider      + ExampleIndexer
 *
 * Core knows only this interface. It never learns an engine's name to decide
 * behaviour: it asks whether the engine is READY and, if it is not, serves the
 * catalogue from the live product tables. The day core contains
 * `if ($engine->key() === 'vendor.example')`, engines have stopped being pluggable.
 *
 * ## THE VISIBILITY INVARIANT: binding on every engine, forever
 *
 * **Visibility semantics are owned by the catalogue. An engine may MATERIALISE
 * visibility; it must never REDEFINE it.**
 *
 * {@see \Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy} decides
 * who may see what. An engine receives that decision as data (the principals on the
 * {@see SearchQuery}) and may translate it into
 * whatever its technology expresses best: a SQL predicate, a filter expression,
 * a bitset. Translating is expected. Deciding is not.
 *
 * Concretely, an adapter must never contain anything of this shape:
 *
 *     if ($document['customer_group'] === 8) { ... }        // a second ACL engine
 *     if (!$user->isB2B()) { $filter[] = 'public'; }       // a second ACL engine
 *
 * The failure mode is slow and expensive: a second rule appears, agrees with the
 * first for a year, and then quietly disagrees: a product listed but unsearchable,
 * or findable but not listed. If an engine needs a distinction the catalogue cannot
 * express, the distinction belongs in the catalogue policy, where every reader gets
 * it at once.
 *
 * An engine that materialises visibility holds a SNAPSHOT of it, and therefore owns
 * the job of refreshing that snapshot when the catalogue's rules change; core cannot
 * know it needs to. The Indexed SQL engine sidesteps this entirely by storing no
 * visibility at all: it returns candidate ids that the catalogue query filters with
 * the live policy, which is why an allowlist edit there costs no reindex.
 *
 * @since  2.0.0
 */
interface SearchEngineInterface
{
    /**
     * Stable identifier, namespaced by vendor: "alfa.indexed", "vendor.example".
     *
     * Used for configuration, logging and ordering, never for branching on
     * behaviour.
     *
     *
     * @since  2.0.0
     */
    public function key(): string;

    /**
     * Human-readable name for the administrator's engine picker.
     *
     *
     * @since  2.0.0
     */
    public function label(): string;

    /**
     * The read half.
     *
     *
     * @since  2.0.0
     */
    public function provider(): SearchProviderInterface;

    /**
     * The write half.
     *
     *
     * @since  2.0.0
     */
    public function indexer(): SearchIndexerInterface;

    /**
     * Whether the engine is reachable and warm enough to serve.
     *
     * An external service can be installed and configured yet unreachable; a local
     * index can exist yet be empty. Core uses this to fall back rather than to
     * serve nothing: the failure mode of a search engine must be degraded results,
     * never an empty catalogue.
     *
     *
     * @since  2.0.0
     */
    public function isReady(): bool;
}
