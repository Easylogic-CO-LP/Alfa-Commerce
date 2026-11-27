<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Provider;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The provider of each kind, made when a tab of that kind first asks for it: a module of categories never loads the
 * products provider, nor the listing model behind it.
 *
 * @since  2.3.0
 */
final class Providers
{
    public const KIND_PRODUCTS = 'products';
    public const KIND_CATEGORIES = 'categories';
    public const KIND_MANUFACTURERS = 'manufacturers';

    /**
     * @var string[] The kinds a tab may show, the default first.
     * @since  2.3.0
     */
    public const KINDS = [self::KIND_PRODUCTS, self::KIND_CATEGORIES, self::KIND_MANUFACTURERS];

    /**
     * @var array<string, ProviderInterface> The providers made this request, by kind.
     * @since  2.3.0
     */
    private static array $made = [];

    /**
     * A stored kind among the kinds; anything else is products.
     *
     * @param mixed $kind The stored value.
     *
     * @since  2.3.0
     */
    public static function kind(mixed $kind): string
    {
        return \is_string($kind) && \in_array($kind, self::KINDS, true) ? $kind : self::KIND_PRODUCTS;
    }

    /**
     * The provider of a kind.
     *
     * @param string $kind One of KINDS.
     *
     * @since  2.3.0
     */
    public static function of(string $kind): ProviderInterface
    {
        return self::$made[$kind] ??= match ($kind) {
            self::KIND_CATEGORIES => new CategoriesProvider(),
            self::KIND_MANUFACTURERS => new ManufacturersProvider(),
            default => new ProductsProvider(),
        };
    }

    /**
     * The kinds whose provider was made this request.
     *
     * @return string[]
     *
     * @since  2.3.0
     */
    public static function made(): array
    {
        return array_keys(self::$made);
    }

    /**
     * Whole positive numbers from a stored list, each once, in the stored order.
     *
     * @param mixed $value The stored value.
     *
     * @return int[]
     *
     * @since  2.3.0
     */
    public static function ids(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, (array) $value),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * A stored value among its choices; a value the form never offers reads as the first choice.
     *
     * @param mixed $value The stored value.
     * @param string[] $choices The choices, the default first.
     *
     * @since  2.3.0
     */
    public static function choice(mixed $value, array $choices): string
    {
        return \is_string($value) && \in_array($value, $choices, true) ? $value : $choices[0];
    }

    /**
     * A stored number within its bounds.
     *
     * @param mixed $value The stored value.
     * @param int $min The smallest number kept.
     * @param int $max The largest number kept.
     * @param int $default The number of a row that stores none.
     *
     * @since  2.3.0
     */
    public static function bounded(mixed $value, int $min, int $max, int $default): int
    {
        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }
}
