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
 * What a product's Labels tab chose, in two homes.
 *
 * The labels the product ticks are rows of `#__alfa_item_labels`: a row per label (#__alfa_labels), with a corner of
 * the image of its own when one was chosen (NULL = the label's own corner). The product's own label and its choice for
 * the automatic New label are the product's `label_settings` column, JSON: {"new": {"shown": 0|1, "corner": ""},
 * "own": {name by language, bg, color, class, list_show, list_slot, item_show, item_slot, corner, publish_up,
 * publish_down}}; NULL = no own label and the New label as the shop shows it.
 *
 * The save asks {@see self::refusals()} before its transaction and writes the rows with {@see self::replace()} inside
 * it; the settings travel with the row as a column. The item delete removes a deleted product's rows with its other
 * pivots; the label delete removes a deleted label's rows. The storefront reads a page's products' rows in one query
 * ({@see self::forItems()}) and decides what each shows in {@see \Alfa\Component\Alfa\Site\Service\Catalog\Labels}.
 *
 * @since  2.3.0
 */
final class ItemLabels
{
    /**
     * The pivot of the labels a product ticks.
     *
     * @since  2.3.0
     */
    public const TABLE = '#__alfa_item_labels';

    /**
     * The product's column of its own label and its New label choice.
     *
     * @since  2.3.0
     */
    public const SETTINGS_COLUMN = 'label_settings';

    /**
     * The corners of a set of products' ticked labels, read in one query, by product id then by label id (NULL = the
     * label's own corner).
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $itemIds The products.
     *
     * @return array<int, array<int, string|null>>
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
                ->select($db->quoteName(['item_id', 'label_id', 'corner']))
                ->from($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('item_id'), $ids),
        )->loadObjectList() ?: [];

        $byItem = [];

        foreach ($rows as $row) {
            $byItem[(int) $row->item_id][(int) $row->label_id] = $row->corner === null ? null : (string) $row->corner;
        }

        return $byItem;
    }

    /**
     * The product form's value of its labels field from the product's rows: by label id, ticked (on) and the corner
     * chosen for it ('' = the label's own). The field adds the shop's other labels unticked.
     *
     * @param array<int, string|null> $rows The product's rows, by label id.
     *
     * @return array<int, array{on: int, corner: string}>
     *
     * @since  2.3.0
     */
    public static function formValue(array $rows): array
    {
        $value = [];

        foreach ($rows as $labelId => $corner) {
            $value[(int) $labelId] = ['on' => 1, 'corner' => (string) ($corner ?? '')];
        }

        return $value;
    }

    /**
     * The rows a save writes, from what was posted as `labels`: the form's map (label id => {on, corner}), a REST
     * list of ids, or a map of label id => corner; the corners may also come as `label_corners` (label id => corner).
     * A label unticked has no row; a corner that names none of the four is the label's own.
     *
     * @param mixed $labels The labels: a list of ids, or a map by id.
     * @param mixed $corners The corners by label id, when sent apart from the labels.
     *
     * @return array<int, string|null> By label id: the corner, NULL for the label's own.
     *
     * @since  2.3.0
     */
    public static function posted(mixed $labels, mixed $corners = []): array
    {
        $labels = \is_array($labels) || \is_object($labels) ? (array) $labels : [];
        $corners = \is_array($corners) || \is_object($corners) ? (array) $corners : [];
        $rows = [];

        if (array_is_list($labels)) {
            // A list of ids: each label in its own corner, or the corner label_corners names for it.
            foreach ($labels as $labelId) {
                if (is_numeric($labelId) && (int) $labelId > 0) {
                    $rows[(int) $labelId] = LabelCorner::tryFrom(trim((string) ($corners[(int) $labelId] ?? '')))?->value;
                }
            }

            return $rows;
        }

        foreach ($labels as $labelId => $choice) {
            if (!is_numeric($labelId) || (int) $labelId <= 0) {
                continue;
            }

            if (\is_array($choice) || \is_object($choice)) {
                // The form's map: the label is ticked only while its row says so.
                $choice = (array) $choice;

                if (empty($choice['on'])) {
                    continue;
                }

                $corner = (string) ($choice['corner'] ?? '');
            } else {
                // A map of label id => corner ('' = the label's own).
                $corner = (string) ($choice ?? '');
            }

            $rows[(int) $labelId] = LabelCorner::tryFrom(trim($corner))?->value;
        }

        return $rows;
    }

    /**
     * The product's settings column decoded: its choice for the New label (shown, corner) and its own label's settings
     * (null for none).
     *
     * @param mixed $stored The column's value (JSON), or null.
     *
     * @return array{new: array{shown: int, corner: string}, own: array<string, mixed>|null}
     *
     * @since  2.3.0
     */
    public static function settingsOf(mixed $stored): array
    {
        $decoded = \is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;
        $decoded = \is_array($decoded) ? $decoded : [];
        $new = (array) ($decoded['new'] ?? []);
        $own = isset($decoded['own']) && \is_array($decoded['own']) && $decoded['own'] !== [] ? $decoded['own'] : null;

        return [
            'new' => ['shown' => (int) ($new['shown'] ?? 1), 'corner' => LabelCorner::tryFrom((string) ($new['corner'] ?? ''))?->value ?? ''],
            'own' => $own,
        ];
    }

    /**
     * The settings column a save writes from what the product form posted: the New label's choice and the product's
     * own label with its period; NULL when both are as the shop has them (the New label shown in its own corner, no own
     * label). An own label without a name in any language is none.
     *
     * @param mixed $newShown Whether the New label shows on this product (1) or not (0).
     * @param mixed $newCorner The New label's corner on this product; '' = the label's own.
     * @param mixed $own The product's own label: name (by language), bg, color, class, list_show, list_slot,
     *                   item_show, item_slot, corner.
     * @param mixed $from The own label shows from this moment (UTC); '' = always.
     * @param mixed $to The own label shows until this moment (UTC); '' = always.
     *
     * @return string|null The JSON, or NULL for nothing of the product's own.
     *
     * @since  2.3.0
     */
    public static function settingsPosted(mixed $newShown, mixed $newCorner, mixed $own, mixed $from, mixed $to): ?string
    {
        $settings = [];
        $shown = $newShown === null || $newShown === '' ? 1 : (int) (bool) $newShown;
        $corner = LabelCorner::tryFrom(trim((string) $newCorner))?->value ?? '';

        if ($shown === 0 || $corner !== '') {
            $settings['new'] = ['shown' => $shown, 'corner' => $corner];
        }

        $own = (array) $own;
        $name = [];

        foreach ((array) ($own['name'] ?? []) as $language => $text) {
            if (trim((string) $text) !== '') {
                $name[(string) $language] = trim((string) $text);
            }
        }

        if ($name !== []) {
            $settings['own'] = [
                'name' => $name,
                'bg' => trim((string) ($own['bg'] ?? '')),
                'color' => trim((string) ($own['color'] ?? '')),
                'class' => trim((string) ($own['class'] ?? '')),
                'list_show' => (int) !empty($own['list_show']),
                'list_slot' => trim((string) ($own['list_slot'] ?? 'image')),
                'item_show' => (int) !empty($own['item_show']),
                'item_slot' => trim((string) ($own['item_slot'] ?? 'image')),
                'corner' => LabelCorner::of((string) ($own['corner'] ?? ''))->value,
                'publish_up' => trim((string) $from),
                'publish_down' => trim((string) $to),
            ];
        }

        return $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * What refuses the settings a save writes, each a sentence: the product's own label with a period that ends before
     * it starts.
     *
     * @param string|null $settings The settings column as {@see self::settingsPosted()} made it.
     *
     * @return list<string>
     *
     * @since  2.3.0
     */
    public static function refusals(?string $settings): array
    {
        $own = self::settingsOf($settings)['own'];

        if ($own !== null && !PublishPeriod::endsAfterStart(start: $own['publish_up'] ?? null, end: $own['publish_down'] ?? null)) {
            return [Text::_('COM_ALFA_ITEM_LABEL_PERIOD_END_BEFORE_START')];
        }

        return [];
    }

    /**
     * Replace a product's rows with the posted ones, inside the save's transaction.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param array<int, string|null> $rows By label id: the corner, NULL for the label's own.
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

        self::add(db: $db, itemIds: [$itemId], rows: $rows);
    }

    /**
     * Give these products these labels, keeping a corner a product already chose for one of them: the Products list's
     * batch, and the second half of {@see self::replace()}.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $itemIds The products.
     * @param array<int, string|null> $rows By label id: the corner, NULL for the label's own.
     *
     * @since  2.3.0
     */
    public static function add(DatabaseInterface $db, array $itemIds, array $rows): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));

        if ($itemIds === [] || $rows === []) {
            return;
        }

        $tuples = [];

        foreach ($itemIds as $itemId) {
            foreach ($rows as $labelId => $corner) {
                if ((int) $labelId > 0) {
                    $tuples[] = $itemId . ', ' . (int) $labelId . ', ' . ($corner === null ? 'NULL' : $db->quote($corner));
                }
            }
        }

        if ($tuples === []) {
            return;
        }

        // A product that already ticks the label keeps its row and its corner.
        $db->setQuery(
            'INSERT IGNORE INTO ' . $db->quoteName(self::TABLE)
            . ' (' . implode(', ', $db->quoteName(['item_id', 'label_id', 'corner'])) . ') VALUES (' . implode('), (', $tuples) . ')',
        )->execute();
    }

    /**
     * Take these labels off these products: the Products list's batch.
     *
     * @param DatabaseInterface $db The database.
     * @param int[] $itemIds The products.
     * @param int[] $labelIds The labels.
     *
     * @since  2.3.0
     */
    public static function remove(DatabaseInterface $db, array $itemIds, array $labelIds): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));
        $labelIds = array_values(array_filter(array_map('intval', $labelIds), static fn (int $id): bool => $id > 0));

        if ($itemIds === [] || $labelIds === []) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('item_id'), $itemIds)
                ->whereIn($db->quoteName('label_id'), $labelIds),
        )->execute();
    }
}
