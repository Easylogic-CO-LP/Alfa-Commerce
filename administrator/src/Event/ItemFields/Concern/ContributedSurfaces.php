<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceBuilder;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;
use Joomla\CMS\Log\Log;

\defined('_JEXEC') or die;

/**
 * The surfaces several plugins contribute to ONE display event (2.2.0, design 2.15.4): each contribution names its
 * plugin, which defaults the surface's layouts to that plugin's tmpl folder. In a selection loop the event knows the
 * plugin answering (SelectionContributorsInterface) and drops, with a log line, a contribution naming another plugin.
 * Attribution, never an authorisation.
 *
 * @since  2.2.0
 */
trait ContributedSurfaces
{
    /**
     * @var list<array{0: PluginId, 1: string, 2: SurfaceBuilder}> Contributor, region, builder, in contribution order.
     * @since  2.2.0
     */
    private array $contributedSurfaces = [];

    /**
     * The plugin a selection loop is calling now; null outside one.
     *
     * @since  2.2.0
     */
    private ?PluginId $answeringPlugin = null;

    /**
     * Start one contributor's surface in a region.
     *
     * @param string $contributor The contributing plugin, group.element.
     * @param string $region The region key (a surface enum case's value).
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.2.0
     */
    protected function contributeSurface(string $contributor, string $region): SurfaceBuilder
    {
        $owner = PluginId::of($contributor);
        $builder = new SurfaceBuilder();

        if ($this->answeringPlugin !== null && $this->answeringPlugin->key() !== $owner->key()) {
            Log::add('Plugins: ' . $this->getName() . ': ' . $this->answeringPlugin . ' contributed a surface in the name of ' . $owner . '; dropped.', Log::WARNING, 'com_alfa.plugins');

            return $builder;
        }

        $this->contributedSurfaces[] = [$owner, $region, $builder];

        return $builder;
    }

    /**
     * The plugin whose handler runs now in a selection loop; null once it returned.
     *
     * @internal The plugin helper calls it around each handler of a selection loop; a plugin never calls it.
     *
     * @param PluginId|null $plugin The plugin.
     *
     * @since  2.2.0
     */
    public function answering(?PluginId $plugin): void
    {
        $this->answeringPlugin = $plugin;
    }

    /**
     * Append every contribution that set a layout onto a slot map, in contribution order, each owner defaulted to its
     * contributor.
     *
     * @param array $target The slot map, by reference (slot => list of descriptors).
     *
     * @since  2.2.0
     */
    public function harvestInto(array &$target): void
    {
        foreach ($this->contributedSurfaces as [$owner, $region, $builder]) {
            if (!$builder->hasLayout()) {
                continue;
            }

            $descriptor = $builder->toDescriptor();
            $descriptor['layoutPluginType'] = $descriptor['layoutPluginType'] ?: $owner->group;
            $descriptor['layoutPluginName'] = $descriptor['layoutPluginName'] ?: $owner->element;
            $descriptor['showon'] = '';
            $descriptor['fieldId'] = null;
            $descriptor['fieldName'] = null;
            $target[$region][] = $descriptor;
        }
    }
}
