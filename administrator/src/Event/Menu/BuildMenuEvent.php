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
use Joomla\CMS\Event\AbstractEvent;

/**
 * The Alfa admin menu, open for additions: fired at every alfa* plugin (onAlfaBuildMenu).
 *
 * The core entries come from menu.xml; a plugin adds its own the same fluent way it would
 * anywhere else in this component:
 *
 *   $event->item('synonyms')
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
 * A SECTION IS PLACED THE SAME WAY. A plugin that owns a whole heading says where
 * it belongs among the core ones with {@see self::section()}: an analytics heading
 * read daily sits above the once-configured Market, not below it merely because a
 * plugin added it. A heading nobody places renders after every placed one.
 *
 * @since  1.7.0
 */
class BuildMenuEvent extends AbstractEvent
{
    /**
     * Items added so far.
     *
     * @var MenuItem[]
     * @since  1.7.0
     */
    protected array $items = [];

    /**
     * Where each placed section heading sorts, keyed by its language key.
     *
     * @var array<string, int>
     * @since  1.10.0
     */
    protected array $sections = [];

    /**
     * Add a new item and return it for fluent configuration.
     *
     * @param string $id Unique id within the menu.
     *
     *
     * @since  1.7.0
     */
    public function item(string $id): MenuItem
    {
        $item = new MenuItem($id);
        $this->items[] = $item;

        return $item;
    }

    /**
     * Add a pre-built item.
     *
     * @param MenuItem $item The item.
     *
     *
     * @since  1.7.0
     */
    public function add(MenuItem $item): static
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Place a section heading among the others.
     *
     * First declaration wins: core places its own headings before any plugin is
     * asked, so a plugin can position the heading it owns but not move a core one.
     *
     * @param string $section The heading's language key, as items name it.
     * @param int $ordering Sort position among headings; core uses 100..900.
     *
     *
     * @since  1.10.0
     */
    public function section(string $section, int $ordering): static
    {
        if ($section !== '' && !isset($this->sections[$section])) {
            $this->sections[$section] = $ordering;
        }

        return $this;
    }

    /**
     * Every placed heading, keyed by language key.
     *
     * @return array<string, int>
     *
     * @since  1.10.0
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * Every valid item added so far.
     *
     * @return MenuItem[]
     *
     * @since  1.7.0
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (MenuItem $item): bool => $item->isValid(),
        ));
    }
}
