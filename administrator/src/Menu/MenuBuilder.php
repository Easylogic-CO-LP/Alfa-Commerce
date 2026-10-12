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

use Alfa\Component\Alfa\Administrator\Event\Menu\BuildMenuEvent;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Joomla\CMS\User\User;
use Joomla\Input\Input;
use SimpleXMLElement;
use Throwable;

/**
 * Assembles the Alfa admin menu we render ourselves.
 *
 * Three steps, one order every request:
 *
 *  1. Read the core entries from menu.xml (com_alfa's own screens): declarative, so
 *     reordering or translating them never touches code.
 *  2. Broadcast {@see BuildMenuEvent} to the `alfa` PLATFORM group (only; capability
 *     groups stay lazy) so each platform plugin adds its own entries the same fluent way,
 *     placing them by ordering wherever it likes.
 *
 *     COST MODEL, so nobody "optimises" this into a bug: the group is already imported by
 *     AlfaComponent::boot() before any view runs, the built menu is cached per request
 *     (self::$cache), and menu handlers contribute plain descriptors. One broadcast per
 *     request over already-booted plugins is the floor; caching across requests would have
 *     to invalidate on ACL, language and plugin-state changes: a bug factory for
 *     microseconds.
 *  3. Drop entries the user may not see (ACL), mark the current one active, group by
 *     section and sort by ordering.
 *
 * The result is plain data the menu layout renders; the menu lives entirely inside our
 * own page, never injected into Joomla's chrome.
 *
 * @since  1.7.0
 */
final class MenuBuilder
{
    /**
     * Request cache: the menu is identical for the injector and the panel view within one
     * request, and building it boots every alfa* plugin, so do it once.
     *
     * @var array<int, array{label:string, items:MenuItem[]}>|null
     * @since  1.7.0
     */
    private static ?array $cache = null;

    /**
     * Build the menu, grouped and ordered, for the current user and request.
     *
     * @param Input $input The current request (used to flag the active item).
     * @param User $user The current user (used to filter by ACL).
     *
     * @return array<int, array{label:string, items:MenuItem[]}> Ordered groups; the
     *                                                           ungrouped bucket (label '') first.
     *
     * @since  1.7.0
     */
    public function build(Input $input, User $user): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $event = $this->collect();
        $visible = [];

        foreach ($event->all() as $item) {
            if ($item->accessAction !== null && !$user->authorise($item->accessAction, $item->accessAsset)) {
                continue;
            }

            $item->active = $this->isActive($item, $input);
            $visible[] = $item;
        }

        return self::$cache = $this->group($visible, $event->sections());
    }

    /**
     * Every permission the menu gates an entry on, in the order the menu draws the entries for
     * someone who may see them all. The Permissions tab lists its rows in this order.
     *
     * @return string[]
     *
     * @since  1.10.0
     */
    public function accessOrder(): array
    {
        $event = $this->collect();
        $order = [];

        foreach ($this->group($event->all(), $event->sections()) as $group) {
            foreach ($group['items'] as $item) {
                if ($item->accessAction !== null) {
                    $order[] = $item->accessAction;
                }
            }
        }

        return array_values(array_unique($order));
    }

    /**
     * Gather every entry, core and plugin, with the headings that place them.
     *
     *
     * @since  1.10.0
     */
    private function collect(): BuildMenuEvent
    {
        $event = new BuildMenuEvent('onAlfaBuildMenu', []);

        // Core places its headings first, so a plugin may position the heading it
        // owns but never move Sales below its own.
        foreach ($this->coreSections() as $section => $ordering) {
            $event->section($section, $ordering);
        }

        foreach ($this->coreItems() as $item) {
            $event->add($item);
        }

        PanelDispatcher::broadcast($event, 'onAlfaBuildMenu');

        return $event;
    }

    /**
     * May this user reach this panel? The built menu is already ACL-filtered, so a panel
     * the user is not permitted to see is simply absent from it: the menu IS the panel
     * ACL. Every panel declares a menu entry (with its access action), so this is the one
     * authoritative gate the view and the mutation controller both use.
     *
     * @param Input $input The request.
     * @param User $user The current user.
     * @param string $group Panel owner group.
     * @param string $element Panel owner element.
     * @param string $panel Panel id.
     *
     *
     * @since  1.7.0
     */
    public function canAccessPanel(Input $input, User $user, string $group, string $element, string $panel): bool
    {
        return $this->panelItem(input: $input, user: $user, group: $group, element: $element, panel: $panel) !== null;
    }

    /**
     * The tasks a panel declares on its menu entry as lookups: they only read, and are the
     * only tasks of the panel the controller accepts by GET. Empty when the panel declares
     * none or the user may not reach it.
     *
     * @param Input $input The request.
     * @param User $user The current user.
     * @param string $group Panel owner group.
     * @param string $element Panel owner element.
     * @param string $panel Panel id.
     *
     * @return string[]
     *
     * @since  1.14.0
     */
    public function panelLookups(Input $input, User $user, string $group, string $element, string $panel): array
    {
        return $this->panelItem(input: $input, user: $user, group: $group, element: $element, panel: $panel)?->lookups ?? [];
    }

    /**
     * The menu entry of a panel, as this user sees the menu, or null when it is not there.
     *
     * @param Input $input The request.
     * @param User $user The current user.
     * @param string $group Panel owner group.
     * @param string $element Panel owner element.
     * @param string $panel Panel id.
     *
     *
     * @since  1.14.0
     */
    private function panelItem(Input $input, User $user, string $group, string $element, string $panel): ?MenuItem
    {
        foreach ($this->build($input, $user) as $groupData) {
            foreach ($groupData['items'] as $item) {
                if (($item->activeWhen['group'] ?? '') === $group
                    && ($item->activeWhen['element'] ?? '') === $element
                    && ($item->activeWhen['panel'] ?? '') === $panel) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Parse the core entries from menu.xml into MenuItems.
     *
     * @return MenuItem[]
     *
     * @since  1.7.0
     */
    /**
     * The headings menu.xml places, keyed by language key.
     *
     * @return array<string, int>
     *
     * @since  1.10.0
     */
    private function coreSections(): array
    {
        $file = JPATH_ADMINISTRATOR . '/components/com_alfa/menu.xml';

        if (!is_file($file)) {
            return [];
        }

        try {
            $xml = new SimpleXMLElement(file_get_contents($file));
        } catch (Throwable $e) {
            return [];
        }

        $sections = [];

        foreach ($xml->section as $node) {
            $id = (string) $node['id'];

            if ($id !== '') {
                $sections[$id] = (int) $node['ordering'];
            }
        }

        return $sections;
    }

    private function coreItems(): array
    {
        $file = JPATH_ADMINISTRATOR . '/components/com_alfa/menu.xml';

        if (!is_file($file)) {
            return [];
        }

        try {
            $xml = new SimpleXMLElement(file_get_contents($file));
        } catch (Throwable $e) {
            return [];
        }

        $items = [];

        foreach ($xml->item as $node) {
            $id = (string) $node['id'];

            if ($id === '') {
                continue;
            }

            $item = (new MenuItem($id))
                ->title((string) $node['title'])
                ->section((string) $node['section'])
                ->icon((string) $node['icon'])
                ->ordering((int) $node['ordering']);

            $view = (string) $node['view'];

            if ($view !== '') {
                $item->view($view);
            } elseif ((string) $node['link'] !== '') {
                $item->link((string) $node['link']);
            }

            // Gate each core item on its entity's view permission, unless it declares its
            // own, so the menu already respects the granular ACL (alfa.<entity>.view).
            if ((string) $node['access'] !== '') {
                $item->access((string) $node['access']);
            } elseif ($view !== '') {
                $item->access('alfa.' . $view . '.view');
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Is this item the current screen: every one of its activeWhen params matches the
     * request.
     *
     * @param MenuItem $item The item.
     * @param Input $input The request.
     *
     *
     * @since  1.7.0
     */
    private function isActive(MenuItem $item, Input $input): bool
    {
        if ($item->activeWhen === []) {
            return false;
        }

        foreach ($item->activeWhen as $key => $value) {
            if ($input->getCmd($key, '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Group items by section and sort each group by ordering.
     *
     * Sections render ungrouped first, then every placed heading by its ordering,
     * then the headings nobody placed in first-seen order, so a plugin's heading
     * lands where the plugin said, and one that said nothing lands at the end.
     *
     * @param MenuItem[] $items The visible items.
     * @param array<string, int> $sections Placed headings, keyed by language key.
     *
     * @return array<int, array{label:string, items:MenuItem[]}>
     *
     * @since  1.7.0
     */
    private function group(array $items, array $sections = []): array
    {
        $buckets = [];
        $seen = [];

        foreach ($items as $item) {
            $buckets[$item->section][] = $item;
            $seen[$item->section] ??= \count($seen);
        }

        $rank = static function (string $section) use ($sections, $seen): array {
            if ($section === '') {
                return [0, 0, 0];
            }

            return isset($sections[$section])
                ? [1, $sections[$section], $seen[$section]]
                : [2, 0, $seen[$section]];
        };

        uksort($buckets, static fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        $groups = [];

        foreach ($buckets as $section => $bucket) {
            usort($bucket, static fn (MenuItem $x, MenuItem $y): int => $x->ordering <=> $y->ordering);

            $groups[] = ['label' => (string) $section, 'items' => $bucket];
        }

        return $groups;
    }
}
