<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;

defined('_JEXEC') or die;

/**
 * Price Settings Helper
 *
 * Manages price visibility settings with user group resolution and global fallback.
 * Implements static caching to minimize database queries and comprehensive error
 * handling to ensure zero runtime failures.
 *
 * Entry points: get() resolves the current user's settings, minimal() is the
 * final-price-only preset, and make() returns a PriceSettingsBuilder for
 * per-element control.
 *
 * @since  1.0.0
 */
class PriceSettings
{
    /**
     * Static cache for resolved user settings
     * Key format: 'u{userId}'
     *
     * @var array
     * @since  1.0.0
     */
    private static $userCache = [];

    /**
     * Static cache for global component settings
     *
     * @var array|null
     * @since  1.0.0
     */
    private static $globalCache = null;

    /**
     * Database field names for all price visibility settings
     * Public for builder class access
     *
     * @var array
     * @since  1.0.0
     */
    public const FIELDS = [
        'base_price_show',
        'base_price_show_label',
        'base_price_with_discounts_show',
        'base_price_with_discounts_show_label',
        'discount_amount_show',
        'discount_amount_show_label',
        'tax_amount_show',
        'tax_amount_show_label',
        'base_price_with_tax_show',
        'base_price_with_tax_show_label',
        'final_price_show',
        'final_price_show_label',
        'price_breakdown_show',
    ];

    /**
     * Element name aliases for user-friendly API
     * Maps common variations and abbreviations to internal field names
     * Public for builder class access
     *
     * @var array
     * @since  1.0.0
     */
    public const ALIASES = [
        // Base price
        'base' => 'base_price',
        'base_price' => 'base_price',
        'baseprice' => 'base_price',
        'original' => 'base_price',
        'original_price' => 'base_price',

        // Subtotal (after discounts, before tax)
        'subtotal' => 'base_price_with_discounts',
        'sub' => 'base_price_with_discounts',
        'after_discount' => 'base_price_with_discounts',
        'base_price_with_discounts' => 'base_price_with_discounts',

        // Discount
        'discount' => 'discount_amount',
        'discount_amount' => 'discount_amount',
        'savings' => 'discount_amount',
        'save' => 'discount_amount',

        // Tax
        'tax' => 'tax_amount',
        'tax_amount' => 'tax_amount',
        'vat' => 'tax_amount',

        // Base with tax
        'base_with_tax' => 'base_price_with_tax',
        'base_price_with_tax' => 'base_price_with_tax',

        // Final price
        'final' => 'final_price',
        'final_price' => 'final_price',
        'total' => 'final_price',
        'price' => 'final_price',

        // Breakdown
        'breakdown' => 'price_breakdown',
        'price_breakdown' => 'price_breakdown',
        'details' => 'price_breakdown',
        'calculation' => 'price_breakdown',
    ];

    // Primary API

    /**
     * Get price settings for specified user
     *
     * Resolution order:
     * 1. User group settings (if configured)
     * 2. Global component settings (fallback)
     *
     * Results are cached statically for the duration of the request.
     *
     * @param int|null $userId User ID (null for current user)
     *
     * @return array Complete settings array with all field values
     * @since  1.0.0
     */
    public static function get(?int $userId = null): array
    {
        $user = null;

        // Resolve user ID
        if ($userId === null) {
            try {
                $user = Factory::getApplication()->getIdentity();
                $userId = $user->id ?? 0;
            } catch (Exception $e) {
                self::log('Failed to get current user', $e);
                $userId = 0;
            }
        }

        // Check cache
        $cacheKey = 'u' . $userId;
        if (isset(self::$userCache[$cacheKey])) {
            return self::$userCache[$cacheKey];
        }

        // Resolve from user groups: the visitor as already loaded, or the
        // account the caller named.
        $settings = self::resolveUserGroup(customer: $user ?? new User(identifier: (int) $userId));

        // Cache and return
        self::$userCache[$cacheKey] = $settings;
        return $settings;
    }

    /**
     * Get global component settings
     *
     * Loads from component configuration and caches for request duration.
     *
     * @return array Global settings array
     * @since  1.0.0
     */
    private static function global(): array
    {
        if (self::$globalCache !== null) {
            return self::$globalCache;
        }

        try {
            $params = ComponentHelper::getParams('com_alfa');
            $settings = [];

            foreach (self::FIELDS as $field) {
                $settings[$field] = (int) $params->get($field, 1);
            }

            self::$globalCache = $settings;
            return $settings;
        } catch (Exception $e) {
            self::log('Failed to load global settings', $e);
            return self::defaults();
        }
    }

    // Preset methods

    /**
     * Minimal preset configuration
     *
     * Shows only the final price without label.
     * Suitable for compact displays such as sidebar widgets or product cards.
     *
     * @return array Settings array
     * @since  1.0.0
     */
    public static function minimal(): array
    {
        $settings = self::hideAll();
        $settings['final_price_show'] = 1;
        $settings['final_price_show_label'] = 0;
        return $settings;
    }

    // Builder pattern

    /**
     * Create fluent settings builder
     *
     * Provides chainable API for fine-grained control over price visibility.
     *
     * @return PriceSettingsBuilder Builder instance
     * @since  1.0.0
     */
    public static function make(): PriceSettingsBuilder
    {
        return new PriceSettingsBuilder();
    }

    // Internal resolution methods

    /**
     * Resolve settings from user group configuration
     *
     * Queries user group price settings table and merges with global settings.
     * A group's settings reach every customer who holds the group: its own
     * members, the members of every group under it and, for the guest group
     * and the groups above it, a visitor with no account. Among several such
     * groups with settings enabled, the NEAREST decides: the deepest in the
     * group tree (the customer's own group before its parent, Public last),
     * and between two at the same depth, in unrelated branches, the higher id.
     * Falls back to global settings on any failure.
     *
     * @param User $customer The customer whose groups decide.
     *
     * @return array Resolved settings array
     * @since  1.0.0
     */
    private static function resolveUserGroup(User $customer): array
    {
        try {
            $groups = PriceContext::customerGroups(customer: $customer);

            if (empty($groups)) {
                return self::global();
            }

            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName(['usergroup_id', 'prices_display']))
                ->from('#__alfa_usergroups')
                ->whereIn($db->quoteName('usergroup_id'), $groups)
                ->where('prices_enable = 1')
                ->order([$db->quoteName('usergroup_id'), $db->quoteName('id')]);
            $displays = $db->setQuery($query)->loadAssocList('usergroup_id', 'prices_display') ?: [];

            if ($displays === []) {
                return self::global();
            }

            // The nearest group with settings of its own: the deepest in the tree,
            // then the higher id. The tree is read only when there is a choice.
            $nearest = array_key_last($displays);

            if (\count($displays) > 1) {
                $depth = array_map('count', PriceContext::groupLineages(groupIds: array_keys($displays)));
                $rank = static fn (int $groupId): array => [$depth[$groupId] ?? 0, $groupId];
                $nearest = array_reduce(
                    array_keys($displays),
                    static fn (?int $best, int $groupId): int => $best === null || $rank($groupId) > $rank($best) ? $groupId : $best,
                );
            }

            $pricesDisplay = $displays[$nearest];

            if (!$pricesDisplay) {
                return self::global();
            }

            $groupSettings = json_decode($pricesDisplay, true);

            if (!is_array($groupSettings)) {
                return self::global();
            }

            return self::merge($groupSettings, self::global());
        } catch (Exception $e) {
            self::log('Failed to resolve user group settings', $e);
            return self::global();
        }
    }

    /**
     * Merge group settings with global fallback
     *
     * Group values of 0 or 1 are used directly; all other values
     * fall back to global configuration.
     *
     * @param array $group User group settings
     * @param array $global Global settings
     *
     * @return array Merged settings array
     * @since  1.0.0
     */
    private static function merge(array $group, array $global): array
    {
        $merged = [];

        foreach (self::FIELDS as $field) {
            $value = isset($group[$field]) ? (int) $group[$field] : -1;
            $merged[$field] = ($value === 0 || $value === 1) ? $value : ($global[$field] ?? 1);
        }

        return $merged;
    }

    // Utility methods

    /**
     * Create settings array with all elements hidden
     *
     * @return array Settings array with all values set to 0
     * @since  1.0.0
     */
    private static function hideAll(): array
    {
        $settings = [];
        foreach (self::FIELDS as $field) {
            $settings[$field] = 0;
        }
        return $settings;
    }

    /**
     * Get default settings (all visible)
     *
     * @return array Default settings array
     * @since  1.0.0
     */
    private static function defaults(): array
    {
        $settings = [];
        foreach (self::FIELDS as $field) {
            $settings[$field] = 1;
        }
        return $settings;
    }

    /**
     * Log error to Joomla logging system
     *
     * Errors are logged but never displayed to end users to ensure
     * graceful degradation.
     *
     * @param string $message Error description
     * @param Exception $exception Exception object
     *
     * @since  1.0.0
     */
    private static function log(string $message, Exception $exception): void
    {
        try {
            Log::add(
                $message . ': ' . $exception->getMessage(),
                Log::WARNING,
                'com_alfa.prices',
            );
        } catch (Exception $e) {
            // Logging failed - continue silently
        }
    }
}
