<?php

/**
 * Price Settings Helper - Production Ready
 *
 * @package    Com_Alfa
 * @subpackage Site
 * @since      1.0.1
 * @author     Agamemnon Fakas
 * @copyright  2025 Easylogic CO LP
 * @license    GNU General Public License version 2 or later
 *
 * FEATURES:
 * - Zero-error guarantee with comprehensive validation
 * - Static caching for optimal performance (1 query/request max)
 * - Fluent API with method chaining support
 * - Smart element name matching with alias resolution
 * - Professional error handling with logging
 * - Three-tier API (beginner/intermediate/advanced)
 *
 * ============================================================================
 * USAGE EXAMPLES
 * ============================================================================
 *
 * BASIC USAGE (Beginner-Friendly):
 * ```php
 * use Alfa\Component\Alfa\Site\Helper\PriceSettings;
 *
 * // Auto-resolved by user group (most common)
 * $settings = PriceSettings::get();
 *
 * // Preset configurations
 * $settings = PriceSettings::minimal();  // Final price only, no label
 * $settings = PriceSettings::compact();  // All prices, no labels
 * $settings = PriceSettings::full();     // Everything with labels
 * ```
 *
 * FILTERING (Simple Control):
 * ```php
 * // Show only specific elements (hide everything else)
 * $settings = PriceSettings::only('final');
 * $settings = PriceSettings::only('base', 'discount', 'final');
 * $settings = PriceSettings::only('base', 'subtotal', 'tax', 'total');
 *
 * // Hide specific elements (show everything else)
 * $settings = PriceSettings::except('tax');
 * $settings = PriceSettings::except('base', 'tax');
 * $settings = PriceSettings::except('subtotal', 'base_with_tax');
 * ```
 *
 * BUILDER PATTERN (Advanced Control):
 * ```php
 * // Fine-grained control with chaining
 * $settings = PriceSettings::make()
 *     ->show('base')              // Show with label
 *     ->show('discount', false)   // Show without label
 *     ->show('final', true)       // Show with label
 *     ->hide('tax')               // Hide completely
 *     ->get();
 *
 * // Show everything without labels
 * $settings = PriceSettings::make()
 *     ->show('base', false)
 *     ->show('discount', false)
 *     ->show('subtotal', false)
 *     ->show('tax', false)
 *     ->show('final', false)
 *     ->get();
 * ```
 *
 * TEMPLATE OVERRIDES:
 * ```php
 * // In template file: templates/yourtemplate/html/com_alfa/items/default.php
 *
 * // Example 1: Hide tax on this page only
 * $priceSettings = PriceSettings::except('tax');
 *
 * // Example 2: Show only final price for sale items
 * $priceSettings = PriceSettings::minimal();
 *
 * // Example 3: Custom configuration
 * $priceSettings = PriceSettings::make()
 *     ->show('base')
 *     ->show('discount', false)
 *     ->show('final')
 *     ->get();
 *
 * // Use in layout
 * foreach ($this->items as $item) {
 *     echo LayoutHelper::render('price', [
 *         'item' => $item,
 *         'settings' => $priceSettings,
 *     ]);
 * }
 * ```
 *
 * MODULE USAGE:
 * ```php
 * // In module: mod_alfa_products/tmpl/default.php
 *
 * // Get settings once for all items
 * $settings = PriceSettings::compact(); // or any method
 *
 * foreach ($items as $item) {
 *     echo LayoutHelper::render('price', [
 *         'item' => $item,
 *         'settings' => $settings,
 *     ]);
 * }
 * ```
 *
 * CONDITIONAL SETTINGS:
 * ```php
 * // Different settings based on context
 *
 * // Example 1: By device
 * $isMobile = $app->client->mobile;
 * $settings = $isMobile ? PriceSettings::minimal() : PriceSettings::compact();
 *
 * // Example 2: By category
 * if ($categoryId == 5) {
 *     $settings = PriceSettings::full();  // Electronics - show all details
 * } else {
 *     $settings = PriceSettings::compact();  // Others - compact view
 * }
 *
 * // Example 3: By user group
 * $user = Factory::getApplication()->getIdentity();
 * if (in_array(8, $user->groups)) {
 *     $settings = PriceSettings::full();  // Wholesale - show breakdown
 * } else {
 *     $settings = PriceSettings::only('final');  // Retail - just price
 * }
 *
 * // Example 4: By page type
 * $view = $app->input->get('view');
 * $settings = ($view == 'item')
 *     ? PriceSettings::full()      // Detail page - full breakdown
 *     : PriceSettings::compact();  // List page - compact
 * ```
 *
 * ELEMENT ALIASES (All These Work):
 * ```php
 * // Final price - many ways
 * PriceSettings::only('final');
 * PriceSettings::only('final_price');
 * PriceSettings::only('total');
 * PriceSettings::only('price');
 *
 * // Discount - aliases
 * PriceSettings::only('discount');
 * PriceSettings::only('savings');
 *
 * // Tax - variations
 * PriceSettings::only('tax');
 * PriceSettings::only('vat');
 *
 * // Base price - aliases
 * PriceSettings::only('base');
 * PriceSettings::only('original');
 *
 * // Subtotal - variations
 * PriceSettings::only('subtotal');
 * PriceSettings::only('sub');
 * ```
 *
 * ERROR HANDLING (Automatic):
 * ```php
 * // Invalid element names are silently ignored - NO ERRORS!
 * $settings = PriceSettings::only('final', 'xyz', 'abc');
 * // Result: Shows 'final', ignores 'xyz' and 'abc'
 *
 * // Typos are OK
 * $settings = PriceSettings::only('tottal'); // Ignored, no crash
 *
 * // Database failures automatically fallback to global
 * $settings = PriceSettings::get(); // Always works, never throws
 * ```
 *
 * PERFORMANCE:
 * ```php
 * // Static cache - called 1000 times = 1 database query!
 * for ($i = 0; $i < 1000; $i++) {
 *     $settings = PriceSettings::get(); // Uses cache after first call
 * }
 *
 * // Get settings once, use many times
 * $settings = PriceSettings::compact();
 * foreach ($items as $item) {
 *     // Zero queries here - settings already resolved
 *     echo LayoutHelper::render('price', [
 *         'item' => $item,
 *         'settings' => $settings,
 *     ]);
 * }
 * ```
 *
 * COMMON PATTERNS:
 * ```php
 * // Pattern 1: Sidebar widget (minimal)
 * $settings = PriceSettings::minimal();
 *
 * // Pattern 2: Product grid (compact)
 * $settings = PriceSettings::compact();
 *
 * // Pattern 3: Product details (full)
 * $settings = PriceSettings::full();
 *
 * // Pattern 4: Hide tax globally
 * $settings = PriceSettings::except('tax');
 *
 * // Pattern 5: Show price only (no breakdown)
 * $settings = PriceSettings::only('final');
 *
 * // Pattern 6: Show base and final (compare prices)
 * $settings = PriceSettings::only('base', 'final');
 *
 * // Pattern 7: Show discount highlight
 * $settings = PriceSettings::only('base', 'discount', 'final');
 * ```
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Exception;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * Price Settings Helper
 *
 * Manages price visibility settings with user group resolution and global fallback.
 * Implements static caching to minimize database queries and comprehensive error
 * handling to ensure zero runtime failures.
 *
 * @since  1.0.1
 */
class PriceSettings
{
    /**
     * Static cache for resolved user settings
     * Key format: 'u{userId}'
     *
     * @var array
     * @since  1.0.1
     */
    private static $userCache = [];

    /**
     * Static cache for global component settings
     *
     * @var array|null
     * @since  1.0.1
     */
    private static $globalCache = null;

    /**
     * Database field names for all price visibility settings
     * Public for builder class access
     *
     * @var array
     * @since  1.0.1
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
     * @since  1.0.1
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

    // ========================================================================
    // PRIMARY API
    // ========================================================================

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
     * @since   1.0.1
     */
    public static function get(?int $userId = null): array
    {
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

        // Guest user - use global settings
        if ($userId <= 0) {
            $settings = self::global();
            self::$userCache[$cacheKey] = $settings;
            return $settings;
        }

        // Resolve from user groups
        $settings = self::resolveUserGroup($userId);

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
     * @since   1.0.1
     */
    public static function global(): array
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

    // ========================================================================
    // PRESET METHODS
    // ========================================================================

    /**
     * Minimal preset configuration
     *
     * Shows only the final price without label.
     * Suitable for compact displays such as sidebar widgets or product cards.
     *
     * @return array Settings array
     * @since   1.0.1
     */
    public static function minimal(): array
    {
        $settings = self::hideAll();
        $settings['final_price_show'] = 1;
        $settings['final_price_show_label'] = 0;
        return $settings;
    }

    /**
     * Compact preset configuration
     *
     * Uses current user's settings but removes all labels.
     * Suitable for product listings and grid views.
     *
     * @return array Settings array
     * @since   1.0.1
     */
    public static function compact(): array
    {
        return self::removeLabels(self::get());
    }

    /**
     * Full preset configuration
     *
     * Shows all price elements with labels.
     * Suitable for detailed product pages.
     *
     * @return array Settings array
     * @since   1.0.1
     */
    public static function full(): array
    {
        $settings = [];
        foreach (self::FIELDS as $field) {
            $settings[$field] = 1;
        }
        return $settings;
    }

    // ========================================================================
    // SIMPLE FILTER METHODS
    // ========================================================================

    /**
     * Show only specified elements (hide all others)
     *
     * Accepts element names with smart matching - aliases and common
     * variations are automatically resolved. Invalid element names
     * are silently ignored to prevent errors.
     *
     * @param string ...$elements Element names to show
     *
     * @return array Settings array
     * @since   1.0.1
     */
    public static function only(string ...$elements): array
    {
        $settings = self::hideAll();

        foreach ($elements as $element) {
            $field = self::resolve($element);
            if ($field !== null) {
                $settings[$field . '_show'] = 1;
                $settings[$field . '_show_label'] = 1;
            }
        }

        return $settings;
    }

    /**
     * Hide specified elements (show all others)
     *
     * Starts with current user's settings and hides specified elements.
     * Invalid element names are silently ignored.
     *
     * @param string ...$elements Element names to hide
     *
     * @return array Settings array
     * @since   1.0.1
     */
    public static function except(string ...$elements): array
    {
        $settings = self::get();

        foreach ($elements as $element) {
            $field = self::resolve($element);
            if ($field !== null) {
                $settings[$field . '_show'] = 0;
                $settings[$field . '_show_label'] = 0;
            }
        }

        return $settings;
    }

    // ========================================================================
    // BUILDER PATTERN
    // ========================================================================

    /**
     * Create fluent settings builder
     *
     * Provides chainable API for fine-grained control over price visibility.
     *
     * @return PriceSettingsBuilder Builder instance
     * @since   1.0.1
     */
    public static function make(): PriceSettingsBuilder
    {
        return new PriceSettingsBuilder();
    }

    // ========================================================================
    // INTERNAL RESOLUTION METHODS
    // ========================================================================

    /**
     * Resolve element name to internal field name
     *
     * Performs case-insensitive matching with normalization of spaces,
     * hyphens, and underscores. Returns null for unrecognized names.
     *
     * @param string $element User-provided element name
     *
     * @return string|null Internal field name or null if not found
     * @since   1.0.1
     */
    private static function resolve(string $element): ?string
    {
        // Normalize: lowercase, remove separators
        $normalized = strtolower(trim($element));
        $normalized = str_replace([' ', '-', '_'], '', $normalized);

        // Match against aliases
        foreach (self::ALIASES as $alias => $field) {
            if (str_replace('_', '', $alias) === $normalized) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Resolve settings from user group configuration
     *
     * Queries user group price settings table and merges with global settings.
     * Falls back to global settings on any failure.
     *
     * @param int $userId User ID
     *
     * @return array Resolved settings array
     * @since   1.0.1
     */
    private static function resolveUserGroup(int $userId): array
    {
        try {
            $groups = Access::getGroupsByUser($userId, false);

            if (empty($groups)) {
                return self::global();
            }

            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('prices_display')
                ->from('#__alfa_usergroups')
                ->where('usergroup_id IN (' . implode(',', array_map('intval', $groups)) . ')')
                ->order('usergroup_id DESC')
                ->setLimit(1);

            $db->setQuery($query);
            $params = $db->loadResult();

            if (!$params) {
                return self::global();
            }

            $groupSettings = json_decode($params, true);
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
     * @since   1.0.1
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

    // ========================================================================
    // UTILITY METHODS
    // ========================================================================

    /**
     * Create settings array with all elements hidden
     *
     * @return array Settings array with all values set to 0
     * @since   1.0.1
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
     * Remove all label visibility flags from settings
     *
     * @param array $settings Settings array to modify
     *
     * @return array Settings array with all labels hidden
     * @since   1.0.1
     */
    private static function removeLabels(array $settings): array
    {
        foreach (self::FIELDS as $field) {
            if (strpos($field, '_show_label') !== false) {
                $settings[$field] = 0;
            }
        }
        return $settings;
    }

    /**
     * Get default settings (all visible)
     *
     * @return array Default settings array
     * @since   1.0.1
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
     * @since   1.0.1
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

    /**
     * Clear static cache
     *
     * Primarily for testing purposes; cache is automatically
     * cleared between requests.
     *
     * @since   1.0.1
     */
    public static function clearCache(): void
    {
        self::$userCache = [];
        self::$globalCache = null;
    }
}

/**
 * Price Settings Builder
 *
 * Provides fluent, chainable API for constructing price visibility settings
 * with fine-grained control. Invalid element names are silently ignored
 * to prevent chain breakage.
 *
 * @since  1.0.1
 */
class PriceSettingsBuilder
{
    /**
     * Settings being constructed
     *
     * @var array
     * @since  1.0.1
     */
    private $settings = [];

    /**
     * Constructor
     *
     * Initializes settings with all elements hidden.
     *
     * @since  1.0.1
     */
    public function __construct()
    {
        foreach (PriceSettings::FIELDS as $field) {
            $this->settings[$field] = 0;
        }
    }

    /**
     * Show specified element
     *
     * Invalid element names are silently ignored to maintain chain integrity.
     *
     * @param string $element Element name
     * @param bool $withLabel Show label (default: true)
     *
     * @return self Builder instance for chaining
     * @since   1.0.1
     */
    public function show(string $element, bool $withLabel = true): self
    {
        $field = $this->resolveField($element);

        if ($field !== null) {
            $this->settings[$field . '_show'] = 1;
            $this->settings[$field . '_show_label'] = $withLabel ? 1 : 0;
        }

        return $this;
    }

    /**
     * Hide specified element
     *
     * Invalid element names are silently ignored.
     *
     * @param string $element Element name
     *
     * @return self Builder instance for chaining
     * @since   1.0.1
     */
    public function hide(string $element): self
    {
        $field = $this->resolveField($element);

        if ($field !== null) {
            $this->settings[$field . '_show'] = 0;
            $this->settings[$field . '_show_label'] = 0;
        }

        return $this;
    }

    /**
     * Remove all label visibility
     *
     * @return self Builder instance for chaining
     * @since   1.0.1
     */
    public function withoutLabels(): self
    {
        foreach (PriceSettings::FIELDS as $field) {
            if (strpos($field, '_show_label') !== false) {
                $this->settings[$field] = 0;
            }
        }

        return $this;
    }

    /**
     * Build and return final settings array
     *
     * @return array Complete settings array
     * @since   1.0.1
     */
    public function get(): array
    {
        return $this->settings;
    }

    /**
     * Resolve element name to internal field name
     *
     * Duplicates PriceSettings::resolve() logic for builder independence.
     *
     * @param string $element Element name
     *
     * @return string|null Field name or null if not found
     * @since   1.0.1
     */
    private function resolveField(string $element): ?string
    {
        $normalized = strtolower(trim($element));
        $normalized = str_replace([' ', '-', '_'], '', $normalized);

        foreach (PriceSettings::ALIASES as $alias => $field) {
            if (str_replace('_', '', $alias) === $normalized) {
                return $field;
            }
        }

        return null;
    }
}
