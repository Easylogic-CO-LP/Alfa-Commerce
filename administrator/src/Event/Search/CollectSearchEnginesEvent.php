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

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineInterface;
use InvalidArgumentException;
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
 *         $event->addEngine(new ExampleEngine(), 'alfa-search.example', '1.0.0');
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
     * The engines contributed so far; written only through addEngine().
     *
     * @var array<int, array{engine: SearchEngineInterface, element: string, version: string}>
     *
     * @since  2.2.0
     */
    private array $engines = [];

    /**
     * Contribute an engine, owned by the plugin that names itself.
     *
     * A registry's entry names its plugin second, after the object it registers: the four registries (addProjection(),
     * addEngine(), and addProvider() of the document types and of the stock allocation) share that shape, while a
     * contribution that builds an entry (item(), column(), action() and the others) names its plugin first.
     *
     * @param SearchEngineInterface $engine The engine instance.
     * @param string $contributor The contributing plugin, group.element (2.2.0; in place of the extension name; attribution,
     *                            never an authorisation). The configured engine's plugin is the one asked for the search
     *                            input when it is an alfa-search plugin.
     * @param string $version The package version.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function addEngine(SearchEngineInterface $engine, string $contributor, string $version = ''): void
    {
        $this->engines[] = [
            'engine' => $engine,
            'element' => PluginId::of($contributor)->key(),
            'version' => trim($version),
        ];
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
        return $this->engines;
    }
}
