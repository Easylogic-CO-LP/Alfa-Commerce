<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * The one order in which the discounts, or the taxes, that match a buyer apply (2.2.1).
 *
 * A rule scoped to a place reaches every place inside it, so a buyer in a town
 * can match a rule of the town, one of its region and one of its country at
 * once, beside rules that name no place. The engine applies the matched rules
 * in one order: the first "only this" rule in it applies alone, and combined
 * and one-after-another rules stack in it, as they always have. This class
 * decides that order, the same for a product's discounts and taxes
 * (PriceDataLoader) and for a shipping charge's taxes (ShippingTaxResolver).
 *
 * THE ORDER. Rules that name no place (or name "every place") keep the order
 * they always had among themselves: by ordering, then by id. Nearness decides
 * only among the rules scoped to a place: a rule of the buyer's own place
 * before one of the region above it, before one of the country, each place's
 * rules by ordering, then id. The two lists are then merged by ordering and id,
 * the way two sorted lists are merged: the next rule is whichever of the two
 * lists' next rules comes first by ordering and id. A shop whose places are all
 * countries has one place in a buyer's chain, so its place-scoped rules are
 * already in ordering and id order and the result is exactly the order of
 * before.
 *
 * As a comparison: each rule gets a key once (keys()), and the rules are sorted
 * by their keys (compare()). A rule with no place is keyed by its own ordering
 * and id. A place-scoped rule is keyed by the largest (ordering, id) among
 * itself and the place-scoped rules before it in the nearness list, then by its
 * position in that list: that is the moment the merge would take it. Keys are
 * lists of integers and no two rules share one, so the comparison is a total
 * order: it never says a < b and b < a, and a < b with b < c always gives
 * a < c.
 *
 * @since  2.2.1
 */
final class RulePrecedence
{
    /**
     * The matched rows of one product's rules (or of one shipping charge's), in the one order: one row per rule.
     *
     * The loader joins a rule's place scope, so a rule that matches through two
     * places of the chain comes as two rows: they are one rule, at its nearest
     * place.
     *
     * @param object[] $rows The matched rows: id, ordering, and matched_place, the place of the rule's scope row that
     *                       matched (null for a rule with no place row, 0 for one that names every place).
     * @param int[] $chain The buyer's place and every place above it, nearest first (PlaceTree::placesFor());
     *                     empty for an unknown place.
     *
     * @return object[] One row per rule, in the order the rules apply.
     *
     * @since  2.2.1
     */
    public static function order(array $rows, array $chain): array
    {
        $first = [];

        foreach ($rows as $row) {
            $first[(int) $row->id] ??= $row;
        }

        $keys = self::keys(rows: $rows, chain: $chain);
        uksort($first, static fn (int $a, int $b): int => self::compare(a: $keys[$a], b: $keys[$b]));

        return array_values($first);
    }

    /**
     * Each rule's key in the one order, by rule id.
     *
     * @param object[] $rows The matched rows (see order()).
     * @param int[] $chain The buyer's chain, nearest first.
     *
     * @return array<int, int[]> Rule id => its key: [ordering, id, position], compared with compare().
     *
     * @since  2.2.1
     */
    public static function keys(array $rows, array $chain): array
    {
        $position = array_flip(array_map('intval', array_values($chain)));
        $rules = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $place = $row->matched_place ?? null;
            $nearness = $place === null || (int) $place === 0 ? null : ($position[(int) $place] ?? null);
            $rule = $rules[$id] ?? ['ordering' => (int) ($row->ordering ?? 0), 'id' => $id, 'anywhere' => false, 'nearness' => null];

            // A rule that matched with no place row, or through "every place", names no place for this buyer, whatever
            // other places it names.
            if ($place === null || (int) $place === 0) {
                $rule['anywhere'] = true;
            } elseif ($nearness !== null) {
                $rule['nearness'] = $rule['nearness'] === null ? $nearness : min($rule['nearness'], $nearness);
            }

            $rules[$id] = $rule;
        }

        $keys = [];
        $scoped = [];

        foreach ($rules as $id => $rule) {
            if ($rule['anywhere'] || $rule['nearness'] === null) {
                $keys[$id] = [$rule['ordering'], $id, 0];
            } else {
                $scoped[] = $rule;
            }
        }

        // The place-scoped rules nearest first, then by ordering and id.
        usort($scoped, static fn (array $a, array $b): int => [$a['nearness'], $a['ordering'], $a['id']] <=> [$b['nearness'], $b['ordering'], $b['id']]);

        // The merge: a place-scoped rule is taken once every place-scoped rule before it is, at the largest
        // (ordering, id) among them.
        $running = null;

        foreach ($scoped as $rank => $rule) {
            $own = [$rule['ordering'], $rule['id']];
            $running = $running === null || $own > $running ? $own : $running;
            $keys[$rule['id']] = [$running[0], $running[1], $rank + 1];
        }

        return $keys;
    }

    /**
     * Compare two rules' keys: negative when the first applies first.
     *
     * @param int[] $a A key from keys().
     * @param int[] $b Another.
     *
     * @since  2.2.1
     */
    public static function compare(array $a, array $b): int
    {
        return $a <=> $b;
    }
}
