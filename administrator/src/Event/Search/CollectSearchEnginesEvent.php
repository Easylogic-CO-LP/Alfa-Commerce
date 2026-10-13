<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Search;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineInterface;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onCollectSearchEngines: the typed collection event of search engine discovery.
 *
 * INVARIANT PROTECTED: a reader and a writer can never be registered separately.
 * The contributed unit is a whole {@see SearchEngineInterface}, never a provider and
 * never an indexer. A registry that collected `providers[]` and `indexers[]` would
 * reintroduce, at discovery time, exactly the mispairing the engine interface exists
 * to make impossible: querying through one engine while indexing into another.
 *
 *     public function onCollectSearchEngines(CollectSearchEnginesEvent $event): void
 *     {
 *         $event->addEngine(new ExampleEngine(), 'plg_alfa-search_example', '1.0.0');
 *     }
 *
 * The typed signature IS the developer contract: a wrong type fails loudly at the
 * plugin rather than silently at selection time.
 *
 * @since  2.0.0
 */
class CollectSearchEnginesEvent extends AbstractEvent
{
    /**
     * Contribute an engine under its owning extension's identity.
     *
     * @param SearchEngineInterface $engine The engine instance.
     * @param string $element Owning extension element (e.g. 'plg_alfa-search_example').
     * @param string $version The package version.
     *
     *
     * @since  2.0.0
     */
    public function addEngine(SearchEngineInterface $engine, string $element, string $version = ''): void
    {
        $entries = (array) ($this->arguments['engines'] ?? []);

        $entries[] = [
            'engine' => $engine,
            'element' => trim($element),
            'version' => trim($version),
        ];

        $this->arguments['engines'] = $entries;
    }

    /**
     * The collected engine entries.
     *
     * @return array<int, array{engine: SearchEngineInterface, element: string, version: string}>
     *
     * @since  2.0.0
     */
    public function getEngines(): array
    {
        return (array) ($this->arguments['engines'] ?? []);
    }
}
