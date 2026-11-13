<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Documents;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Provider\DocumentTypeProviderInterface;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onCollectDocumentTypeProviders: the typed collection event of the
 * provider discovery. Package plugins contribute their providers through
 * addProvider(), naming themselves as group.element (2.2.0):
 *
 *     public function onCollectDocumentTypeProviders(
 *         CollectDocumentTypeProvidersEvent $event
 *     ): void {
 *         $event->addProvider(new MyDocumentProvider(), 'alfa.mypack', '1.0.0');
 *     }
 *
 * The registry keeps a provider's types under Joomla's name for the plugin,
 * plg_<group>_<element> (plg_alfa_mypack), as before 2.2.0, so the types a
 * provider registered then stay its own.
 *
 * The typed signature IS the developer contract: no argument-array
 * shapes to remember, and a wrong provider type fails loudly at the
 * plugin, not silently at the sync. This event is the template for the
 * wider provider family (stock, tax, export ... collections follow the
 * same shape).
 *
 * @since  2.0.0
 */
class CollectDocumentTypeProvidersEvent extends AbstractEvent
{
    /**
     * The providers contributed so far; written only through addProvider().
     *
     * @var array<int, array{provider: DocumentTypeProviderInterface, element: string, contributor: string, version: string}>
     *
     * @since  2.2.0
     */
    private array $providers = [];

    /**
     * Contribute a provider, owned by the plugin that names itself.
     *
     * A registry's entry names its plugin second, after the object it registers: the four registries (addProjection(),
     * addEngine(), and addProvider() of the document types and of the stock allocation) share that shape, while a
     * contribution that builds an entry (item(), column(), action() and the others) names its plugin first.
     *
     * @param DocumentTypeProviderInterface $provider The provider instance.
     * @param string $contributor The contributing plugin, group.element (2.2.0; in place of the extension name; attribution,
     *                            never an authorisation).
     * @param string $version The package version.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function addProvider(DocumentTypeProviderInterface $provider, string $contributor, string $version = ''): void
    {
        $owner = PluginId::of($contributor);

        $this->providers[] = [
            'provider' => $provider,
            // Joomla's name for the plugin: the provider identity its types are kept under.
            'element' => 'plg_' . $owner->group . '_' . $owner->element,
            'contributor' => $owner->key(),
            'version' => trim($version),
        ];
    }

    /**
     * The collected provider entries.
     *
     * @return array<int, array{provider: DocumentTypeProviderInterface, element: string, contributor: string, version: string}>
     *
     * @since  2.0.0
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
