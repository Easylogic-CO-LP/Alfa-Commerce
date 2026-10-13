<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaFilters\Site\Section;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Throwable;

defined('_JEXEC') or die;

/**
 * WHAT THE PANEL SHOWS, AND IN WHAT ORDER.
 *
 * The panel is a sequence of SECTIONS. Some are built into the module: the category tree,
 * the manufacturer list, the price slider, the ordering and limit selects, the on-sale and
 * discount controls. The rest are the catalogue's own filterable item fields, one section
 * each, and those are neither known nor knowable here: a merchant adds a field and the
 * panel must simply grow one.
 *
 * ORDER COMES FROM THE ITEM FIELD, NOT FROM THIS MODULE. A field already carries an
 * `ordering` in its backend record, which is where a merchant naturally arranges them and
 * which every other surface already obeys. That is the resolution order. This module adds
 * only an OPTIONAL override, for the merchant who wants one panel arranged differently from
 * the catalogue's own sequence.
 *
 * THE OVERRIDE STORES ONLY WHAT WAS SAID, NEVER A SNAPSHOT. If the module saved the full
 * list it saw at configuration time, that list would silently become the authority: a field
 * added next week would be absent from it and would have to be either dropped (invisible
 * until someone re-saves every module) or appended at the end (ignoring the ordering the
 * merchant just set). Both are wrong, and both are the kind of wrong that is only noticed
 * months later. So the saved value is the merchant's explicit ORDER and their explicit
 * HIDDEN set, and anything not named in either is reconciled at render time back into its
 * natural position (see {@see self::resolve()}).
 *
 * GROUPING IS NOT HERE. Whether a field's values collapse into groups is decided once, in
 * that field's own type settings, and is deliberately not repeated per module.
 *
 * @since  2.0.0
 */
final class FilterSections
{
    /**
     * Built-in sections that precede the item fields, in their natural order.
     *
     * @var string[]
     * @since  2.0.0
     */
    public const FIXED_BEFORE = ['category', 'manufacturer'];

    /**
     * Built-in sections that follow the item fields, in their natural order.
     *
     * @var string[]
     * @since  2.0.0
     */
    public const FIXED_AFTER = [
        'stock',
        'price',
        'ordering',
        'limit',
        'on_sale',
        'discount_amount',
        'discount_percent',
    ];

    /**
     * Key prefix marking a section as one item field's facet.
     *
     * @var string
     * @since  2.0.0
     */
    public const FIELD_PREFIX = 'field:';

    /**
     * Language key per built-in section. These are the SAME strings the sections already
     * render with, so the composer names a section exactly as the storefront does.
     *
     * @var array<string, string>
     * @since  2.0.0
     */
    private const FIXED_LABELS = [
        'category' => 'MOD_ALFAFILTERS_FILTER_CATEGORY',
        'manufacturer' => 'MOD_ALFAFILTERS_FILTER_MANUFACTURER',
        'stock' => 'MOD_ALFAFILTERS_FILTER_STOCK_LABEL',
        'price' => 'MOD_ALFAFILTERS_FILTER_PRICE',
        'ordering' => 'MOD_ALFAFILTERS_FILTER_ORDERING_LABEL',
        'limit' => 'MOD_ALFAFILTERS_FILTER_LIST_LIMIT_LABEL',
        'on_sale' => 'MOD_ALFAFILTERS_FILTER_ON_SALE_LABEL',
        'discount_amount' => 'MOD_ALFAFILTERS_FILTER_DISCOUNT_AMOUNT_MIN_LABEL',
        'discount_percent' => 'MOD_ALFAFILTERS_FILTER_DISCOUNT_PERCENT_MIN_LABEL',
    ];

    /**
     * The section key for one item field.
     *
     * @param int $fieldId The item field id.
     *
     *
     * @since  2.0.0
     */
    public static function fieldKey(int $fieldId): string
    {
        return self::FIELD_PREFIX . $fieldId;
    }

    /**
     * The natural sequence for a given set of item fields.
     *
     * The item fields are placed as a block where they render today (after the
     * manufacturer list, before the price slider) and among themselves they keep the order
     * they arrive in, which on both call paths is the backend `ordering`.
     *
     * @param int[] $fieldIds Item field ids, already in backend ordering.
     *
     * @return string[] Section keys in natural order.
     *
     * @since  2.0.0
     */
    public static function natural(array $fieldIds): array
    {
        return array_merge(
            self::FIXED_BEFORE,
            array_map(static fn ($id): string => self::fieldKey((int) $id), array_values($fieldIds)),
            self::FIXED_AFTER,
        );
    }

    /**
     * Read a stored override, whatever shape it survived in.
     *
     * Tolerant on purpose: the value travels as a JSON string in the module params, but a
     * Registry, a form reload or a hand-edited param can hand back an array or an object,
     * and an unconfigured module hands back an empty string. None of those is an error:
     * they all mean "no override", or "this much override".
     *
     * @param mixed $raw The stored value.
     *
     * @return array{order: string[], hidden: string[]}
     *
     * @since  2.0.0
     */
    public static function decode($raw): array
    {
        if (\is_string($raw)) {
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }

        $raw = (array) ($raw ?: []);

        $clean = static function ($list): array {
            $out = [];

            foreach ((array) ($list ?: []) as $key) {
                $key = trim((string) $key);

                if ($key !== '' && !\in_array($key, $out, true)) {
                    $out[] = $key;
                }
            }

            return $out;
        };

        return [
            'order' => $clean($raw['order'] ?? []),
            'hidden' => $clean($raw['hidden'] ?? []),
        ];
    }

    /**
     * The order to render in: the merchant's explicit sequence, with everything it does not
     * mention woven back into its natural position.
     *
     * The stored sequence is a PREFERENCE, never the authority. It decides the order of the
     * sections it names and nothing else; it does not decide which sections exist, and a
     * section missing from it is never a section that disappears. So:
     *
     *   a saved key that no longer exists is DROPPED, because a deleted field must not
     *   leave a hole in the panel; and
     *
     *   a live key the sequence never mentions is WOVEN IN: immediately after the last of
     *   its natural predecessors that is present, or, when it has none, immediately before
     *   the first of its natural successors, or last of all when it has neither.
     *
     * That second rule is the whole point of storing a preference rather than a snapshot: a
     * field added next week lands beside the neighbours its backend `ordering` gives it,
     * visible, without anyone reopening a single module. Missing keys are walked in natural
     * order, so a run of new neighbours also keeps its own order.
     *
     * @param string[] $natural The natural sequence for what actually exists now.
     * @param mixed $saved The stored override, in any shape {@see self::decode()} takes.
     *
     * @return string[] Section keys to render, in order, hidden ones already removed.
     *
     * @since  2.0.0
     */
    public static function resolve(array $natural, $saved): array
    {
        $hidden = self::decode($saved)['hidden'];

        return array_values(array_filter(
            self::order($natural, $saved),
            static fn (string $key): bool => !\in_array($key, $hidden, true),
        ));
    }

    /**
     * The same resolved sequence, but WITH the hidden sections still in it.
     *
     * The composer needs this: a section a merchant hid still has a place in their
     * arrangement, and dropping it from the list would silently move it to the end the
     * moment they switched it back on. Hiding something should not relocate it.
     *
     * @param string[] $natural The natural sequence for what actually exists now.
     * @param mixed $saved The stored override, in any shape {@see self::decode()} takes.
     *
     * @return string[] Every live section key, in resolved order.
     *
     * @since  2.0.0
     */
    public static function order(array $natural, $saved): array
    {
        $natural = array_values(array_unique(array_map('strval', $natural)));
        $saved = self::decode($saved);

        // Only what still exists, in the sequence the merchant put it in.
        $result = array_values(array_filter(
            $saved['order'],
            static fn (string $key): bool => \in_array($key, $natural, true),
        ));

        foreach ($natural as $position => $key) {
            if (\in_array($key, $result, true)) {
                continue;
            }

            $at = null;

            // Nearest natural PREDECESSOR that is present: land just after it.
            for ($back = $position - 1; $back >= 0; $back--) {
                $anchor = array_search($natural[$back], $result, true);

                if ($anchor !== false) {
                    $at = $anchor + 1;

                    break;
                }
            }

            // None present: nearest natural SUCCESSOR instead; land just before it. This
            // is the case for a section that opens the panel, where there is no predecessor
            // to hang off by definition.
            if ($at === null) {
                for ($fwd = $position + 1, $last = \count($natural); $fwd < $last; $fwd++) {
                    $anchor = array_search($natural[$fwd], $result, true);

                    if ($anchor !== false) {
                        $at = $anchor;

                        break;
                    }
                }
            }

            array_splice($result, $at ?? \count($result), 0, [$key]);
        }

        return $result;
    }

    /**
     * The label a section is named by in the composer.
     *
     * @param string $key The section key.
     * @param array<int, string> $fields fieldId => label, for the item-field sections.
     *
     *
     * @since  2.0.0
     */
    public static function label(string $key, array $fields = []): string
    {
        if (isset(self::FIXED_LABELS[$key])) {
            return Text::_(self::FIXED_LABELS[$key]);
        }

        if (str_starts_with($key, self::FIELD_PREFIX)) {
            $id = (int) substr($key, \strlen(self::FIELD_PREFIX));

            return (string) ($fields[$id] ?? ('#' . $id));
        }

        return $key;
    }

    /**
     * Every filterable item field the catalogue has, in backend ordering.
     *
     * Read straight from the field table rather than from the storefront facets, because
     * the composer must offer a field a merchant can arrange TODAY even when this moment's
     * catalogue happens to have no items carrying it: a facet with no reachable values
     * does not render, but it is still a configured field and hiding it from the composer
     * would make the panel look arbitrary.
     *
     * @param DatabaseInterface|null $db Database driver; the container's by default.
     * @param string $tag Language tag for the translated name.
     *
     * @return array<int, string> fieldId => label, ordered.
     *
     * @since  2.0.0
     */
    public static function filterableFields(?DatabaseInterface $db = null, string $tag = ''): array
    {
        $db ??= Factory::getContainer()->get(DatabaseInterface::class);
        $tag = $tag !== '' ? $tag : Factory::getApplication()->getLanguage()->getTag();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.id', 'f.field_name']))
            ->from($db->quoteName('#__alfa_item_fields', 'f'))
            ->where($db->quoteName('f.filterable') . ' = 1')
            ->where($db->quoteName('f.state') . ' = 1')
            ->order($db->quoteName('f.ordering') . ' ASC')
            ->order($db->quoteName('f.id') . ' ASC');

        // The translated name lives in a per-language aux table. It is the better label,
        // but a language installed after the fields were created may not have one yet, so
        // the internal field_name is the fallback rather than the failure.
        $aux = '#__alfa_item_fields_' . strtolower(str_replace('-', '_', $tag));

        try {
            $query->select($db->quoteName('t.name', 'translated'))
                ->join(
                    'LEFT',
                    $db->quoteName($aux, 't'),
                    $db->quoteName('t.id_item_field') . ' = ' . $db->quoteName('f.id'),
                );

            $rows = $db->setQuery($query)->loadObjectList() ?: [];
        } catch (Throwable) {
            $rows = [];
        }

        if (empty($rows)) {
            $query->clear('join')->clear('select')
                ->select($db->quoteName(['f.id', 'f.field_name']));

            try {
                $rows = $db->setQuery($query)->loadObjectList() ?: [];
            } catch (Throwable) {
                return [];
            }
        }

        $fields = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row->translated ?? ''));
            $fields[(int) $row->id] = $label !== '' ? $label : (string) $row->field_name;
        }

        return $fields;
    }
}
