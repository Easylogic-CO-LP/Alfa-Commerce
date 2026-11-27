<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Provider\StockAllocationProviderInterface;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onCollectStockAllocationProviders: the typed collection event of the
 * allocation provider chain. Package plugins contribute providers under
 * their extension identity with a priority; the core asks the chain
 * highest-priority-first and the first non-null answer wins:
 *
 *     public function onCollectStockAllocationProviders(
 *         CollectStockAllocationProvidersEvent $event
 *     ): void {
 *         $event->addProvider('inventory.mypack', new NearestStoreAllocation(), 100);
 *     }
 *
 * Same shape as the document type provider collection: the typed
 * signature IS the developer contract.
 *
 * @since  2.0.0
 */
class CollectStockAllocationProvidersEvent extends AbstractEvent
{
    /**
     * The providers contributed so far; written only through addProvider().
     *
     * @var array<int, array{provider: StockAllocationProviderInterface, element: string, priority: int}>
     *
     * @since  2.2.0
     */
    private array $providers = [];

    /**
     * Contribute a provider, owned by the plugin that names itself.
     *
     * Every contribution names its plugin first, then what it contributes: the four registries (addProjection(),
     * addEngine(), and addProvider() of the document types and of the stock allocation) as the contributions that build
     * an entry (item(), column(), action() and the others). A registry answers the event, so a plugin's contributions
     * chain in one statement.
     *
     * @param string $contributor The contributing plugin, group.element (in place of the extension name; attribution,
     *                            never an authorisation).
     * @param StockAllocationProviderInterface $provider The provider instance.
     * @param int $priority Chain position; higher asks first.
     *
     * @return static The event, for the next contribution.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function addProvider(string $contributor, StockAllocationProviderInterface $provider, int $priority = 0): static
    {
        $this->providers[] = [
            'provider' => $provider,
            'element' => PluginId::of($contributor)->key(),
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * The collected provider entries.
     *
     * @return array<int, array{provider: StockAllocationProviderInterface, element: string, priority: int}>
     *
     * @since  2.0.0
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
