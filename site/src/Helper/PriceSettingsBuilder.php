<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * Price Settings Builder
 *
 * Provides fluent, chainable API for constructing price visibility settings
 * with fine-grained control. Obtained from PriceSettings::make().
 *
 * Each figure has one name, the stem of its field in PriceSettings::FIELDS:
 * base_price, base_price_with_discounts, discount_amount, tax_amount,
 * base_price_with_tax, final_price and price_breakdown. Any other name is
 * refused with an InvalidArgumentException that lists them.
 *
 * @since  1.0.0
 */
class PriceSettingsBuilder
{
    /**
     * Settings being constructed
     *
     * @var array
     * @since  1.0.0
     */
    private $settings = [];

    /**
     * Constructor
     *
     * Initializes settings with all elements hidden.
     *
     * @since  1.0.0
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
     * @param string $element Element name (one of the names in the class description)
     * @param bool $withLabel Show label (default: true)
     *
     * @return self Builder instance for chaining
     *
     * @throws InvalidArgumentException When the name is not one of the elements.
     * @since  1.0.0
     */
    public function show(string $element, bool $withLabel = true): self
    {
        $element = self::element($element);

        $this->settings[$element . '_show'] = 1;

        // The breakdown has no label of its own.
        if (\array_key_exists($element . '_show_label', $this->settings)) {
            $this->settings[$element . '_show_label'] = $withLabel ? 1 : 0;
        }

        return $this;
    }

    /**
     * Hide specified element
     *
     * @param string $element Element name (one of the names in the class description)
     *
     * @return self Builder instance for chaining
     *
     * @throws InvalidArgumentException When the name is not one of the elements.
     * @since  1.0.0
     */
    public function hide(string $element): self
    {
        $element = self::element($element);

        $this->settings[$element . '_show'] = 0;

        if (\array_key_exists($element . '_show_label', $this->settings)) {
            $this->settings[$element . '_show_label'] = 0;
        }

        return $this;
    }

    /**
     * Remove all label visibility
     *
     * @return self Builder instance for chaining
     * @since  1.0.0
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
     * @since  1.0.0
     */
    public function get(): array
    {
        return $this->settings;
    }

    /**
     * The element names the builder takes: the stem of each _show field of PriceSettings::FIELDS.
     *
     * @return list<string>
     *
     * @since  2.3.0
     */
    private static function elements(): array
    {
        $elements = [];

        foreach (PriceSettings::FIELDS as $field) {
            if (str_ends_with($field, '_show')) {
                $elements[] = substr($field, 0, -\strlen('_show'));
            }
        }

        return $elements;
    }

    /**
     * The element named, exactly as written; any other name is refused.
     *
     * @param string $element Element name
     *
     * @throws InvalidArgumentException When the name is not one of the elements.
     * @since  2.3.0
     */
    private static function element(string $element): string
    {
        $elements = self::elements();

        if (!\in_array($element, $elements, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown price element "%s". The price builder takes one name per element: %s. See the Manual, Services: Pricing, "Which figures are shown: PriceSettings".',
                $element,
                implode(', ', $elements),
            ));
        }

        return $element;
    }
}
