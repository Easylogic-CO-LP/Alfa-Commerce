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

use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionInterface;
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
 *         $event->addProjection(new ExampleProjection(), 'plg_alfa-search_example');
 *     }
 *
 * A projection that materialises VISIBILITY needs one more thing, because a
 * visibility change has a blast radius an item save does not: it must also listen to
 * {@see VisibilityChangedEvent}, since nothing else will tell it that an allowlist
 * moved underneath the rows it stores.
 *
 * @since  2.0.0
 */
class CollectCatalogProjectionsEvent extends AbstractEvent
{
    /**
     * Contribute a projection under its owning extension's identity.
     *
     * @param ProjectionInterface $projection The projection instance.
     * @param string $element Owning extension element (e.g. 'plg_alfa-search_example').
     * @param string $version The package version.
     *
     *
     * @since  2.0.0
     */
    public function addProjection(ProjectionInterface $projection, string $element, string $version = ''): void
    {
        $entries = (array) ($this->arguments['projections'] ?? []);

        $entries[] = [
            'projection' => $projection,
            'element' => trim($element),
            'version' => trim($version),
        ];

        $this->arguments['projections'] = $entries;
    }

    /**
     * The collected projection entries.
     *
     * @return array<int, array{projection: ProjectionInterface, element: string, version: string}>
     *
     * @since  2.0.0
     */
    public function getProjections(): array
    {
        return (array) ($this->arguments['projections'] ?? []);
    }
}
