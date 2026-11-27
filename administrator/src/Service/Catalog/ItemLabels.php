<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Catalog\LabelCorner;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * A product's rows of `#__alfa_item_labels`: what the product's Labels tab chose. A row per predefined label the
 * product ticks (its key, shown, and a corner of its own when one was chosen), one row for the automatic New label
 * only when the product hides it or moves it to a corner of its own, and one row for the product's own label with its
 * settings as JSON. A product with no row shows the New label as the shop does and no other label.
 *
 * The save asks {@see self::refusals()} before its transaction and writes {@see self::replace()} inside it; the item
 * delete removes a deleted product's rows with its other pivots. The storefront reads a page's products in one query
 * ({@see self::forItems()}) and decides what each shows in {@see \Alfa\Component\Alfa\Site\Service\Catalog\Labels}.
 *
 * @since  2.3.0
 */
final class ItemLabels
{
    /**
     * The table.
     *
     * @since  2.3.0
     */
    public const TABLE = '#__alfa_item_labels';

    /**
     * The key of the automatic New label's row.
     *
     * @since  2.3.0
     */
    public const KEY_NEW = 'new';

    /**
     * The key of the product's own label's row.
     *
     * @since  2.3.0
     */
    public const KEY_CUSTOM = 'custom';

    /**
     * What a key may be made of: lower-case letters, digits, dots, dashes and underscores, at most 64.
     *
     * @since  2.3.0
     */
    private const KEY_PATTERN = '/^[a-z0-9_.-]{1,64}$/';

    /**
     * The rows of a set of products, read in one query, by product id then by label key.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $itemIds The products.
     *
     * @return array<int, array<string, object>>
     *
     * @since  2.3.0
     */
    public static function forItems(DatabaseInterface $db, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['item_id', 'label_key', 'shown', 'corner', 'custom']))
                ->from($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('item_id'), $ids),
        )->loadObjectList() ?: [];

        $byItem = [];

        foreach ($rows as $row) {
            $byItem[(int) $row->item_id][(string) $row->label_key] = $row;
        }

        return $byItem;
    }

    /**
     * The product form's value of its labels field, from the product's rows: by key, whether it is shown and the
     * corner chosen for it ('' = the label's own). The New label is shown unless a row hides it; a predefined label is
     * shown when it has a row. The field adds the shop's labels without a row as unticked.
     *
     * @param array<string, object> $rows The product's rows, by key.
     *
     * @return array<string, array{shown: int, corner: string}>
     *
     * @since  2.3.0
     */
    public static function formValue(array $rows): array
    {
        $value = [self::KEY_NEW => ['shown' => 1, 'corner' => '']];

        foreach ($rows as $key => $row) {
            if ((string) $key === self::KEY_CUSTOM) {
                continue;
            }

            $value[(string) $key] = ['shown' => (int) $row->shown, 'corner' => (string) ($row->corner ?? '')];
        }

        return $value;
    }

    /**
     * The product form's value of its own label, from the product's rows: its settings with its corner and its period
     * (publish_up, publish_down); [] when the product has none.
     *
     * @param array<string, object> $rows The product's rows, by key.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    public static function customValue(array $rows): array
    {
        $row = $rows[self::KEY_CUSTOM] ?? null;

        if ($row === null) {
            return [];
        }

        $settings = json_decode((string) ($row->custom ?? ''), true);

        if (!\is_array($settings)) {
            return [];
        }

        $settings['corner'] = LabelCorner::of(isset($row->corner) ? (string) $row->corner : null)->value;

        return $settings;
    }

    /**
     * The rows a save writes, from what the product form posted: the labels field (by key: shown and corner) and the
     * product's own label (its settings, its period). A predefined label unticked has no row; the New label has one
     * only when hidden or moved; the product's own label has one only when it has a name in some language.
     *
     * @param mixed $labels The labels field's value: key => [shown, corner].
     * @param mixed $custom The product's own label: name (by language), bg, color, class, list_show, list_slot,
     *                      item_show, item_slot, corner.
     * @param mixed $from The product's own label shows from this moment (UTC); '' = always.
     * @param mixed $to The product's own label shows until this moment (UTC); '' = always.
     *
     * @return list<array{label_key: string, shown: int, corner: string|null, custom: string|null}>
     *
     * @since  2.3.0
     */
    public static function posted(mixed $labels, mixed $custom, mixed $from, mixed $to): array
    {
        $rows = [];

        foreach ((array) $labels as $key => $choice) {
            $key = strtolower(trim((string) $key));
            $choice = (array) $choice;

            if ($key === self::KEY_CUSTOM || preg_match(self::KEY_PATTERN, $key) !== 1) {
                continue;
            }

            $shown = (int) !empty($choice['shown']);
            $corner = LabelCorner::tryFrom(trim((string) ($choice['corner'] ?? '')))?->value;

            // The New label: a row only where the product departs from the shop's own showing.
            if ($key === self::KEY_NEW ? ($shown === 1 && $corner === null) : $shown === 0) {
                continue;
            }

            $rows[$key] = ['label_key' => $key, 'shown' => $shown, 'corner' => $corner, 'custom' => null];
        }

        $custom = (array) $custom;
        $name = [];

        foreach ((array) ($custom['name'] ?? []) as $language => $text) {
            if (trim((string) $text) !== '') {
                $name[(string) $language] = trim((string) $text);
            }
        }

        if ($name !== []) {
            $settings = [
                'name' => $name,
                'bg' => trim((string) ($custom['bg'] ?? '')),
                'color' => trim((string) ($custom['color'] ?? '')),
                'class' => trim((string) ($custom['class'] ?? '')),
                'list_show' => (int) !empty($custom['list_show']),
                'list_slot' => trim((string) ($custom['list_slot'] ?? 'image')),
                'item_show' => (int) !empty($custom['item_show']),
                'item_slot' => trim((string) ($custom['item_slot'] ?? 'image')),
                'publish_up' => trim((string) $from),
                'publish_down' => trim((string) $to),
            ];
            $rows[self::KEY_CUSTOM] = [
                'label_key' => self::KEY_CUSTOM,
                'shown' => 1,
                'corner' => LabelCorner::of((string) ($custom['corner'] ?? ''))->value,
                'custom' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return array_values($rows);
    }

    /**
     * What refuses the posted rows, each a sentence: the product's own label with a period that ends before it starts.
     *
     * @param list<array{label_key: string, shown: int, corner: string|null, custom: string|null}> $rows The rows.
     *
     * @return list<string>
     *
     * @since  2.3.0
     */
    public static function refusals(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row['label_key'] !== self::KEY_CUSTOM) {
                continue;
            }

            $settings = json_decode((string) $row['custom'], true) ?: [];

            if (!PublishPeriod::endsAfterStart(start: $settings['publish_up'] ?? null, end: $settings['publish_down'] ?? null)) {
                return [Text::_('COM_ALFA_ITEM_LABEL_PERIOD_END_BEFORE_START')];
            }
        }

        return [];
    }

    /**
     * Replace a product's rows with the posted ones, inside the save's transaction.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param list<array{label_key: string, shown: int, corner: string|null, custom: string|null}> $rows The rows.
     *
     * @since  2.3.0
     */
    public static function replace(DatabaseInterface $db, int $itemId, array $rows): void
    {
        if ($itemId <= 0) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('item_id') . ' = ' . $itemId),
        )->execute();

        if ($rows === []) {
            return;
        }

        $tuples = [];

        foreach ($rows as $row) {
            $tuples[] = $itemId . ', ' . $db->quote($row['label_key']) . ', ' . (int) $row['shown']
                . ', ' . ($row['corner'] === null ? 'NULL' : $db->quote($row['corner']))
                . ', ' . ($row['custom'] === null ? 'NULL' : $db->quote($row['custom']));
        }

        $db->setQuery(
            $db->getQuery(true)
                ->insert($db->quoteName(self::TABLE))
                ->columns($db->quoteName(['item_id', 'label_key', 'shown', 'corner', 'custom']))
                ->values($tuples),
        )->execute();
    }
}
