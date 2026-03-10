<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Com_Alfa
 * @subpackage Site.Service.Pricing
 * @since      1.0.0
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use RuntimeException;

/**
 * PriceContext — Immutable value object carrying the visitor's pricing dimensions.
 *
 * DIMENSIONS
 * ----------
 * Four orthogonal dimensions fully determine which prices, discounts, and taxes
 * apply to a given visitor:
 *
 *   currencyId   — DB id from #__alfa_currencies (0 = use component default)
 *   userId       — Joomla user id (null = guest)
 *   userGroups   — Array of Joomla usergroup ids. Always contains 0 ("any group"
 *                  sentinel) so public prices are always matched.
 *   locationId   — Place id from #__alfa_places (null = unknown / any place)
 *
 * NOTE: currencyId stores the DB PRIMARY KEY of #__alfa_currencies, not the
 * ISO numeric code (e.g. 978 for EUR). PriceComputationEngine uses
 * Currency::loadById() accordingly.
 *
 * IMMUTABILITY
 * ------------
 * PriceContext is a value object. All with*() methods return a NEW instance
 * so callers can branch contexts without mutation side-effects:
 *
 *   $base    = PriceContext::fromSession();
 *   $gbp     = $base->withCurrency(47);          // new instance, GBP
 *   $base    unchanged
 *
 * FLUENT API
 * ----------
 *   PriceContext::fromSession()              — live visitor context
 *   PriceContext::forIndex($c, $p, $ug)     — one index combination slot
 *
 *   ->withCurrency(int $id)                 — override currency
 *   ->withLocation(?int $placeId)           — override place
 *   ->withUserGroups(array $groups)         — override groups (0 auto-added)
 *   ->withUserId(?int $userId)              — override user
 *   ->withoutUser()                         — make anonymous (index contexts)
 *
 * SESSION KEYS (set by location-detection / currency-selector middleware)
 * -----------------------------------------------------------------------
 *   alfa.currency_id  — int   DB id of visitor's chosen/detected currency
 *   alfa.location_id  — int   DB id of visitor's place (#__alfa_places.id)
 *
 * @since 1.0.0
 */
class PriceContext
{
    // =========================================================================
    // Properties (all private — access only through getters and with*() methods)
    // =========================================================================

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

    // =========================================================================
    // Constructor (private — use factory methods)
    // =========================================================================

    /**
     * @param int[] $userGroups
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

    // =========================================================================
    // Factory methods
    // =========================================================================

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
     */
    public static function fromSession(): self
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $session = $app->getSession();

        // ── Currency ──────────────────────────────────────────────────────────
        $currencyId = (int) $session->get('alfa.currency_id', 0);
        if ($currencyId <= 0) {
            try {
                $currencyId = Currency::getDefault()->getId();
            } catch (RuntimeException $e) {
                $currencyId = 0;
            }
        }

        // ── Usergroups ────────────────────────────────────────────────────────
        // 0 is always included as the "any group" sentinel.
        // getAuthorisedGroups() returns group ids as strings — cast to int.
        $userGroups = [0];
        if ($user->id > 0) {
            foreach ($user->getAuthorisedGroups() as $gid) {
                $userGroups[] = (int) $gid;
            }
        }

        // ── Location ──────────────────────────────────────────────────────────
        $locationId = (int) $session->get('alfa.location_id', 0) ?: null;

        return new self(
            $currencyId,
            $user->id > 0 ? $user->id : null,
            array_unique($userGroups),
            $locationId,
        );
    }

    /**
     * Build a context for one specific index combination slot.
     *
     * Called by PriceIndexSyncService when computing prices for every
     * (currency × place × usergroup) combination in the price index.
     *
     * userGroups is set to [0, $usergroupId] so the computation picks up
     * both "public" prices/discounts/taxes AND those for this specific group.
     * When $usergroupId = 0 (public slot), only [0] is used.
     *
     * @param int $currencyId DB id from #__alfa_currencies; 0 = default
     * @param int $placeId DB id from #__alfa_places;     0 = any
     * @param int $usergroupId Joomla usergroup id;            0 = public
     */
    public static function forIndex(int $currencyId, int $placeId, int $usergroupId): self
    {
        $groups = [0];
        if ($usergroupId > 0) {
            $groups[] = $usergroupId;
        }

        return new self(
            $currencyId,
            null,                                   // Index contexts are always anonymous
            $groups,
            $placeId > 0 ? $placeId : null,
        );
    }

    // =========================================================================
    // Fluent immutable API — each method returns a NEW instance
    // =========================================================================

    /**
     * Return a new context with a different currency.
     *
     * Usage:
     *   $gbpContext = PriceContext::fromSession()->withCurrency(47);
     *
     * @param int $currencyId DB id from #__alfa_currencies
     */
    public function withCurrency(int $currencyId): self
    {
        return new self($currencyId, $this->userId, $this->userGroups, $this->locationId);
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
     */
    public function withUserGroups(array $groups): self
    {
        return new self($this->currencyId, $this->userId, $groups, $this->locationId);
    }

    /**
     * Return a new context with a different user id.
     *
     * @param int|null $userId Joomla user id, or null for guest
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
     * Return a new anonymous context (no user, keeps groups and location).
     *
     * Useful for index contexts and public price comparisons.
     *
     * Usage:
     *   $anonCtx = $userCtx->withoutUser();
     */
    public function withoutUser(): self
    {
        return new self($this->currencyId, null, $this->userGroups, $this->locationId);
    }

    // =========================================================================
    // Getters
    // =========================================================================

    /**
     * Currency DB id (from #__alfa_currencies.id, NOT the ISO code).
     */
    public function getCurrencyId(): int
    {
        return $this->currencyId;
    }

    /**
     * Joomla user id, or null for guests.
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Array of Joomla usergroup ids (always includes 0 as sentinel).
     *
     * @return int[]
     */
    public function getUserGroups(): array
    {
        return $this->userGroups;
    }

    /**
     * Place DB id (#__alfa_places.id), or null when location is unknown.
     */
    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    // =========================================================================
    // Convenience helpers
    // =========================================================================

    /**
     * True if this context represents a logged-in user.
     */
    public function isLoggedIn(): bool
    {
        return $this->userId !== null;
    }

    /**
     * True if a specific location is known.
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
     */
    public function toCacheKey(): string
    {
        $groups = $this->userGroups;
        sort($groups);

        return sprintf(
            'price_ctx_c%d_p%d_u%s',
            $this->currencyId,
            $this->locationId ?? 0,
            implode('-', $groups),
        );
    }

    /**
     * Comma-separated SQL IN() list of usergroup ids.
     *
     * Example: "0,8,25"  →  WHERE usergroup_id IN (0, 8, 25)
     */
    public function getUserGroupsSql(): string
    {
        return implode(',', array_unique(array_map('intval', $this->userGroups)));
    }

    /**
     * Serialise to array (logging, APIs, passing to sub-services).
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
