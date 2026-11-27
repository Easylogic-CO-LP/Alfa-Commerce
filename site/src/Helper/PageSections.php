<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * The sections of a product page, under the gallery and the buy box: one collection, built once per page and drawn
 * by layouts/common/sections.php in the presentation the menu item chose (stacked, tabs, accordion).
 *
 * In order: the description (the full description with the FullDescription surface), the specifications (the
 * SpecificationsSection surface as a label and value table), then the general sections of Options, the product's own
 * sections and the fields placed in a section of their own, by their ordering number, then the sections plugins
 * contribute (the Sections surface) in contribution order. A section replaces an earlier one with the same key: a
 * product's section replaces the general section whose key it names (an empty body removes it for that product).
 * A section with nothing inside, or without a title, is not in the collection.
 *
 * Every section has a stable key, from which the page derives its element ids: the general section's key, the
 * field's machine name, description, specifications, own-<id> for a product's section of its own, the plugin's
 * key or name for a plugin's. Never the translated title.
 *
 * @since  2.3.0
 */
final class PageSections
{
    /**
     * The description section's key.
     *
     * @since  2.3.0
     */
    public const DESCRIPTION = 'description';

    /**
     * The specifications section's key.
     *
     * @since  2.3.0
     */
    public const SPECIFICATIONS = 'specifications';

    /**
     * The product page's sections, in display order.
     *
     * @param object $item The product (full_desc, categories, sections, fields, surfaces).
     * @param Registry $params The component's Options (product_sections).
     *
     * @return list<array{key: string, title: string, body: string, ordering: int, source: string}>
     *
     * @since  2.3.0
     */
    public static function collect(object $item, Registry $params): array
    {
        $sections = [];

        // The description: the product's text and whatever a plugin adds under it.
        $text = trim((string) ($item->full_desc ?? ''));
        $body = ($text !== '' ? nl2br($text) : '') . Surface::render($item, ItemSurface::FullDescription);
        self::put($sections, self::DESCRIPTION, Text::_('COM_ALFA_ITEM_DESCRIPTION'), $body, PHP_INT_MIN, 'core');

        // The specifications: the fields placed there, one table row each.
        if (Surface::has($item, ItemSurface::SpecificationsSection)) {
            $body = '<table class="alfa-spec-table"><tbody>'
                . Surface::render($item, ItemSurface::SpecificationsSection, tag: 'tr')
                . '</tbody></table>';
            self::put($sections, self::SPECIFICATIONS, Text::_('COM_ALFA_ITEM_SPECIFICATIONS'), $body, PHP_INT_MIN + 1, 'core');
        }

        // The general sections of Options, those whose categories admit the product (none named = every product).
        $itemCategories = array_map('intval', array_keys((array) ($item->categories ?? [])));

        foreach (self::general($params) as $general) {
            if ($general['categories'] !== [] && array_intersect($general['categories'], $itemCategories) === []) {
                continue;
            }

            self::put($sections, $general['key'], $general['title'], $general['body'], $general['ordering'], 'general');
        }

        // The product's own: each replaces the general section it names, or is a section of its own.
        foreach ((array) ($item->sections ?? []) as $row) {
            $key = self::key((string) ($row->section_key ?? '')) ?: 'own-' . (int) ($row->id ?? 0);
            $title = trim((string) ($row->title ?? ''));
            self::put($sections, $key, $title !== '' ? $title : (string) ($sections[$key]['title'] ?? ''), (string) ($row->body ?? ''), (int) ($row->position ?? 0), 'product');
        }

        // The Sections surface: a field placed in a section of its own (by its ordering), a plugin's section (last).
        $count = 0;

        foreach ($item->surfaces[ItemSurface::Sections->value] ?? [] as $descriptor) {
            $data = (array) ($descriptor['layoutData'] ?? []);
            $fieldName = (string) ($descriptor['fieldName'] ?? '');
            $isField = ($descriptor['fieldId'] ?? null) !== null;
            $field = $isField ? ($item->fields[$fieldName] ?? ItemFieldsHelper::find($item, (int) $descriptor['fieldId'])) : null;
            $key = $isField
                ? (self::key($fieldName) ?: 'field-' . (int) $descriptor['fieldId'])
                : (self::key((string) ($data['key'] ?? '')) ?: self::key((string) ($descriptor['layoutPluginName'] ?? 'plugin') . '-' . ++$count));
            $title = (string) ($data['title'] ?? ($field->display_name ?? ''));
            $body = Layout::render('item.surfaces', ['surfaces' => [$descriptor], 'slot' => ItemSurface::Sections->value, 'item' => $item]);
            self::put($sections, $key, $title, $body, $isField ? (int) ($field->ordering ?? 0) : PHP_INT_MAX, $isField ? 'field' : 'plugin');
        }

        $list = array_values(array_filter(
            $sections,
            static fn (array $section): bool => trim($section['body']) !== '' && $section['title'] !== '',
        ));

        usort($list, static fn (array $a, array $b): int => $a['ordering'] <=> $b['ordering']);

        return $list;
    }

    /**
     * The general sections of Options, each with its title and body in the page's language, its ordering and the
     * categories it is limited to ([] for every product), in the order they are listed.
     *
     * @param Registry $params The component's Options.
     *
     * @return list<array{key: string, title: string, body: string, ordering: int, categories: list<int>}>
     *
     * @since  2.3.0
     */
    public static function general(Registry $params): array
    {
        $out = [];

        foreach ((array) $params->get('product_sections', []) as $row) {
            $row = (array) $row;
            $key = self::key((string) ($row['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $out[] = [
                'key' => $key,
                'title' => MultilingualHelper::resolveLangMap($row['title'] ?? ''),
                'body' => MultilingualHelper::resolveLangMap($row['body'] ?? ''),
                'ordering' => (int) ($row['ordering'] ?? 0),
                'categories' => array_values(array_filter(array_map('intval', (array) ($row['categories'] ?? [])))),
            ];
        }

        return $out;
    }

    /**
     * A section key as the page uses it: lower-case letters, digits and hyphens, at most 64 characters; '' when
     * nothing of it is left.
     *
     * @param string $key The key as stored.
     *
     * @since  2.3.0
     */
    public static function key(string $key): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($key))), '-');

        return substr($key, 0, 64);
    }

    /**
     * Add a section, replacing the one with the same key.
     *
     * @param array<string, array> $sections The collection, by key.
     * @param string $key The section's key.
     * @param string $title Its title.
     * @param string $body Its body, HTML.
     * @param int $ordering Its ordering number.
     * @param string $source Where it comes from: core, general, product, field, plugin.
     *
     * @since  2.3.0
     */
    private static function put(array &$sections, string $key, string $title, string $body, int $ordering, string $source): void
    {
        $sections[$key] = ['key' => $key, 'title' => $title, 'body' => $body, 'ordering' => $ordering, 'source' => $source];
    }
}
