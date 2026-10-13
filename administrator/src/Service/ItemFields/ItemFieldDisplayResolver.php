<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\ItemFields;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Throwable;

\defined('_JEXEC') or die;

/**
 * The one place that turns an item-field or value id into its display name.
 *
 * A field's label lived in three copies before this: the storefront helper
 * read it one way, the admin cart read raw ids, and the order snapshot read
 * it a third way, and the three disagreed, so the same cart could read
 * "Size" in the storefront and "Field 8" in the back office. This service
 * owns the single rule, and everything that needs a label asks here.
 *
 * The rule: the translated name from the per-language table
 * (#__alfa_item_fields_<lang>.name / #__alfa_item_field_values_<lang>.name),
 * across every content language, falling back, in this one place, to the
 * field's own machine name and finally to "#<id>" when nothing else exists.
 *
 * Two lifetimes consume it, and the difference is the caller's, not the
 * service's. A live reading resolves the map to the current language now
 * ({@see self::fieldLabel()}); order placement freezes the whole map so a
 * historical order keeps the name the shopper saw even after the field is
 * renamed or removed ({@see self::fieldLabelMap()}). The service never reads
 * a frozen row and never decides which lifetime it is serving.
 *
 * @since  2.0.0
 */
final class ItemFieldDisplayResolver
{
    /**
     * The label map for each field id: {langCode: name} across content
     * languages, the current language always populated (machine name, then
     * "#<id>", when there is no translation).
     *
     * @param int[] $fieldIds Field definition ids.
     *
     * @return array<int, array<string, string>> Field id -> {langCode: name}.
     *
     * @since  2.0.0
     */
    public static function fieldLabelMaps(array $fieldIds): array
    {
        return self::labelMaps(
            ids:        $fieldIds,
            baseTable:  '#__alfa_item_fields',
            langTable:  '#__alfa_item_fields_',
            foreignKey: 'id_item_field',
            nameColumn: 'field_name',
        );
    }

    /**
     * The value-name map for each value id: {langCode: name} across content
     * languages, the current language always populated ("#<id>" when there is
     * no translation; a value carries no machine name of its own).
     *
     * @param int[] $valueIds Field-value ids.
     *
     * @return array<int, array<string, string>> Value id -> {langCode: name}.
     *
     * @since  2.0.0
     */
    public static function valueTextMaps(array $valueIds): array
    {
        return self::labelMaps(
            ids:        $valueIds,
            baseTable:  '#__alfa_item_field_values',
            langTable:  '#__alfa_item_field_values_',
            foreignKey: 'id_item_field_value',
            nameColumn: null,
        );
    }

    /**
     * One field's label in the current language.
     *
     * @param int $fieldId Field definition id.
     *
     *
     * @since  2.0.0
     */
    public static function fieldLabel(int $fieldId): string
    {
        return MultilingualHelper::resolveLangMap(
            json_encode(self::fieldLabelMaps([$fieldId])[$fieldId] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * One value's name in the current language.
     *
     * @param int $valueId Field-value id.
     *
     *
     * @since  2.0.0
     */
    public static function valueText(int $valueId): string
    {
        return MultilingualHelper::resolveLangMap(
            json_encode(self::valueTextMaps([$valueId])[$valueId] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Build the {langCode: name} map for a set of ids over one field/value
     * domain. Each id is guaranteed a current-language entry so the map is
     * never empty: a translation when there is one, otherwise the machine
     * name (fields) and finally "#<id>".
     *
     * @param int[] $ids Field or value ids.
     * @param string $baseTable The definition table (for the fallback name).
     * @param string $langTable The per-language table prefix (trailing underscore).
     * @param string $foreignKey The id column both tables key on.
     * @param string|null $nameColumn The base-table column holding the machine name, or null.
     *
     * @return array<int, array<string, string>>
     *
     * @since  2.0.0
     */
    private static function labelMaps(array $ids, string $baseTable, string $langTable, string $foreignKey, ?string $nameColumn): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $current = strtolower(str_replace('-', '_', MultilingualHelper::getCurrentLanguageTag()));
        $maps = [];

        // Per-language translated names, across every published content language.
        foreach (array_keys(LanguageHelper::getLanguages('lang_code') ?: []) as $langCode) {
            $langKey = strtolower(str_replace('-', '_', (string) $langCode));

            try {
                $rows = $db->setQuery(
                    $db->getQuery(true)
                        ->select([$db->quoteName($foreignKey), $db->quoteName('name')])
                        ->from($db->quoteName($langTable . $langKey))
                        ->whereIn($db->quoteName($foreignKey), $ids),
                )->loadObjectList() ?: [];
            } catch (Throwable $e) {
                // Aux table pending resync for this language: skip it, not an error.
                continue;
            }

            foreach ($rows as $row) {
                if ((string) $row->name !== '') {
                    $maps[(int) $row->{$foreignKey}][$langKey] = (string) $row->name;
                }
            }
        }

        // The one fallback: machine name, then "#<id>", pinned to the current
        // language so a live read and a frozen snapshot both have something.
        $fallbacks = [];

        if ($nameColumn !== null) {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->quoteName('id'), $db->quoteName($nameColumn)])
                    ->from($db->quoteName($baseTable))
                    ->whereIn($db->quoteName('id'), $ids),
            )->loadObjectList('id') ?: [];

            foreach ($rows as $id => $row) {
                $fallbacks[(int) $id] = (string) ($row->{$nameColumn} ?? '');
            }
        }

        foreach ($ids as $id) {
            if (!isset($maps[$id][$current]) || $maps[$id][$current] === '') {
                $machine = $fallbacks[$id] ?? '';
                $maps[$id][$current] = $machine !== '' ? $machine : '#' . $id;
            }
        }

        return $maps;
    }
}
