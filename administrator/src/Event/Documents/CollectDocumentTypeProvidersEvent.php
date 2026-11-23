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
 * addProvider(), naming themselves as group.element:
 *
 *     public function onCollectDocumentTypeProviders(
 *         CollectDocumentTypeProvidersEvent $event
 *     ): void {
 *         $event->addProvider('alfa.mypack', new MyDocumentProvider(), '1.0.0')
 *             ->addProvider('alfa.mypack', new MyCreditProvider(), '1.0.0');
 *     }
 *
 * The registry keeps a provider's types under Joomla's name for the plugin,
 * plg_<group>_<element> (plg_alfa_mypack), so the types a
 * provider registered earlier stay its own.
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
     * Every contribution names its plugin first, then what it contributes: the four registries (addProjection(),
     * addEngine(), and addProvider() of the document types and of the stock allocation) as the contributions that build
     * an entry (item(), column(), action() and the others). A registry answers the event, so a plugin's contributions
     * chain in one statement.
     *
     * @param string $contributor The contributing plugin, group.element (in place of the extension name; attribution,
     *                            never an authorisation).
     * @param DocumentTypeProviderInterface $provider The provider instance.
     * @param string $version The package version.
     *
     * @return static The event, for the next contribution.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function addProvider(string $contributor, DocumentTypeProviderInterface $provider, string $version = ''): static
    {
        $owner = PluginId::of($contributor);

        $this->providers[] = [
            'provider' => $provider,
            // Joomla's name for the plugin: the provider identity its types are kept under.
            'element' => 'plg_' . $owner->group . '_' . $owner->element,
            'contributor' => $owner->key(),
            'version' => trim($version),
        ];

        return $this;
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
