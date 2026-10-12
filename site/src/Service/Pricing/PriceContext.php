<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Alfa Commerce
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use RuntimeException;

/**
 * PriceContext: Immutable value object carrying the visitor's pricing dimensions.
 *
 * Dimensions
 * Four orthogonal dimensions fully determine which prices, discounts, and taxes
 * apply to a given visitor:
 *
 *   currencyId   - DB id from #__alfa_currencies (0 = use component default)
 *   userId       - Joomla user id (null = guest)
 *   userGroups   - Array of Joomla usergroup ids. Always contains 0 ("any group"
 *                  sentinel) so public prices are always matched.
 *   locationId   - Place id from #__alfa_places (null = unknown / any place)
 *
 * NOTE: currencyId stores the DB PRIMARY KEY of #__alfa_currencies, not the
 * ISO numeric code (e.g. 978 for EUR). PriceComputationEngine uses
 * Currency::loadById() accordingly.
 *
 * Immutability
 * PriceContext is a value object. All with*() methods return a NEW instance
 * so callers can branch contexts without mutation side-effects:
 *
 *   $base    = PriceContext::fromSession();
 *   $gbp     = $base->withCurrency(47);          // new instance, GBP
 *   $base    unchanged
 *
 * Fluent API
 *   PriceContext::fromSession()              - live visitor context
 *   PriceContext::forIndex($c, $p, $ug)     - one group alone, in one currency and place
 *   PriceContext::customerGroups($customer)  - the groups one customer holds
 *   PriceContext::groupLineage($groupId)     - one group and every group above it
 *   PriceContext::groupLineages($groupIds)   - the same for several groups at once
 *
 *   ->withCurrency(int $id)                 - override currency
 *   ->withLocation(?int $placeId)           - override place
 *   ->withCustomer(User $customer)          - price for one customer account (its id and groups)
 *   ->withUserGroups(array $groups)         - override groups (0 auto-added)
 *   ->withUserId(?int $userId)              - override user
 *   ->withoutUser()                         - make anonymous (index contexts)
 *
 * SESSION KEYS (set by location-detection / currency-selector middleware)
 *   alfa.currency_id  - int   DB id of visitor's chosen/detected currency
 *   alfa.location_id  - int   DB id of visitor's place (#__alfa_places.id)
 *
 * @since  1.0.0
 */
class PriceContext
{
    // Properties (all private: access only through getters and with*() methods)

    /** @var int DB id from #__alfa_currencies. 0 = use component default. */
    private int $currencyId;

    /** @var int|null Joomla user id. null for guests. */
    private ?int $userId;

    /**
     * @var int[] Joomla usergroup ids for this visitor.
     *            Always contains 0 so "applies to all groups" rows are matched.
     */
    private array $userGroups;

    /** @var int|null DB id from #__alfa_places. null = unknown location. */
    private ?int $locationId;

    /**
     * @var string|null Evaluate publish windows at this SQL datetime instead
     *                  of NOW(): the temporal price index computes future
     *                  segments with it. Null = live (NOW()).
     *                  NOTE: apply withReferenceDate() LAST; the other
     *                  with*() methods build fresh instances and reset it.
     */
    private ?string $referenceDate = null;

    /**
     * @var array<int, int>|null How far each group of the context is from the nearest group it is placed in, once
     *                           asked ({@see self::groupDistances()}).
     */
    private ?array $groupDistances = null;

    // Constructor (private: use factory methods)

    /**
     * @param int[] $userGroups
     * @since  1.0.0
     */
    private function __construct(
        int $currencyId,
        ?int $userId,
        array $userGroups,
        ?int $locationId,
    ) {
        $this->currencyId = $currencyId;
        $this->userId = $userId;
        $this->locationId = $locationId;

        // Normalise: deduplicate, cast to int, always include sentinel 0
        $groups = array_unique(array_map('intval', $userGroups));
        if (!in_array(0, $groups, true)) {
            array_unshift($groups, 0);
        }
        $this->userGroups = $groups;
    }

    // Factory methods

    /**
     * Build a context from the current HTTP request / Joomla session.
     *
     * Currency: read from session key 'alfa.currency_id' (DB id of the
     *           currency row, not the ISO code). Falls back to the component
     *           default currency.
     *
     * Location: read from session key 'alfa.location_id' (place DB id).
     *           Set this in your location-detection middleware, e.g.:
     *             Factory::getApplication()->getSession()
     *                 ->set('alfa.location_id', $detectedPlaceId);
     *
     * Identity: the signed-in account, or the anonymous visitor for a guest
     *           (withCustomer()).
     * @since  1.0.0
     */
    public static function fromSession(): self
    {
        $app = Factory::getApplication();
        $session = $app->getSession();

        // Currency
        $currencyId = (int) $session->get('alfa.currency_id', 0);
        if ($currencyId <= 0) {
            try {
                $currencyId = Currency::getDefault()->getId();
            } catch (RuntimeException $e) {
                $currencyId = 0;
            }
        }

        // Location
        $locationId = (int) $session->get('alfa.location_id', 0) ?: null;

        // Identity
        // The signed-in visitor's account, or the anonymous visitor for a guest,
        // by the rule every customer context follows.
        return (new self(currencyId: $currencyId, userId: null, userGroups: [], locationId: $locationId))
            ->withCustomer(customer: $app->getIdentity());
    }

    /**
     * Build an anonymous context for one group alone, in one currency and place.
     *
     * The group is taken WITH every group above it (groupLineage()), behind the
     * "any group" sentinel: what a customer placed in that group alone is priced
     * with. Without a group ($usergroupId = 0), and for a group that no longer
     * exists, the context holds the sentinel alone: what everyone is priced with.
     *
     * @param int $currencyId DB id from #__alfa_currencies; 0 = default
     * @param int $placeId DB id from #__alfa_places;     0 = any
     * @param int $usergroupId Joomla usergroup id;            0 = public
     * @param int|null $userId Specific user id; null = anonymous. A customer account is priced
     *                         with withCustomer(), which also takes the account's own groups.
     * @since  1.0.0
     */
    public static function forIndex(int $currencyId, int $placeId, int $usergroupId, ?int $userId = null): self
    {
        return new self(
            currencyId: $currencyId,
            // Index contexts are anonymous unless the caller names a customer.
            userId: $userId > 0 ? $userId : null,
            // The "any group" sentinel first, then the line of the slot's group.
            userGroups: array_merge([0], self::groupLineage(groupId: $usergroupId)),
            locationId: $placeId > 0 ? $placeId : null,
        );
    }

    // Fluent immutable API: each method returns a NEW instance

    /**
     * Return a new context with a different currency.
     *
     * Usage:
     *   $gbpContext = PriceContext::fromSession()->withCurrency(47);
     *
     * @param int $currencyId DB id from #__alfa_currencies
     * @since  1.0.0
     */
    public function withCurrency(int $currencyId): self
    {
        return new self($currencyId, $this->userId, $this->userGroups, $this->locationId);
    }

    /**
     * Return a new context evaluating publish windows at a specific moment.
     *
     * The temporal price index uses this to compute rows for FUTURE segments
     * ("what will this price be once the promo window opens?"). Pass null to
     * return to live NOW() evaluation. Apply LAST in a with*() chain.
     *
     * @param string|null $referenceDate SQL datetime, or null for NOW()
     * @since  1.3.0
     */
    public function withReferenceDate(?string $referenceDate): self
    {
        $new = clone $this;
        $new->referenceDate = $referenceDate;

        return $new;
    }

    /**
     * The moment publish windows are evaluated at; null = live (NOW()).
     *
     * @since  1.3.0
     */
    public function getReferenceDate(): ?string
    {
        return $this->referenceDate;
    }

    /**
     * Return a new context with a different location (place).
     *
     * Pass null to represent "unknown location" (only universal rows apply).
     *
     * Usage:
     *   $deContext = PriceContext::fromSession()->withLocation(85); // Germany
     *   $anyPlace  = PriceContext::fromSession()->withLocation(null);
     *
     * @param int|null $placeId DB id from #__alfa_places, or null
     * @since  1.0.0
     */
    public function withLocation(?int $placeId): self
    {
        return new self(
            $this->currencyId,
            $this->userId,
            $this->userGroups,
            ($placeId !== null && $placeId > 0) ? $placeId : null,
        );
    }

    /**
     * Return a new context with a different set of usergroups.
     *
     * The sentinel 0 is automatically added if not present.
     *
     * Usage:
     *   $wholesaleCtx = PriceContext::fromSession()->withUserGroups([0, 25]);
     *
     * @param int[] $groups Joomla usergroup ids
     * @since  1.0.0
     */
    public function withUserGroups(array $groups): self
    {
        return new self($this->currencyId, $this->userId, $groups, $this->locationId);
    }

    /**
     * Return a new context with a different user id.
     *
     * @param int|null $userId Joomla user id, or null for guest
     * @since  1.0.0
     */
    public function withUserId(?int $userId): self
    {
        return new self(
            $this->currencyId,
            ($userId !== null && $userId > 0) ? $userId : null,
            $this->userGroups,
            $this->locationId,
        );
    }

    /**
     * Return a new context priced for one customer account.
     *
     * The identity half of every customer's price context, decided here once for
     * the storefront visitor and for each screen that prices for a named customer:
     * the account's own user id, and the groups it holds (customerGroups()).
     *
     * A guest, or an account that cannot be loaded (one since deleted), is no
     * customer account: it prices as the anonymous visitor, with the guest's groups
     * and no user id, so no price, discount or tax scoped to an account reaches it.
     *
     * Currency and place stay as they are.
     *
     * @param User $customer The customer's account as loaded; new User(identifier: $id)
     *                       leaves the id empty for an account that does not exist.
     *
     *
     * @since  1.14.0
     */
    public function withCustomer(User $customer): self
    {
        $customerId = (int) ($customer->id ?? 0);

        return new self(
            currencyId: $this->currencyId,
            userId: $customerId > 0 ? $customerId : null,
            // The "any group" sentinel first, so rules scoped to any group reach
            // every shopper.
            userGroups: array_merge([0], self::customerGroups(customer: $customer)),
            locationId: $this->locationId,
        );
    }

    /**
     * The customer groups one customer holds.
     *
     * Customer-group membership means one thing wherever something is scoped to a
     * group: catalogue prices, catalogue discounts, taxes, coupons, automatic cart
     * discounts, payment methods and shipping methods all decide by this list, so
     * a customer is never inside a group for one of them and outside it for
     * another. It holds the groups the account is placed in and every parent of
     * them, as Joomla authorises them: a member of a group under Wholesale is a
     * member of Wholesale.
     *
     * A guest, or an account that cannot be loaded (one since deleted), is no
     * customer account: it holds the guest group and its parents, whichever
     * screen is asking and whoever is operating it.
     *
     * The "any group" sentinel is not a group and is not in the list; a price
     * context adds it for itself.
     *
     * @param User $customer The customer's account as loaded; new User(identifier: $id)
     *                       leaves the id empty for an account that does not exist.
     *
     * @return int[] Distinct group ids.
     *
     * @since  1.14.0
     */
    public static function customerGroups(User $customer): array
    {
        $groups = [];

        // Joomla answers the ids as strings, and a membership of a group that no
        // longer exists as 0, which is no group.
        foreach ($customer->getAuthorisedGroups() as $groupId) {
            if ((int) $groupId > 0) {
                $groups[] = (int) $groupId;
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * One customer group and every group above it, the root first.
     *
     * The group side of the rule customerGroups() states for an account: what
     * is scoped to a group reaches the members of every group under it, so a
     * group stands for itself and for each group above it. An account placed in
     * one group alone holds exactly this list. A context for one group alone is
     * priced with it (forIndex()), the price editor previews a row scoped to a
     * group under it, and the price display settings of the nearest group are
     * found by it.
     *
     * A group that does not exist is no group, and neither is the "any group"
     * sentinel: both answer an empty list.
     *
     * @param int $groupId The customer group.
     *
     * @return int[] Group ids from the root down to the group itself.
     *
     * @since  1.14.0
     */
    public static function groupLineage(int $groupId): array
    {
        return self::groupLineages(groupIds: [$groupId])[$groupId] ?? [];
    }

    /**
     * Several customer groups, each with every group above it, the root first.
     *
     * Read in one statement from the group tree's nested set, the way Joomla
     * reads the groups of an account (Access::getGroupsByUser()): a group is
     * above another when its range encloses the other's. The two halves of the
     * rule therefore agree in every state of the tree, also where a group's
     * parent row is missing: an account placed in one group alone holds exactly
     * that group's line.
     *
     * @param int[] $groupIds The customer groups.
     *
     * @return array<int, int[]> Group id => group ids from the root down to it. A group that does
     *                           not exist, the "any group" sentinel and a negative id are left out.
     *
     * @since  1.14.0
     */
    public static function groupLineages(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(
            array_map('intval', $groupIds),
            static fn (int $groupId): bool => $groupId > 0,
        )));

        if ($groupIds === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select([$db->quoteName('g.id', 'group_id'), $db->quoteName('line.id', 'line_id')])
            ->from($db->quoteName('#__usergroups', 'g'))
            ->join(
                'INNER',
                $db->quoteName('#__usergroups', 'line'),
                $db->quoteName('line.lft') . ' <= ' . $db->quoteName('g.lft')
                . ' AND ' . $db->quoteName('line.rgt') . ' >= ' . $db->quoteName('g.rgt'),
            )
            ->whereIn($db->quoteName('g.id'), $groupIds)
            ->order([$db->quoteName('g.id'), $db->quoteName('line.lft')]);

        $lineages = [];

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            $lineages[(int) $row['group_id']][] = (int) $row['line_id'];
        }

        return $lineages;
    }

    /**
     * How far each group of this context is from the nearest group it is placed
     * in: 0 for that group, 1 for its parent, 2 for the parent's parent. The
     * groups placed in are the context's deepest ones, those under which no
     * other group of the context lies (the list holds every group above each,
     * as customerGroups() and forIndex() build it); a group above several of
     * them takes the nearest. A price row scoped to a nearer group wins over a
     * farther one. Read with groupLineages(), the
     * one reader of the group tree, once per context.
     *
     * @return array<int, int> Group id => distance; the "any group" sentinel is no group and is not in it.
     *
     * @since  1.14.0
     */
    public function groupDistances(): array
    {
        if ($this->groupDistances !== null) {
            return $this->groupDistances;
        }

        $lineages = self::groupLineages(groupIds: $this->userGroups);
        $above = [];

        foreach ($lineages as $groupId => $line) {
            foreach ($line as $lineId) {
                if ($lineId !== $groupId) {
                    $above[$lineId] = true;
                }
            }
        }

        $distances = [];

        foreach ($lineages as $groupId => $line) {
            // Only from a group placed in: one no other group of the context lies under.
            if (isset($above[$groupId])) {
                continue;
            }

            foreach (array_reverse($line) as $steps => $lineId) {
                $distances[$lineId] = min($steps, $distances[$lineId] ?? $steps);
            }
        }

        return $this->groupDistances = $distances;
    }

    /**
     * Return a new anonymous context (no user, keeps groups and location).
     *
     * Useful for index contexts and public price comparisons.
     *
     * Usage:
     *   $anonCtx = $userCtx->withoutUser();
     * @since  1.0.0
     */
    public function withoutUser(): self
    {
        return new self($this->currencyId, null, $this->userGroups, $this->locationId);
    }

    // Getters

    /**
     * Currency DB id (from #__alfa_currencies.id, NOT the ISO code).
     * @since  1.0.0
     */
    public function getCurrencyId(): int
    {
        return $this->currencyId;
    }

    /**
     * Joomla user id, or null for guests.
     * @since  1.0.0
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Array of Joomla usergroup ids (always includes 0 as sentinel).
     *
     * @return int[]
     * @since  1.0.0
     */
    public function getUserGroups(): array
    {
        return $this->userGroups;
    }

    /**
     * Place DB id (#__alfa_places.id), or null when location is unknown.
     * @since  1.0.0
     */
    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    // Convenience helpers

    /**
     * True if this context represents a logged-in user.
     * @since  1.0.0
     */
    public function isLoggedIn(): bool
    {
        return $this->userId !== null;
    }

    /**
     * True if a specific location is known.
     * @since  1.0.0
     */
    public function hasLocation(): bool
    {
        return $this->locationId !== null;
    }

    /**
     * Deterministic cache key for this context.
     *
     * Suitable for Joomla's cache service or APCu.
     * Example: "price_ctx_c47_p85_u0-8-25"
     * @since  1.0.0
     */
    public function toCacheKey(): string
    {
        $groups = $this->userGroups;
        sort($groups);

        // userId and referenceDate MUST be in the key: the index sync runs the same
        // items through contexts that differ only by referenceDate (temporal price
        // segments), and per-user rows differ by userId. Omitting them collided
        // distinct contexts onto one memoised result.
        return sprintf(
            'price_ctx_c%d_p%d_u%s_i%d_d%s',
            $this->currencyId,
            $this->locationId ?? 0,
            implode('-', $groups),
            $this->userId ?? 0,
            $this->referenceDate ?? 'now',
        );
    }

    /**
     * Comma-separated SQL IN() list of usergroup ids.
     *
     * Example: "0,8,25"  ->  WHERE usergroup_id IN (0, 8, 25)
     * @since  1.0.0
     */
    public function getUserGroupsSql(): string
    {
        return implode(',', array_unique(array_map('intval', $this->userGroups)));
    }

    /**
     * Serialise to array (logging, APIs, passing to sub-services).
     * @since  1.0.0
     */
    public function toArray(): array
    {
        return [
            'currency_id' => $this->currencyId,
            'user_id' => $this->userId,
            'user_groups' => $this->userGroups,
            'location_id' => $this->locationId,
        ];
    }
}
