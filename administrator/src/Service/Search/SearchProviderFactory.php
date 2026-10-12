<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Resolves which {@see SearchProviderInterface} the catalog reads through, so `ItemsModel`
 * depends on this seam rather than on a concrete provider.
 *
 * INVARIANT PROTECTED: reads and writes can never be served by different engines.
 * The provider is taken from the ACTIVE engine in the registry: the same engine
 * whose indexer {@see SearchWriteCoordinator} writes into. Constructing a provider
 * directly here, as this class used to, meant that selecting a new engine would route
 * writes to it while the storefront kept reading the SQL index that nothing
 * was filling any more: search would freeze at its last state, permanently, with no
 * error anywhere.
 *
 * Taking the provider FROM the engine also preserves a second invariant that only
 * the engine can guarantee: the Indexed SQL engine hands its provider and its indexer the
 * same generation ledger, so a rebuild activated mid-request cannot leave the reader
 * resolving one generation while the writer resolves another.
 *
 * WHEN NO ENGINE IS READY the storefront is served by {@see CatalogJoinProvider}
 * over the live tables. That is a first-class answer, not a degraded one: it is the
 * default every installation without a search plugin runs on, and it covers exactly
 * what the index covers. An engine that is missing, still building, or unreachable
 * therefore costs speed and nothing else.
 *
 * @since  1.3.0
 */
class SearchProviderFactory
{
    /** @var DatabaseInterface */
    private $db;

    /**
     * @since  1.5.0
     */
    private readonly SearchEngineRegistry $registry;

    /**
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     * @param SearchEngineRegistry|null $registry Injected for testing.
     *
     * @since  1.3.0
     */
    public function __construct(?DatabaseInterface $db = null, ?SearchEngineRegistry $registry = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
        $this->registry = $registry ?? new SearchEngineRegistry();
    }

    /**
     * The provider the catalog search should use right now.
     *
     * @since  1.3.0
     */
    public function getCatalogProvider(): SearchProviderInterface
    {
        // EITHER/OR, and that is only safe because of what changed underneath it.
        //
        // This used to UNION the engine with a name-only live-table provider, because
        // the index could be silently incomplete (a restore, a staging sync, a
        // migration, or an upgrade that predated it) and the engine would still
        // report itself ready over the gap. The union masked that, at the cost of
        // running SQL on every search even when a remote engine was answering, and of
        // hiding every one of those inconsistencies from anyone who might have fixed
        // them.
        //
        // Two things removed the need. Readiness became honest: an engine serving a
        // generation that holds nothing, or one built under a different declaration
        // of what is searchable, now answers false. And the fallback became complete:
        // {@see CatalogJoinProvider} reads the live tables and covers everything the
        // index covers, so choosing it costs recall nothing.
        //
        // An engine is therefore a pure SPEED decision. If it is ready it answers
        // alone; if it is not, the live tables answer alone. Never both, and never
        // nothing.
        $engine = $this->registry->active();

        $provider = $engine !== null && $engine->isReady()
            ? $engine->provider()
            : new CatalogJoinProvider($this->db);

        return new MemoizedSearchProvider($provider);
    }
}
