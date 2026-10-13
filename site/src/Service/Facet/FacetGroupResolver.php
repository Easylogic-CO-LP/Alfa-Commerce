<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Facet;

defined('_JEXEC') or die;

/**
 * Buckets item-field VALUES into facet GROUPS, without ever knowing what a group means.
 *
 * A field type reports, per value, the groups it belongs to: `(type, id)` pairs it has
 * already normalised itself
 * ({@see \Alfa\Component\Alfa\Administrator\Event\ItemFields\ValueFacetGroupsEvent}).
 * Everything here is set logic over those pairs: collect them, invert them, give each one
 * a URL-safe token, and hand back both directions of the mapping.
 *
 * NOTHING IN THIS CLASS INTERPRETS `type` OR `id`. They are opaque strings. It does not
 * know that '#0000ff' is a colour, that two colours could be similar, or that 'nike' is a
 * brand: a Brand plugin and a Colour plugin are indistinguishable from in here, which is
 * exactly the point: a new field type joins the filter by answering the event, and no
 * component or module code changes to accommodate it.
 *
 * The only ordering opinion it holds about GROUPS is a stable one: the type's own
 * `ordering` hint, then the label as a plain string comparison, then the id, so the panel
 * does not reshuffle between requests. It holds NO opinion about VALUES: every value list
 * it returns comes back in the caller's key order, which is the merchant's configured
 * ordering, because rearranging the merchant's values is not this class's decision.
 *
 * @since  2.0.0
 */
final class FacetGroupResolver
{
    /**
     * Token prefix.
     *
     * A group id cannot travel in the query string as itself. It is an opaque string that
     * may legitimately contain '#' (which opens a URL fragment), and
     * UrlListModel::normalizeArrayValue() int-casts an array whose members are ALL numeric
     * - which a brand id like '12345' would be. Hashing sidesteps both: the token is always
     * this prefix plus hex, so it is never numeric and never carries a reserved character.
     *
     * @var string
     * @since  2.0.0
     */
    private const TOKEN_PREFIX = 'g';

    /**
     * A URL-safe, request-stable token for one group.
     *
     * A hash rather than an encoding: it is short, it cannot smuggle anything into the
     * query string, and it need not be reversible: the component resolves a token by
     * looking it up in the very map it just built from the same inputs.
     *
     * @param string $type The group namespace.
     * @param string $id The group identity.
     *
     *
     * @since  2.0.0
     */
    public static function token(string $type, string $id): string
    {
        return self::TOKEN_PREFIX . substr(sha1($type . "\0" . $id), 0, 16);
    }

    /**
     * Invert per-value memberships into groups, and report which values had none.
     *
     * @param array $groupsByValue valueId => list of membership descriptors, as reported.
     *
     * @return array{
     *     groups: array<string, array{token: string, type: string, id: string, label: string, layout: string, layoutData: array, valueIds: int[]}>,
     *     byValue: array<int, string[]>,
     *     ungrouped: int[]
     * }
     *
     * @since  2.0.0
     */
    public static function resolve(array $groupsByValue): array
    {
        $groups = [];
        $byValue = [];
        $ungrouped = [];

        // TWO ORDERS, ON PURPOSE. The WALK runs in value-id order, so which membership
        // gets to name a group (first one wins) is decided by identity and never by the
        // order the rows arrived in. What is EMITTED is then put back into the caller's
        // key order, which on the storefront is the merchant's configured value order (see
        // the projection in ItemsModel::buildFacetValues()). Sorting the walk and sorting
        // the output are different questions and had been answered with one sort().
        $inputOrder = array_flip(array_map('intval', array_keys($groupsByValue)));

        $valueIds = array_keys($inputOrder);
        sort($valueIds);

        foreach ($valueIds as $valueId) {
            $memberships = array_filter((array) ($groupsByValue[$valueId] ?? []), '\is_array');

            if (empty($memberships)) {
                $ungrouped[] = $valueId;

                continue;
            }

            foreach ($memberships as $membership) {
                $type = trim((string) ($membership['type'] ?? ''));
                $id = trim((string) ($membership['id'] ?? ''));

                if ($type === '' || $id === '') {
                    continue;
                }

                $token = self::token($type, $id);

                if (!isset($groups[$token])) {
                    $groups[$token] = [
                        'token' => $token,
                        'type' => $type,
                        'id' => $id,
                        // The id is the fallback label so a group is never nameless; the
                        // component is not judging whether it reads well.
                        'label' => trim((string) ($membership['label'] ?? '')) ?: $id,
                        'layout' => trim((string) ($membership['layout'] ?? '')),
                        'layoutData' => (array) ($membership['layoutData'] ?? []),
                        'ordering' => (int) ($membership['ordering'] ?? 0),
                        'valueIds' => [],
                    ];
                }

                $groups[$token]['valueIds'][] = $valueId;
                $byValue[$valueId][] = $token;
            }

            if (empty($byValue[$valueId])) {
                // Every membership it offered was malformed: treat it as ungrouped rather
                // than losing the value from the panel entirely.
                $ungrouped[] = $valueId;
            }
        }

        // The value LISTS go back into the caller's order. GROUP order is a separate
        // contract and stays where it was (the type's own `ordering` hint, then label,
        // then id) because a group is not a value and has no place in the merchant's
        // value sequence.
        $byInputOrder = static fn (int $a, int $b): int
            => ($inputOrder[$a] ?? PHP_INT_MAX) <=> ($inputOrder[$b] ?? PHP_INT_MAX);

        foreach (array_keys($groups) as $token) {
            usort($groups[$token]['valueIds'], $byInputOrder);
        }

        usort($ungrouped, $byInputOrder);

        uasort(
            $groups,
            static fn (array $a, array $b): int => ($a['ordering'] <=> $b['ordering'])
                ?: (strcasecmp($a['label'], $b['label']) ?: strcmp($a['id'], $b['id'])),
        );

        return ['groups' => $groups, 'byValue' => $byValue, 'ungrouped' => $ungrouped];
    }
}
