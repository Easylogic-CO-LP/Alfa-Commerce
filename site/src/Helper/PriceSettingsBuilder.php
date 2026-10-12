<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

/**
 * Price Settings Builder
 *
 * Provides fluent, chainable API for constructing price visibility settings
 * with fine-grained control. Invalid element names are silently ignored
 * to prevent chain breakage. Obtained from PriceSettings::make().
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
     * Invalid element names are silently ignored to maintain chain integrity.
     *
     * @param string $element Element name
     * @param bool $withLabel Show label (default: true)
     *
     * @return self Builder instance for chaining
     * @since  1.0.0
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
     * @since  1.0.0
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
     * Resolve element name to internal field name
     *
     * Duplicates PriceSettings::resolve() logic for builder independence.
     *
     * @param string $element Element name
     *
     * @return string|null Field name or null if not found
     * @since  1.0.0
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
