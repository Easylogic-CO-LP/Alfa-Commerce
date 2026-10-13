<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Provider\StockAllocationProviderInterface;
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
 *         $event->addProvider(new NearestStoreAllocation(), 'plg_alfa_mypack', 100);
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
     * Contribute a provider under its owning extension's identity.
     *
     * @param StockAllocationProviderInterface $provider The provider instance.
     * @param string $element Owning extension element
     *                        (e.g. 'plg_alfa_mypack').
     * @param int $priority Chain position; higher asks first.
     *
     *
     * @since  2.0.0
     */
    public function addProvider(StockAllocationProviderInterface $provider, string $element, int $priority = 0): void
    {
        $entries = (array) ($this->arguments['providers'] ?? []);

        $entries[] = [
            'provider' => $provider,
            'element' => trim($element),
            'priority' => $priority,
        ];

        $this->arguments['providers'] = $entries;
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
        return (array) ($this->arguments['providers'] ?? []);
    }
}
