<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * One entity's ranked hits within a catalogue search result.
 *
 * A SECTION IS TYPED ONCE AND ITS HITS MUST AGREE: the constructor enforces it, so a
 * products section carrying a category hit is unrepresentable rather than a rendering
 * surprise. The duplication (entity on the section AND on each hit) is deliberate:
 * hits travel alone into surfaces that never see their section.
 *
 * @since  1.9.0
 */
final class CatalogSearchSection
{
    /**
     * @param SearchEntity $entity Whose rows this section holds.
     * @param CatalogSearchHit[] $hits The hits, ranked as the search ordered them.
     *
     * @throws InvalidArgumentException When a hit is not a CatalogSearchHit or carries
     *                                  a different entity.
     *
     * @since  1.9.0
     */
    public function __construct(
        public readonly SearchEntity $entity,
        public readonly array $hits = [],
    ) {
        foreach ($this->hits as $hit) {
            if (!$hit instanceof CatalogSearchHit) {
                throw new InvalidArgumentException('Sections hold CatalogSearchHit instances only.');
            }

            if ($hit->entity !== $this->entity) {
                throw new InvalidArgumentException(\sprintf(
                    'A %s section cannot hold a %s hit.',
                    $this->entity->value,
                    $hit->entity->value,
                ));
            }
        }
    }

    /**
     * Whether the section holds any hits.
     *
     *
     * @since  1.9.0
     */
    public function isEmpty(): bool
    {
        return $this->hits === [];
    }
}
