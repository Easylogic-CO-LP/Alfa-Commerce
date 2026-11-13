<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionInterface;
use BadMethodCallException;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractEvent;

/**
 * onCollectCatalogProjections: the registration point for derived catalogue data.
 *
 * INVARIANT PROTECTED: nothing derived from the catalogue has to hook the models to
 * stay current. Before this, the projection list was hardcoded in
 * {@see \Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager}, so
 * a search or analytics package had exactly one option: subscribe to save
 * events itself, write its own queries to work out what changed, and encode its own
 * assumptions about the catalogue. The second package would encode different ones.
 *
 * A projection registered here is called by the SAME manager, on the SAME triggers,
 * with the SAME ids as the built-in price, item-field and search projections, which
 * is the whole point: one write path, one definition of "this item changed".
 *
 *     public function onCollectCatalogProjections(CollectCatalogProjectionsEvent $event): void
 *     {
 *         $event->addProjection(new ExampleProjection(), 'alfa-search.example');
 *     }
 *
 * A projection that materialises VISIBILITY needs one more thing, because a
 * visibility change has a blast radius an item save does not: it must also listen to
 * {@see VisibilityChangedEvent}, since nothing else will tell it that an allowlist
 * moved underneath the rows it stores.
 *
 * THE LIST IS WRITTEN ONLY THROUGH addProjection() (2.2.1). It is the event's own, not an
 * argument: setting the projections argument (setArgument(), or $event['projections'])
 * is refused with a BadMethodCallException, so no listener replaces or empties what the
 * others registered.
 *
 * @since  2.0.0
 */
class CollectCatalogProjectionsEvent extends AbstractEvent
{
    /**
     * The projections contributed so far, in contribution order.
     *
     * @var list<array{projection: ProjectionInterface, contributor: string, version: string}>
     * @since  2.2.1
     */
    private array $entries = [];

    /**
     * Contribute a projection under its owning plugin's identity.
     *
     * A registry's entry names its plugin second, after the object it registers: the four registries (addProjection(),
     * addEngine(), and addProvider() of the document types and of the stock allocation) share that shape, while a
     * contribution that builds an entry (item(), column(), action() and the others) names its plugin first.
     *
     * @param ProjectionInterface $projection The projection instance.
     * @param string $contributor The owning plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $version The package version.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function addProjection(ProjectionInterface $projection, string $contributor, string $version = ''): void
    {
        $owner = PluginId::of($contributor);

        $this->entries[] = [
            'projection' => $projection,
            'contributor' => $owner->key(),
            'version' => trim($version),
        ];
    }

    /**
     * The collected projection entries.
     *
     * @return list<array{projection: ProjectionInterface, contributor: string, version: string}>
     *
     * @since  2.0.0
     */
    public function getProjections(): array
    {
        return $this->entries;
    }

    /**
     * Refuses the projections argument: the list is written only through addProjection() (2.2.1).
     *
     * @param mixed $value The value a caller tried to set.
     *
     * @throws BadMethodCallException Always.
     *
     * @since  2.2.1
     */
    protected function onSetProjections(mixed $value): never
    {
        throw new BadMethodCallException(
            'The projections of onCollectCatalogProjections are added through addProjection(), each under its plugin;'
            . ' the list itself cannot be set.',
        );
    }
}
