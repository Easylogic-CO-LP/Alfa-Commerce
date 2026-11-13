<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Menu;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Menu\MenuItem;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractEvent;

/**
 * The Alfa admin menu, open for additions: fired at every alfa* plugin (onAlfaBuildMenu).
 *
 * The core entries and headings come from menu.xml and enter through the event's constructor,
 * before any plugin is asked (the coreItems and coreSections arguments, MenuBuilder::collect());
 * a plugin adds its own, under its identity, the same fluent way it would anywhere else in this
 * component:
 *
 *   $event->item('alfa-search.enterprise', 'synonyms')
 *         ->section('COM_ALFA_MENU_SEARCH')
 *         ->title('PLG_ALFA_SEARCH_ENTERPRISE_SYNONYMS_TITLE')
 *         ->panel('alfa-search', 'enterprise', 'synonyms')
 *         ->icon('tags')
 *         ->ordering(10)
 *         ->access('alfa.search.manage');
 *
 * Ordering is data, so a plugin can place its item first, second, wherever. The builder
 * filters by ACL and sorts; the plugin only declares.
 *
 * A PLUGIN WRITES ONLY ITS OWN ENTRIES (2.2.1). item() hands the plugin the entry it
 * just added; all() hands copies, so a listener that reads the menu and changes what it
 * read changes nothing: core's entries and another plugin's stay as they declared them.
 *
 * A SECTION IS PLACED THE SAME WAY. A plugin that owns a whole heading says where
 * it belongs among the core ones with {@see self::section()}, under its identity: an analytics heading
 * read daily sits above the once-configured Market, not below it merely because a
 * plugin added it. A heading nobody places renders after every placed one.
 *
 * @since  2.0.0
 */
class BuildMenuEvent extends AbstractEvent
{
    /**
     * Items added so far.
     *
     * @var MenuItem[]
     * @since  2.0.0
     */
    protected array $items = [];

    /**
     * Where each placed section heading sorts, keyed by its language key.
     *
     * @var array<string, int>
     * @since  2.0.0
     */
    protected array $sections = [];

    /**
     * The event, holding the component's own entries and headings (the coreItems argument: MenuItem[], and the
     * coreSections argument: [language key => ordering], from menu.xml) before any plugin is asked; every later entry
     * and heading comes through item(), add() or section(), under its contributor.
     *
     * @param string $name The event name.
     * @param array $arguments The arguments; coreItems and coreSections are taken here and not kept as arguments.
     *
     * @throws InvalidArgumentException When a core entry is not a MenuItem.
     *
     * @since  2.2.0
     */
    public function __construct(string $name, array $arguments = [])
    {
        $coreItems = $arguments['coreItems'] ?? [];
        $coreSections = $arguments['coreSections'] ?? [];
        unset($arguments['coreItems'], $arguments['coreSections']);

        parent::__construct($name, $arguments);

        foreach ($coreItems as $item) {
            if (!$item instanceof MenuItem) {
                throw new InvalidArgumentException('A core menu entry is a MenuItem.');
            }

            $this->items[] = $item;
        }

        foreach ($coreSections as $section => $ordering) {
            $this->placeSection((string) $section, (int) $ordering);
        }
    }

    /**
     * Add a new item and return it for fluent configuration.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Unique id within the menu.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function item(string $contributor, string $id): MenuItem
    {
        PluginId::of($contributor);
        $item = new MenuItem($id);
        $this->items[] = $item;

        return $item;
    }

    /**
     * Add a pre-built item.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param MenuItem $item The item.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function add(string $contributor, MenuItem $item): static
    {
        PluginId::of($contributor);
        $this->items[] = $item;

        return $this;
    }

    /**
     * Place a section heading among the others.
     *
     * First declaration wins: core places its own headings before any plugin is
     * asked, so a plugin can position the heading it owns but not move a core one.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $section The heading's language key, as items name it.
     * @param int $ordering Sort position among headings; core uses 100..900.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function section(string $contributor, string $section, int $ordering): static
    {
        PluginId::of($contributor);
        $this->placeSection($section, $ordering);

        return $this;
    }

    /**
     * The first declaration of a heading wins.
     *
     * @param string $section The heading's language key.
     * @param int $ordering Its sort position.
     *
     * @since  2.2.0
     */
    private function placeSection(string $section, int $ordering): void
    {
        if ($section !== '' && !isset($this->sections[$section])) {
            $this->sections[$section] = $ordering;
        }
    }

    /**
     * Every placed heading, keyed by language key.
     *
     * @return array<string, int>
     *
     * @since  2.0.0
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * A copy of every valid item added so far (2.2.1: copies, so changing one changes nothing in the menu).
     *
     * @return MenuItem[]
     *
     * @since  2.0.0
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (MenuItem $item): MenuItem => clone $item,
            array_filter(
                $this->items,
                static fn (MenuItem $item): bool => $item->isValid(),
            ),
        ));
    }
}
