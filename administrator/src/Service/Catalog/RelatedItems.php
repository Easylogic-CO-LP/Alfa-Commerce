<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Catalog\RelatedLinksChangedEvent;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Model\ItemModel;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * A product's related products: the products its page shows after it, in the order staff set on its Related tab, each
 * a {@see RelatedLink}: the product alone, one of its combinations, or values chosen for some of its fields.
 *
 * A link goes one way. A row of `#__alfa_items_links` from A to B shows B on A's page and nothing on B's; B shows A
 * only through a row of its own. Each row names its kind of link in `link_type`: {@see self::TYPE_RELATED} is the one
 * kind core writes and reads; the column is there so that a further kind (an up-sell, a cross-sell) needs no schema
 * change, and a row of another kind is neither read nor replaced here. A link's combination is its row's
 * linked_combination_id (0 for none) and its values are rows of `#__alfa_items_link_values`, one a field. A product is
 * linked at most once on a product's page, as a product, a combination or a preselection.
 *
 * The links are kept whatever state either product is in (a trashed, unpublished, archived or scheduled product keeps
 * its links and shows again when it is published), and so is a link to an unpublished combination; the storefront shows
 * only what the visitor may buy. A deleted product's rows go on both sides ({@see self::deleteFor()}). A link whose
 * combination or value is no longer the linked product's (a combination deleted or archived by the variants grid, an
 * option removed, a value deleted) is removed by the save that removed it, in its transaction, and the save says so
 * ({@see self::forgetVanished()}).
 *
 * The save asks {@see self::refusals()} before its transaction and writes {@see self::replace()} inside it. Refused,
 * each with its sentence and nothing saved: the product itself, a product named twice, a product that does not exist,
 * a combination that is not a current combination of the product, a value that is not of the field named, a value
 * the product does not take (a field neither a dimension of its combinations nor of an option it offers, a dimension
 * value none of its current combinations carries, an option value it does not offer), and dimension values no current
 * combination carries together. Values naming every dimension are stored as the combination they name, so one meaning
 * has one stored form. A product or combination gone between the check and the write is left out by the write itself.
 *
 * @since  2.2.2
 */
final class RelatedItems
{
    /**
     * The table.
     *
     * @since  2.2.2
     */
    public const TABLE = '#__alfa_items_links';

    /**
     * The links' values.
     *
     * @since  2.3.0
     */
    public const VALUES_TABLE = '#__alfa_items_link_values';

    /**
     * The kind of link core writes and reads: the product page's related products.
     *
     * @since  2.2.2
     */
    public const TYPE_RELATED = 'related';

    /**
     * The choices of the Related tab's variant cell, by product, for the request.
     *
     * @var array<int, array>
     * @since  2.3.0
     */
    private static array $choices = [];

    /**
     * The related products of a product, in their order.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param int $limit How many at most; 0 for every one.
     * @param int $offset How many to skip first.
     *
     * @return list<RelatedLink>
     *
     * @since  2.3.0
     */
    public static function links(DatabaseInterface $db, int $itemId, int $limit = 0, int $offset = 0): array
    {
        if ($itemId <= 0) {
            return [];
        }

        // The page of links first, then its values joined: one statement, the paging on the links alone.
        $page = 'SELECT ' . $db->quoteName('linked_item_id') . ', ' . $db->quoteName('linked_combination_id') . ', ' . $db->quoteName('ordering')
            . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . $db->quoteName('link_type') . ' = ' . $db->quote(self::TYPE_RELATED)
            . ' ORDER BY ' . $db->quoteName('ordering') . ' ASC, ' . $db->quoteName('linked_item_id') . ' ASC'
            . ($limit > 0 ? ' LIMIT ' . max(0, $offset) . ', ' . $limit : ($offset > 0 ? ' LIMIT ' . $offset . ', 18446744073709551615' : ''));
        $rows = $db->setQuery(
            'SELECT l.' . $db->quoteName('linked_item_id') . ', l.' . $db->quoteName('linked_combination_id')
            . ', v.' . $db->quoteName('field_id') . ', v.' . $db->quoteName('value_id')
            . ' FROM (' . $page . ') AS l'
            . ' LEFT JOIN ' . $db->quoteName(self::VALUES_TABLE) . ' AS v ON v.' . $db->quoteName('item_id') . ' = ' . $itemId
            . ' AND v.' . $db->quoteName('linked_item_id') . ' = l.' . $db->quoteName('linked_item_id')
            . ' AND v.' . $db->quoteName('link_type') . ' = ' . $db->quote(self::TYPE_RELATED)
            . ' ORDER BY l.' . $db->quoteName('ordering') . ' ASC, l.' . $db->quoteName('linked_item_id') . ' ASC',
        )->loadObjectList() ?: [];

        return array_values(self::linksFromRows(rows: $rows));
    }

    /**
     * The related products as the item record and the Related tab hold them: {item_id, combination_id (0 for none),
     * values (field id to value id)} each, in their order.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     *
     * @return list<array{item_id: int, combination_id: int, values: array<int, int>}>
     *
     * @since  2.2.2
     */
    public static function rows(DatabaseInterface $db, int $itemId): array
    {
        return array_map(static fn (RelatedLink $link): array => $link->toRow(), self::links(db: $db, itemId: $itemId));
    }

    /**
     * The links a save carries, by their place in it (0 for the first row sent): the rows the Related field gives
     * ({item_id, combination_id, values}) or links. An empty row (a picker left empty, naming nothing) is no link and
     * keeps its place, so a refusal names the row as it was sent.
     *
     * @param mixed $posted The save's `related` value.
     *
     * @return array<int, RelatedLink>
     *
     * @since  2.2.2
     */
    public static function posted(mixed $posted): array
    {
        $links = [];
        $place = 0;

        foreach (\is_array($posted) ? $posted : [] as $row) {
            $link = $row instanceof RelatedLink ? $row : self::linkOf(row: $row);

            if ($link !== null) {
                $links[$place] = $link;
            }

            $place++;
        }

        return $links;
    }

    /**
     * Why a save may not write these links, one refusal each, pointing at its row (/related/<place>); empty when it
     * may. At most four statements whatever the number of rows (the products, their current combinations, the options
     * they offer, the fields of the values named), and the names of what a refusal names.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product being saved; 0 for a new one (a copy among them).
     * @param array<int, RelatedLink> $links The links, by their place in the save ({@see self::posted()}).
     *
     * @return list<Refusal>
     *
     * @since  2.2.2
     */
    public static function refusals(DatabaseInterface $db, int $itemId, array $links): array
    {
        if ($links === []) {
            return [];
        }

        $refusals = [];
        $ids = array_values(array_unique(array_map(static fn (RelatedLink $link): int => $link->itemId, $links)));
        $existing = [];

        if (array_filter($ids, static fn (int $id): bool => $id > 0) !== []) {
            $existing = array_map('intval', $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__alfa_items'))
                    ->whereIn($db->quoteName('id'), array_values(array_filter($ids, static fn (int $id): bool => $id > 0))),
            )->loadColumn() ?: []);
        }

        $judged = [];
        $seen = [];

        foreach ($links as $place => $link) {
            $at = '/related/' . $place;

            if ($itemId > 0 && $link->itemId === $itemId) {
                $refusals[] = new Refusal(code: 'related_self', sentence: Text::_('COM_ALFA_ITEM_RELATED_SELF'), pointer: $at);

                continue;
            }

            if (!\in_array($link->itemId, $existing, true)) {
                $refusals[] = new Refusal(code: 'related_missing', sentence: Text::sprintf('COM_ALFA_ITEM_RELATED_MISSING', $link->itemId), pointer: $at);

                continue;
            }

            if (isset($seen[$link->itemId])) {
                $refusals[] = new Refusal(code: 'related_twice', sentence: Text::sprintf('COM_ALFA_ITEM_RELATED_TWICE', ItemModel::nameForStaff(itemId: $link->itemId)), pointer: $at);

                continue;
            }

            $seen[$link->itemId] = true;

            if ($link->isPreselected()) {
                $judged[$place] = $link;
            }
        }

        if ($judged === []) {
            return $refusals;
        }

        $catalog = self::catalogOf(
            db: $db,
            itemIds: array_map(static fn (RelatedLink $link): int => $link->itemId, array_values($judged)),
            valueIds: array_merge(...array_map(static fn (RelatedLink $link): array => array_values($link->values), array_values($judged))),
        );
        $named = [];

        foreach ($judged as $place => $link) {
            foreach (self::faults(catalog: $catalog[$link->itemId], link: $link) as $fault) {
                $named[] = [$place, $link, $fault];
            }
        }

        if ($named === []) {
            return $refusals;
        }

        $valueIds = [];

        foreach ($named as [, $link, $fault]) {
            array_push($valueIds, ...$fault['values']);
        }

        $names = self::valueNames(db: $db, valueIds: $valueIds);
        $nameOf = static fn (int $valueId): string => $names[$valueId] ?? ('#' . $valueId);

        foreach ($named as [$place, $link, $fault]) {
            $product = ItemModel::nameForStaff(itemId: $link->itemId);
            $sentence = match ($fault['code']) {
                'related_not_variant' => Text::sprintf('COM_ALFA_ITEM_RELATED_NOT_VARIANT', $product, $link->combinationId),
                'related_value_field' => Text::sprintf('COM_ALFA_ITEM_RELATED_VALUE_FIELD', $product, $fault['values'][0], $fault['field']),
                'related_no_such_variant' => Text::sprintf('COM_ALFA_ITEM_RELATED_NO_SUCH_VARIANT', $product, implode(' / ', array_map($nameOf, $fault['values']))),
                default => Text::sprintf('COM_ALFA_ITEM_RELATED_NOT_OFFERED', $product, $nameOf($fault['values'][0])),
            };
            $refusals[] = new Refusal(code: $fault['code'], sentence: $sentence, pointer: '/related/' . $place);
        }

        return $refusals;
    }

    /**
     * Replace a product's related products by these, in this order (ordering 1, 2, 3); its links of another kind stay.
     * Run inside the save's transaction, after {@see self::refusals()}. Values naming every dimension of the product are
     * stored as the combination they name, and a combination link keeps its option values only. Each row is written only
     * while its product (and its combination, for a combination link) is current, so one deleted since the check leaves
     * no link. When the links come out other than they were, onAlfaRelatedLinksChanged (cause save) is raised once the
     * save commits.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param array<int, RelatedLink> $links The links, in their order, checked by {@see self::refusals()}.
     *
     * @since  2.2.2
     */
    public static function replace(DatabaseInterface $db, int $itemId, array $links): void
    {
        $before = array_map(static fn (RelatedLink $link): array => $link->toRow(), self::links(db: $db, itemId: $itemId));
        $after = [];

        foreach ([self::VALUES_TABLE, self::TABLE] as $table) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName('item_id') . ' = ' . (int) $itemId)
                    ->where($db->quoteName('link_type') . ' = ' . $db->quote(self::TYPE_RELATED)),
            )->execute();
        }

        $links = array_values($links);
        $withValues = array_values(array_filter($links, static fn (RelatedLink $link): bool => $link->values !== []));
        $catalog = $withValues === []
            ? []
            : self::catalogOf(db: $db, itemIds: array_map(static fn (RelatedLink $link): int => $link->itemId, $withValues), valueIds: []);
        $position = 0;
        $written = [];

        foreach ($links as $link) {
            if ($link->itemId === $itemId || $link->itemId <= 0 || isset($written[$link->itemId])) {
                continue;
            }

            if ($link->values !== [] && isset($catalog[$link->itemId])) {
                $link = self::normalised(catalog: $catalog[$link->itemId], link: $link);
            }

            $current = $link->combinationId > 0
                ? ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_item_combinations') . ' AS c'
                    . ' WHERE c.' . $db->quoteName('id') . ' = ' . $link->combinationId
                    . ' AND c.' . $db->quoteName('item_id') . ' = ' . $link->itemId
                    . ' AND ' . CombinationState::currentSql(column: 'c.' . $db->quoteName('state')) . ')'
                : '';
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName(self::TABLE)
                . ' (' . $db->quoteName('item_id') . ', ' . $db->quoteName('linked_item_id') . ', ' . $db->quoteName('link_type')
                . ', ' . $db->quoteName('linked_combination_id') . ', ' . $db->quoteName('ordering') . ')'
                . ' SELECT ' . (int) $itemId . ', ' . $db->quoteName('id') . ', ' . $db->quote(self::TYPE_RELATED)
                . ', ' . $link->combinationId . ', ' . ($position + 1)
                . ' FROM ' . $db->quoteName('#__alfa_items')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $link->itemId . $current,
            )->execute();

            if ($db->getAffectedRows() < 1) {
                continue;
            }

            $position++;
            $written[$link->itemId] = true;
            $after[] = $link->toRow();

            if ($link->values === []) {
                continue;
            }

            $tuples = [];

            foreach ($link->values as $fieldId => $valueId) {
                $tuples[] = (int) $itemId . ', ' . $link->itemId . ', ' . $db->quote(self::TYPE_RELATED) . ', ' . (int) $fieldId . ', ' . (int) $valueId;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->insert($db->quoteName(self::VALUES_TABLE))
                    ->columns($db->quoteName(['item_id', 'linked_item_id', 'link_type', 'field_id', 'value_id']))
                    ->values($tuples),
            )->execute();
        }

        if ($after !== $before) {
            self::announce(db: $db, itemIds: [$itemId], cause: RelatedLinksChangedEvent::CAUSE_SAVE);
        }
    }

    /**
     * Remove every link of these products, on both sides and of every kind, with their values: the links they hold and
     * the links other products hold to them. The item delete's own statement
     * ({@see \Alfa\Component\Alfa\Administrator\Model\ItemModel::delete()}); the products that kept links to them
     * hear onAlfaRelatedLinksChanged (cause delete) once the delete commits.
     *
     * @param DatabaseInterface $db The database.
     * @param list<int> $itemIds The deleted products.
     *
     * @since  2.2.2
     */
    public static function deleteFor(DatabaseInterface $db, array $itemIds): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));

        if ($itemIds === []) {
            return;
        }

        $list = implode(',', $itemIds);
        $owners = array_map('intval', $db->setQuery(
            'SELECT DISTINCT ' . $db->quoteName('item_id') . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('linked_item_id') . ' IN (' . $list . ') AND ' . $db->quoteName('item_id') . ' NOT IN (' . $list . ')'
            . ' AND ' . $db->quoteName('link_type') . ' = ' . $db->quote(self::TYPE_RELATED),
        )->loadColumn() ?: []);

        foreach ([self::VALUES_TABLE, self::TABLE] as $table) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($table))
                    ->where('(' . $db->quoteName('item_id') . ' IN (' . $list . ') OR ' . $db->quoteName('linked_item_id') . ' IN (' . $list . '))'),
            )->execute();
        }

        self::announce(db: $db, itemIds: $owners, cause: RelatedLinksChangedEvent::CAUSE_DELETE);
    }

    /**
     * Remove the links to these products that name what they no longer have: a combination that is no longer current
     * (deleted, or archived for its order history), a value none of their current combinations carries, an option
     * they no longer offer, dimension values no current combination carries together. Run inside the transaction
     * that removed it, after the removal: a rollback keeps the links. Once that transaction commits, staff are told
     * how many links went and from which products' pages, and onAlfaRelatedLinksChanged is raised for those products.
     *
     * @param DatabaseInterface $db The database.
     * @param list<int> $linkedItemIds The products whose combinations, options or values were removed.
     * @param string $cause What was removed: RelatedLinksChangedEvent::CAUSE_COMBINATION or CAUSE_VALUE.
     *
     * @return list<int> The products whose links were removed.
     *
     * @since  2.3.0
     */
    public static function forgetVanished(DatabaseInterface $db, array $linkedItemIds, string $cause): array
    {
        $linkedItemIds = array_values(array_unique(array_filter(array_map('intval', $linkedItemIds), static fn (int $id): bool => $id > 0)));

        if ($linkedItemIds === []) {
            return [];
        }

        $rows = $db->setQuery(
            'SELECT l.' . $db->quoteName('item_id') . ', l.' . $db->quoteName('linked_item_id') . ', l.' . $db->quoteName('linked_combination_id')
            . ', v.' . $db->quoteName('field_id') . ', v.' . $db->quoteName('value_id')
            . ' FROM ' . $db->quoteName(self::TABLE) . ' AS l'
            . ' LEFT JOIN ' . $db->quoteName(self::VALUES_TABLE) . ' AS v ON v.' . $db->quoteName('item_id') . ' = l.' . $db->quoteName('item_id')
            . ' AND v.' . $db->quoteName('linked_item_id') . ' = l.' . $db->quoteName('linked_item_id')
            . ' AND v.' . $db->quoteName('link_type') . ' = l.' . $db->quoteName('link_type')
            . ' WHERE l.' . $db->quoteName('linked_item_id') . ' IN (' . implode(',', $linkedItemIds) . ')'
            . ' AND l.' . $db->quoteName('link_type') . ' = ' . $db->quote(self::TYPE_RELATED)
            . ' AND (l.' . $db->quoteName('linked_combination_id') . ' > 0 OR v.' . $db->quoteName('value_id') . ' IS NOT NULL)'
            . ' ORDER BY l.' . $db->quoteName('item_id') . ' ASC',
        )->loadObjectList() ?: [];

        if ($rows === []) {
            return [];
        }

        $owners = [];
        $links = [];

        foreach ($rows as $row) {
            $owners[(int) $row->item_id . ':' . (int) $row->linked_item_id] = (int) $row->item_id;
        }

        foreach (array_keys($owners) as $key) {
            $links[$key] = null;
        }

        foreach ($rows as $row) {
            $key = (int) $row->item_id . ':' . (int) $row->linked_item_id;
            $link = $links[$key] ?? RelatedLink::to((int) $row->linked_item_id)->onCombination((int) $row->linked_combination_id);
            $links[$key] = $row->value_id !== null ? $link->withValue((int) $row->field_id, (int) $row->value_id) : $link;
        }

        $catalog = self::catalogOf(db: $db, itemIds: $linkedItemIds, valueIds: []);
        $gone = [];

        foreach ($links as $key => $link) {
            if (self::faults(catalog: $catalog[$link->itemId] ?? self::emptyCatalog(), link: $link) !== []) {
                $gone[$key] = [$owners[$key], $link->itemId];
            }
        }

        if ($gone === []) {
            return [];
        }

        $pairs = implode(' OR ', array_map(
            static fn (array $pair): string => '(' . $db->quoteName('item_id') . ' = ' . $pair[0] . ' AND ' . $db->quoteName('linked_item_id') . ' = ' . $pair[1] . ')',
            array_values($gone),
        ));

        foreach ([self::VALUES_TABLE, self::TABLE] as $table) {
            $db->setQuery(
                'DELETE FROM ' . $db->quoteName($table)
                . ' WHERE ' . $db->quoteName('link_type') . ' = ' . $db->quote(self::TYPE_RELATED) . ' AND (' . $pairs . ')',
            )->execute();
        }

        $ownerIds = array_values(array_unique(array_map(static fn (array $pair): int => $pair[0], $gone)));
        $count = \count($gone);
        self::announce(db: $db, itemIds: $ownerIds, cause: $cause);

        if ($db instanceof DatabaseDriver) {
            PostCommit::defer(
                db: $db,
                label: 'the related links removed with what they named',
                work: static function () use ($ownerIds, $count): void {
                    $names = implode(', ', array_map(static fn (int $id): string => ItemModel::nameForStaff(itemId: $id), $ownerIds));
                    Factory::getApplication()->enqueueMessage(Text::plural('COM_ALFA_ITEM_RELATED_LINKS_REMOVED', $count, $names), 'warning');
                },
            );
        }

        return $ownerIds;
    }

    /**
     * Remove the links that name these values, deleted from their field, with the links that no longer stand without
     * them: {@see self::forgetVanished()} for the products whose links name them. Run inside the transaction that
     * deleted them, after the deletion.
     *
     * @param DatabaseInterface $db The database.
     * @param list<int> $valueIds The deleted values.
     *
     * @return list<int> The products whose links were removed.
     *
     * @since  2.3.0
     */
    public static function forgetValues(DatabaseInterface $db, array $valueIds): array
    {
        $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0)));

        if ($valueIds === []) {
            return [];
        }

        $linked = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . $db->quoteName('linked_item_id'))
                ->from($db->quoteName(self::VALUES_TABLE))
                ->whereIn($db->quoteName('value_id'), $valueIds),
        )->loadColumn() ?: []);

        return self::forgetVanished(db: $db, linkedItemIds: $linked, cause: RelatedLinksChangedEvent::CAUSE_VALUE);
    }

    /**
     * What the Related tab offers for a product: its current combinations by their grid label (state 0 for an
     * unpublished one), its dimensions with the values its combinations carry, and the options it offers by field, in
     * the product's field order. Read once a product in a request.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     *
     * @return array{combinations: list<array{id: int, label: string, state: int}>, dimensions: list<array{id: int, name: string, values: list<array{id: int, name: string}>}>, options: list<array{id: int, name: string, values: list<array{id: int, name: string}>}>}
     *
     * @since  2.3.0
     */
    public static function choices(DatabaseInterface $db, int $itemId): array
    {
        if (isset(self::$choices[$itemId])) {
            return self::$choices[$itemId];
        }

        $empty = ['combinations' => [], 'dimensions' => [], 'options' => []];

        if ($itemId <= 0) {
            return $empty;
        }

        $order = 'COALESCE(ovr.' . $db->quoteName('ordering') . ', f.' . $db->quoteName('ordering') . ') ASC, f.' . $db->quoteName('ordering')
            . ' ASC, v.' . $db->quoteName('ordering') . ' ASC, v.' . $db->quoteName('id') . ' ASC';
        $overrides = ' LEFT JOIN ' . $db->quoteName('#__alfa_item_field_overrides') . ' AS ovr ON ovr.' . $db->quoteName('field_id') . ' = f.' . $db->quoteName('id')
            . ' AND ovr.' . $db->quoteName('item_id') . ' = ' . $itemId;
        $combinationRows = $db->setQuery(
            'SELECT c.' . $db->quoteName('id') . ', c.' . $db->quoteName('state') . ', v.' . $db->quoteName('id') . ' AS value_id, f.' . $db->quoteName('id') . ' AS field_id'
            . ', v.' . $db->quoteName('ordering') . ' AS value_ordering'
            . ' FROM ' . $db->quoteName('#__alfa_item_combinations') . ' AS c'
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_combination_values') . ' AS cv ON cv.' . $db->quoteName('combination_id') . ' = c.' . $db->quoteName('id')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_field_values') . ' AS v ON v.' . $db->quoteName('id') . ' = cv.' . $db->quoteName('value_id')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_fields') . ' AS f ON f.' . $db->quoteName('id') . ' = v.' . $db->quoteName('item_field_id')
            . $overrides
            . ' WHERE c.' . $db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . CombinationState::currentSql(column: 'c.' . $db->quoteName('state'))
            . ' ORDER BY c.' . $db->quoteName('ordering') . ' ASC, c.' . $db->quoteName('id') . ' ASC, ' . $order,
        )->loadObjectList() ?: [];
        $optionRows = $db->setQuery(
            'SELECT v.' . $db->quoteName('id') . ' AS value_id, f.' . $db->quoteName('id') . ' AS field_id'
            . ' FROM ' . $db->quoteName('#__alfa_item_option_values') . ' AS o'
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_field_values') . ' AS v ON v.' . $db->quoteName('id') . ' = o.' . $db->quoteName('value_id')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_fields') . ' AS f ON f.' . $db->quoteName('id') . ' = v.' . $db->quoteName('item_field_id')
            . $overrides
            . ' WHERE o.' . $db->quoteName('item_id') . ' = ' . $itemId
            . ' ORDER BY ' . $order,
        )->loadObjectList() ?: [];

        $valueIds = array_values(array_unique(array_merge(
            array_map(static fn (object $row): int => (int) $row->value_id, $combinationRows),
            array_map(static fn (object $row): int => (int) $row->value_id, $optionRows),
        )));
        $fieldIds = array_values(array_unique(array_merge(
            array_map(static fn (object $row): int => (int) $row->field_id, $combinationRows),
            array_map(static fn (object $row): int => (int) $row->field_id, $optionRows),
        )));
        $valueNames = self::valueNames(db: $db, valueIds: $valueIds);
        $fieldNames = [];

        if ($fieldIds !== []) {
            foreach (MultilingualHelper::getRecordsByIds(db: $db, ids: $fieldIds, table: '#__alfa_item_fields', langTableBase: '#__alfa_item_fields', langPrimaryColumn: 'id_item_field') as $id => $record) {
                $fieldNames[(int) $id] = (string) ($record['name'] ?? '');
            }
        }

        $name = static fn (array $names, int $id): string => ($names[$id] ?? '') !== '' ? $names[$id] : '#' . $id;
        $combinations = [];
        // The dimensions in the product's field order (a combination's values are read in it), each value with its
        // place in the field's pool.
        $dimensions = [];

        foreach ($combinationRows as $row) {
            $id = (int) $row->id;
            $combinations[$id] ??= ['id' => $id, 'label' => '', 'state' => (int) $row->state];
            $combinations[$id]['label'] .= ($combinations[$id]['label'] !== '' ? ' / ' : '') . $name($valueNames, (int) $row->value_id);
            $dimensions[(int) $row->field_id][(int) $row->value_id] = [(int) $row->value_ordering, (int) $row->value_id];
        }

        foreach ($dimensions as &$values) {
            uasort($values, static fn (array $a, array $b): int => $a <=> $b);
        }

        unset($values);

        // The options' fields, those that are no dimension, in the product's field order, values in the pool's order.
        $options = [];

        foreach ($optionRows as $row) {
            if (!isset($dimensions[(int) $row->field_id])) {
                $options[(int) $row->field_id][(int) $row->value_id] = true;
            }
        }

        $byField = static function (array $pairs) use ($fieldNames, $valueNames, $name): array {
            $out = [];

            foreach ($pairs as $fieldId => $values) {
                $out[] = [
                    'id' => (int) $fieldId,
                    'name' => $name($fieldNames, (int) $fieldId),
                    'values' => array_map(static fn (int $valueId): array => ['id' => $valueId, 'name' => $name($valueNames, $valueId)], array_keys($values)),
                ];
            }

            return $out;
        };

        return self::$choices[$itemId] = [
            'combinations' => array_values($combinations),
            'dimensions' => $byField($dimensions),
            'options' => $byField($options),
        ];
    }

    /**
     * Forget the choices read in this request (after a write that changed them).
     *
     * @since  2.3.0
     */
    public static function clearChoices(): void
    {
        self::$choices = [];
    }

    /**
     * Raise onAlfaRelatedLinksChanged for these products once the transaction that changed their links commits (at
     * once when none is open): the alfa and alfa-search groups, dispatched isolated.
     *
     * @param DatabaseInterface $db The database the change was written on.
     * @param list<int> $itemIds The products whose links changed.
     * @param string $cause Why ({@see RelatedLinksChangedEvent::CAUSE_SAVE} and the others).
     *
     * @since  2.3.0
     */
    private static function announce(DatabaseInterface $db, array $itemIds, string $cause): void
    {
        if ($itemIds === [] || !$db instanceof DatabaseDriver) {
            return;
        }

        PostCommit::defer(
            db: $db,
            label: 'onAlfaRelatedLinksChanged (' . $cause . ')',
            work: static function () use ($itemIds, $cause): void {
                $plugins = AlfaPluginHelper::get();
                $plugins->importGroup('alfa-search');
                $plugins->importGroup('alfa');
                $plugins->dispatchIsolated(RelatedLinksChangedEvent::of(itemIds: $itemIds, cause: $cause));
            },
        );
    }

    /**
     * A link from a row the Related field gives, or null for a row naming nothing.
     *
     * @param mixed $row {item_id, combination_id, values}, as an array or an object.
     *
     * @since  2.3.0
     */
    private static function linkOf(mixed $row): ?RelatedLink
    {
        if (!\is_array($row) && !\is_object($row)) {
            $row = ['item_id' => $row];
        }

        $row = (array) $row;
        $itemId = is_numeric($row['item_id'] ?? null) ? (int) $row['item_id'] : 0;
        $combinationId = is_numeric($row['combination_id'] ?? null) ? (int) $row['combination_id'] : 0;
        $values = [];

        foreach ((array) ($row['values'] ?? []) as $fieldId => $valueId) {
            $values[(int) $fieldId] = is_numeric($valueId) ? (int) $valueId : 0;
        }

        if ($itemId <= 0 && $combinationId <= 0 && $values === []) {
            return null;
        }

        return RelatedLink::to($itemId)->onCombination($combinationId)->withValues($values);
    }

    /**
     * Links from rows of linked_item_id, linked_combination_id and (field_id, value_id) or nulls, in their order.
     *
     * @param list<object> $rows The rows.
     *
     * @return array<int, RelatedLink> By linked product.
     *
     * @since  2.3.0
     */
    private static function linksFromRows(array $rows): array
    {
        $links = [];

        foreach ($rows as $row) {
            $id = (int) $row->linked_item_id;
            $link = $links[$id] ?? RelatedLink::to($id)->onCombination((int) $row->linked_combination_id);
            $links[$id] = $row->value_id !== null ? $link->withValue((int) $row->field_id, (int) $row->value_id) : $link;
        }

        return $links;
    }

    /**
     * What the products take, read for a judgement of links: their current combinations (published and unpublished,
     * never archived) with each one's value by field, and the rule of a selection on each ({@see SelectionRules}). Three
     * statements at most, whatever the number of products and values.
     *
     * @param DatabaseInterface $db The database.
     * @param list<int> $itemIds The products.
     * @param list<int> $valueIds Values the links name, whose fields the rule needs.
     *
     * @return array<int, array{rules: SelectionRules, combinations: array<int, array<int, int>>}> By product.
     *
     * @since  2.3.0
     */
    private static function catalogOf(DatabaseInterface $db, array $itemIds, array $valueIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($itemIds === []) {
            return [];
        }

        $list = implode(',', $itemIds);
        $combinationRows = $db->setQuery(
            'SELECT c.' . $db->quoteName('item_id') . ', c.' . $db->quoteName('id') . ', cv.' . $db->quoteName('value_id') . ', v.' . $db->quoteName('item_field_id')
            . ' FROM ' . $db->quoteName('#__alfa_item_combinations') . ' AS c'
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_item_combination_values') . ' AS cv ON cv.' . $db->quoteName('combination_id') . ' = c.' . $db->quoteName('id')
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_item_field_values') . ' AS v ON v.' . $db->quoteName('id') . ' = cv.' . $db->quoteName('value_id')
            . ' WHERE c.' . $db->quoteName('item_id') . ' IN (' . $list . ')'
            . ' AND ' . CombinationState::currentSql(column: 'c.' . $db->quoteName('state')),
        )->loadObjectList() ?: [];
        $optionRows = $db->setQuery(
            'SELECT o.' . $db->quoteName('item_id') . ', o.' . $db->quoteName('value_id') . ', v.' . $db->quoteName('item_field_id')
            . ' FROM ' . $db->quoteName('#__alfa_item_option_values') . ' AS o'
            . ' LEFT JOIN ' . $db->quoteName('#__alfa_item_field_values') . ' AS v ON v.' . $db->quoteName('id') . ' = o.' . $db->quoteName('value_id')
            . ' WHERE o.' . $db->quoteName('item_id') . ' IN (' . $list . ')',
        )->loadObjectList() ?: [];
        $fieldOf = [];
        $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0)));

        if ($valueIds !== []) {
            foreach ($db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'item_field_id']))
                    ->from($db->quoteName('#__alfa_item_field_values'))
                    ->whereIn($db->quoteName('id'), $valueIds),
            )->loadObjectList() ?: [] as $row) {
                $fieldOf[(int) $row->id] = (int) $row->item_field_id;
            }
        }

        $combinations = array_fill_keys($itemIds, []);
        $valueLists = array_fill_keys($itemIds, []);
        $offered = array_fill_keys($itemIds, []);

        foreach ($combinationRows as $row) {
            $combinations[(int) $row->item_id][(int) $row->id] ??= [];
            $valueLists[(int) $row->item_id][(int) $row->id] ??= [];

            if ($row->value_id !== null && $row->item_field_id !== null) {
                $fieldOf[(int) $row->value_id] = (int) $row->item_field_id;
                $combinations[(int) $row->item_id][(int) $row->id][(int) $row->item_field_id] = (int) $row->value_id;
                $valueLists[(int) $row->item_id][(int) $row->id][] = (int) $row->value_id;
            }
        }

        foreach ($optionRows as $row) {
            if ($row->item_field_id !== null) {
                $fieldOf[(int) $row->value_id] = (int) $row->item_field_id;
            }

            $offered[(int) $row->item_id][] = (int) $row->value_id;
        }

        $catalog = [];

        foreach ($itemIds as $itemId) {
            $catalog[$itemId] = [
                'rules' => SelectionRules::of(fieldOf: $fieldOf, combinations: $valueLists[$itemId], offered: $offered[$itemId]),
                'combinations' => $combinations[$itemId],
            ];
        }

        return $catalog;
    }

    /**
     * A product that takes nothing: no current combination, no option.
     *
     * @return array{rules: SelectionRules, combinations: array<int, array<int, int>>}
     *
     * @since  2.3.0
     */
    private static function emptyCatalog(): array
    {
        return ['rules' => SelectionRules::of(fieldOf: [], combinations: [], offered: []), 'combinations' => []];
    }

    /**
     * What a link names that its product does not take, each with its code and the values it names; empty when the
     * link stands.
     *
     * @param array{rules: SelectionRules, combinations: array<int, array<int, int>>} $catalog What the product takes.
     * @param RelatedLink $link The link.
     *
     * @return list<array{code: string, values: list<int>, field: int}>
     *
     * @since  2.3.0
     */
    private static function faults(array $catalog, RelatedLink $link): array
    {
        $faults = [];

        if ($link->combinationId > 0 && !isset($catalog['combinations'][$link->combinationId])) {
            $faults[] = ['code' => 'related_not_variant', 'values' => [], 'field' => 0];
        }

        $dimensions = [];

        foreach ($link->values as $fieldId => $valueId) {
            $verdict = $catalog['rules']->verdict(fieldId: $fieldId, valueId: $valueId);

            if ($verdict === SelectionRules::DIMENSION) {
                $dimensions[$fieldId] = $valueId;
            } elseif ($verdict === SelectionRules::NOT_A_VALUE_OF_FIELD) {
                $faults[] = ['code' => 'related_value_field', 'values' => [$valueId], 'field' => $fieldId];
            } elseif ($verdict !== SelectionRules::OPTION) {
                $faults[] = ['code' => 'related_not_offered', 'values' => [$valueId], 'field' => $fieldId];
            }
        }

        if ($faults !== [] || $dimensions === []) {
            return $faults;
        }

        // The dimension values together: a combination of the product carries them all (the link's own, for a
        // combination link).
        $candidates = $link->combinationId > 0
            ? [$catalog['combinations'][$link->combinationId] ?? []]
            : $catalog['combinations'];

        foreach ($candidates as $map) {
            if (array_intersect_assoc($dimensions, $map) === $dimensions) {
                return [];
            }
        }

        return [['code' => 'related_no_such_variant', 'values' => array_values($dimensions), 'field' => 0]];
    }

    /**
     * The link as it is stored: values naming every dimension become the combination that carries them, and a
     * combination link keeps its option values only. A link the save refused is never stored.
     *
     * @param array{rules: SelectionRules, combinations: array<int, array<int, int>>} $catalog What the product takes.
     * @param RelatedLink $link The link, judged.
     *
     * @since  2.3.0
     */
    private static function normalised(array $catalog, RelatedLink $link): RelatedLink
    {
        $rules = $catalog['rules'];
        $dimensions = array_filter($link->values, static fn (int $valueId, int $fieldId): bool => $rules->isDimension(fieldId: $fieldId), ARRAY_FILTER_USE_BOTH);

        if ($dimensions === []) {
            return $link;
        }

        $options = array_diff_key($link->values, $dimensions);

        if ($link->combinationId > 0) {
            return $link->withValues($options);
        }

        if (\count($dimensions) !== \count($rules->dimensions())) {
            return $link;
        }

        foreach ($catalog['combinations'] as $combinationId => $map) {
            if ($map == $dimensions) {
                return $link->onCombination((int) $combinationId)->withValues($options);
            }
        }

        return $link;
    }

    /**
     * Value names in the staff member's language, its number for a value without one.
     *
     * @param DatabaseInterface $db The database.
     * @param list<int> $valueIds The values.
     *
     * @return array<int, string>
     *
     * @since  2.3.0
     */
    private static function valueNames(DatabaseInterface $db, array $valueIds): array
    {
        $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0)));
        $names = [];

        if ($valueIds === []) {
            return [];
        }

        foreach (MultilingualHelper::getRecordsByIds(db: $db, ids: $valueIds, table: '#__alfa_item_field_values', langTableBase: '#__alfa_item_field_values', langPrimaryColumn: 'id_item_field_value') as $id => $record) {
            if ((string) ($record['name'] ?? '') !== '') {
                $names[(int) $id] = (string) $record['name'];
            }
        }

        return $names;
    }
}
