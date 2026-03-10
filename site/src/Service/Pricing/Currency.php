<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

use Joomla\CMS\Factory;
use RuntimeException;

defined('_JEXEC') or die;

/**
 * Currency Value Object
 *
 * Immutable currency representation with formatting rules.
 * Loads currency data from database and caches it.
 */
class Currency
{
    private int $id;
    private string $name;
    private string $code;
    private int $number;
    private string $symbol;
    private int $decimalPlaces;
    private string $decimalSymbol;
    private string $decimalSeparator;
    private string $thousandSeparator;
    private string $formatPattern;

    /**
     * Per-request static cache for loaded currencies.
     * Keyed by lookup type + value: "id_47", "number_978", "code_EUR".
     * Currency is immutable — safe to cache and share.
     *
     * @var array<string, self>
     */
    private static array $cache = [];

    private function __construct(
        int $id,
        string $name,
        string $code,
        int $number,
        string $symbol,
        int $decimalPlaces,
        string $decimalSymbol,
        string $decimalSeparator,
        string $thousandSeparator,
        string $formatPattern,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->code = $code;
        $this->number = $number;
        $this->symbol = $symbol;
        $this->decimalPlaces = $decimalPlaces;
        $this->decimalSymbol = $decimalSymbol;
        $this->decimalSeparator = $decimalSeparator;
        $this->thousandSeparator = $thousandSeparator;
        $this->formatPattern = $formatPattern;
    }

    /**
     * Create Currency from database row
     */
    public static function fromDatabase(object $row): self
    {
        return new self(
            (int) $row->id,
            $row->name,
            $row->code,
            (int) $row->number,
            $row->symbol,
            (int) ($row->decimal_place ?? 2),
            $row->decimal_symbol ?? '.',
            $row->decimal_separator ?? '.',
            $row->thousand_separator ?? ',',
            $row->format_pattern ?? '{number} {symbol}',
        );
    }

    /**
     * Load currency by ISO number (e.g., 978 for EUR)
     * Uses caching for performance
     */
    public static function loadByNumber(int $number): self
    {
        $cacheKey = "number_{$number}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_currencies')
            ->where($db->quoteName('number') . ' = ' . (int) $number)
            ->where($db->quoteName('state') . ' = 1');

        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            throw new RuntimeException("Currency with number {$number} not found");
        }

        $currency = self::fromDatabase($row);
        self::cacheAll($currency);

        return $currency;
    }

    public static function loadById(int $id): self
    {
        $cacheKey = "id_{$id}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_currencies')
            ->where('id = ' . (int) $id);

        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            throw new RuntimeException("Currency with id {$id} not found");
        }

        $currency = self::fromDatabase($row);
        self::cacheAll($currency);

        return $currency;
    }

    /**
     * Load currency by ISO code (e.g., 'EUR', 'USD')
     */
    public static function loadByCode(string $code): self
    {
        $cacheKey = "code_{$code}";

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_currencies')
            ->where($db->quoteName('code') . ' = ' . $db->quote($code))
            ->where($db->quoteName('state') . ' = 1');

        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row) {
            throw new RuntimeException("Currency with code {$code} not found");
        }

        $currency = self::fromDatabase($row);
        self::cacheAll($currency);

        return $currency;
    }

    /**
     * Get default currency from component configuration
     */
    public static function getDefault(): self
    {
        $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_alfa');
        $defaultNumber = (int) $params->get('default_currency', 978); // EUR
        return self::loadByNumber($defaultNumber);
    }

    /**
     * Cache a currency under ALL lookup keys (id, number, code).
     *
     * When we load by ID, we also cache by number and code — so a
     * subsequent loadByCode('EUR') hits the cache even if the first
     * load was via loadById(47).
     *
     * @param self $currency Currency to cache
     */
    private static function cacheAll(self $currency): void
    {
        self::$cache["id_{$currency->id}"] = $currency;
        self::$cache["number_{$currency->number}"] = $currency;
        self::$cache["code_{$currency->code}"] = $currency;
    }

    // Getters

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getDecimalPlaces(): int
    {
        return $this->decimalPlaces;
    }

    public function getDecimalSymbol(): string
    {
        return $this->decimalSymbol;
    }

    public function getDecimalSeparator(): string
    {
        return $this->decimalSeparator;
    }

    public function getThousandSeparator(): string
    {
        return $this->thousandSeparator;
    }

    public function getFormatPattern(): string
    {
        return $this->formatPattern;
    }

    /**
     * Format an amount according to this currency's rules
     *
     * @param float $amount Amount to format
     * @param bool $includeSymbol Whether to include currency symbol
     * @return string Formatted amount
     */
    public function format(float $amount, bool $includeSymbol = true): string
    {
        // Format the number with proper separators
        $formatted = number_format(
            $amount,
            $this->decimalPlaces,
            $this->decimalSeparator,
            $this->thousandSeparator,
        );

        if (!$includeSymbol) {
            return $formatted;
        }

        // Apply the pattern
        $result = str_replace('{number}', $formatted, $this->formatPattern);
        $result = str_replace('{symbol}', $this->symbol, $result);

        return $result;
    }

    /**
     * Check if this currency equals another
     */
    public function equals(Currency $other): bool
    {
        return $this->number === $other->number;
    }

    /**
     * Check if this is a specific currency by code
     */
    public function is(string $code): bool
    {
        return strcasecmp($this->code, $code) === 0;
    }

    /**
     * Export as array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'number' => $this->number,
            'symbol' => $this->symbol,
            'decimal_places' => $this->decimalPlaces,
            'decimal_separator' => $this->decimalSeparator,
            'thousand_separator' => $this->thousandSeparator,
            'format_pattern' => $this->formatPattern,
        ];
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->code, $this->symbol);
    }

    /**
     * Allow serialization for caching
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'number' => $this->number,
            'symbol' => $this->symbol,
            'decimalPlaces' => $this->decimalPlaces,
            'decimalSymbol' => $this->decimalSymbol,
            'decimalSeparator' => $this->decimalSeparator,
            'thousandSeparator' => $this->thousandSeparator,
            'formatPattern' => $this->formatPattern,
        ];
    }

    /**
     * Allow deserialization from cache
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->name = $data['name'];
        $this->code = $data['code'];
        $this->number = $data['number'];
        $this->symbol = $data['symbol'];
        $this->decimalPlaces = $data['decimalPlaces'];
        $this->decimalSymbol = $data['decimalSymbol'];
        $this->decimalSeparator = $data['decimalSeparator'];
        $this->thousandSeparator = $data['thousandSeparator'];
        $this->formatPattern = $data['formatPattern'];
    }
}
