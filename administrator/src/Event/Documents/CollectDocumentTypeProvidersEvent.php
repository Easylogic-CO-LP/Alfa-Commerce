<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Documents;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Provider\DocumentTypeProviderInterface;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onCollectDocumentTypeProviders: the typed collection event of the
 * provider discovery. Package plugins contribute their providers through
 * addProvider(), under their extension identity:
 *
 *     public function onCollectDocumentTypeProviders(
 *         CollectDocumentTypeProvidersEvent $event
 *     ): void {
 *         $event->addProvider(new MyDocumentProvider(), 'plg_alfa_mypack', '1.0.0');
 *     }
 *
 * The typed signature IS the developer contract: no argument-array
 * shapes to remember, and a wrong provider type fails loudly at the
 * plugin, not silently at the sync. This event is the template for the
 * wider provider family (stock, tax, export ... collections follow the
 * same shape).
 *
 * @since  1.3.0
 */
class CollectDocumentTypeProvidersEvent extends AbstractEvent
{
    /**
     * Contribute a provider under its owning extension's identity.
     *
     * @param DocumentTypeProviderInterface $provider The provider instance.
     * @param string $element Owning extension element
     *                        (e.g. 'plg_alfa_mypack').
     * @param string $version The package version.
     *
     *
     * @since  1.3.0
     */
    public function addProvider(DocumentTypeProviderInterface $provider, string $element, string $version = ''): void
    {
        $entries = (array) ($this->arguments['providers'] ?? []);

        $entries[] = [
            'provider' => $provider,
            'element' => trim($element),
            'version' => trim($version),
        ];

        $this->arguments['providers'] = $entries;
    }

    /**
     * The collected provider entries.
     *
     * @return array<int, array{provider: DocumentTypeProviderInterface, element: string, version: string}>
     *
     * @since  1.3.0
     */
    public function getProviders(): array
    {
        return (array) ($this->arguments['providers'] ?? []);
    }
}
