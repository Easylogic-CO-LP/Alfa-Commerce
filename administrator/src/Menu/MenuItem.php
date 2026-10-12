<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Menu;

\defined('_JEXEC') or die;

/**
 * One entry in the Alfa admin menu: DECLARED fluently, rendered by us.
 *
 * The menu is ours end to end: com_alfa declares its own entries in menu.xml and any
 * alfa* plugin adds more on the {@see \Alfa\Component\Alfa\Administrator\Event\Menu\BuildMenuEvent},
 * with the SAME builder either way. An item says where it points, how it reads, what
 * icon paints it (a real inline SVG, coloured by currentColor, not a CSS background),
 * where it sits (section + ordering), who may see it (an ACL action) and, for a panel,
 * which of its own tasks only read (the only ones the panel accepts by GET). It never draws
 * itself; the menu layout does, so look and behaviour stay in one place.
 *
 * @since  1.7.0
 */
final class MenuItem
{
    /** @var string Unique id within the menu. */
    public string $id;

    /** @var string Optional grouping heading (a language key); '' renders ungrouped. */
    public string $section = '';

    /** @var string Navigation label (a language key). */
    public string $title = '';

    /** @var string The URL this item points to. */
    public string $link = '';

    /** @var array<string, string> Request params that, when all present, mark this item active. */
    public array $activeWhen = [];

    /** @var string Icon path (a URL relative to the site root, e.g. media/com_alfa/images/admin/menu/orders.svg). */
    public string $icon = '';

    /** @var int Sort order within its section (lower first). */
    public int $ordering = 0;

    /** @var string|null ACL action gating visibility; null = visible to anyone in the component. */
    public ?string $accessAction = null;

    /** @var string ACL asset the action is checked against. */
    public string $accessAsset = 'com_alfa';

    /** @var string[] The panel's own tasks that only read; the only ones it accepts by GET. */
    public array $lookups = [];

    /** @var bool Computed by the builder: is this the current screen. */
    public bool $active = false;

    /**
     * @param string $id Unique id within the menu.
     *
     * @since  1.7.0
     */
    public function __construct(string $id)
    {
        $this->id = $id;
    }

    /**
     * @param string $section Grouping heading (language key).
     *
     * @since  1.7.0
     */
    public function section(string $section): static
    {
        $this->section = $section;

        return $this;
    }

    /**
     * @param string $title Navigation label (language key).
     *
     * @since  1.7.0
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Point at a core com_alfa view (e.g. 'items', 'orders').
     *
     * @param string $view The component view.
     *
     * @since  1.7.0
     */
    public function view(string $view): static
    {
        $this->link = 'index.php?option=com_alfa&view=' . $view;
        $this->activeWhen = ['option' => 'com_alfa', 'view' => $view];

        return $this;
    }

    /**
     * Point at a plugin PANEL (the shared MVC), identified by group + element + panel.
     *
     * @param string $group Owning plugin group.
     * @param string $element Owning plugin element.
     * @param string $panel Screen id within the plugin.
     *
     * @since  1.7.0
     */
    public function panel(string $group, string $element, string $panel): static
    {
        $this->link = 'index.php?option=com_alfa&view=panel'
            . '&group=' . rawurlencode($group)
            . '&element=' . rawurlencode($element)
            . '&panel=' . rawurlencode($panel);
        $this->activeWhen = [
            'option' => 'com_alfa',
            'view' => 'panel',
            'group' => $group,
            'element' => $element,
            'panel' => $panel,
        ];

        return $this;
    }

    /**
     * Point at an arbitrary URL, with the params that mark it active.
     *
     * @param string $url The target URL.
     * @param array<string, string> $activeWhen Params that, when all match, mark it active.
     *
     * @since  1.7.0
     */
    public function link(string $url, array $activeWhen = []): static
    {
        $this->link = $url;
        $this->activeWhen = $activeWhen;

        return $this;
    }

    /**
     * The icon, ALWAYS a path (a URL relative to the site root). The renderer paints it as
     * a CSS mask coloured by currentColor, so a single monochrome SVG matches the sidebar
     * theme. A plugin ships its own glyph and points here; com_alfa's own icons live under
     * media/com_alfa/images/admin/menu/.
     *
     * @param string $path Icon path, e.g. 'media/com_alfa/images/admin/menu/orders.svg'.
     *
     * @since  1.7.0
     */
    public function icon(string $path): static
    {
        $this->icon = $path;

        return $this;
    }

    /**
     * @param int $ordering Sort order within the section.
     *
     * @since  1.7.0
     */
    public function ordering(int $ordering): static
    {
        $this->ordering = $ordering;

        return $this;
    }

    /**
     * Gate visibility behind an ACL action, checked via $user->authorise().
     *
     * @param string $action The ACL action (e.g. 'alfa.orders.view', 'alfa.search.manage').
     * @param string $asset The asset to check it against.
     *
     * @since  1.7.0
     */
    public function access(string $action, string $asset = 'com_alfa'): static
    {
        $this->accessAction = $action;
        $this->accessAsset = $asset;

        return $this;
    }

    /**
     * Declare which of the panel's own tasks only read: a picker's search, a fragment a
     * screen fetches, an export. These may arrive by GET, still carrying the form token.
     * Every other task of the panel changes data and is accepted from a POST alone.
     *
     * @param string ...$tasks The bare task verbs, as the panel's task handler names them.
     *
     * @since  1.14.0
     */
    public function lookups(string ...$tasks): static
    {
        $this->lookups = array_values(array_unique($tasks));

        return $this;
    }

    /**
     * Is this item complete enough to render (an id, a label and a link).
     *
     * @since  1.7.0
     */
    public function isValid(): bool
    {
        return $this->id !== '' && $this->title !== '' && $this->link !== '';
    }
}
