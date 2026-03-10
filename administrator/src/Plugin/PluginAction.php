<?php

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Plugin
 * @version     3.5.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Plugin Action Definition
 *
 * Defines an action button for the admin order edit page.
 * Supports fluent API and array construction.
 *
 * Path: administrator/components/com_alfa/src/Plugin/PluginAction.php
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

defined('_JEXEC') or die;

class PluginAction
{
    /** @var string Unique action identifier */
    public string $id;

    /** @var string Button label */
    public string $label;

    /** @var string Icon name (without icon- prefix) */
    public string $icon;

    /** @var string Bootstrap button class */
    public string $class;

    /** @var bool Show confirmation dialog before executing? */
    public bool $requires_confirmation;

    /** @var ?string Confirmation dialog message */
    public ?string $confirmation_message;

    /** @var bool Is the action currently enabled? */
    public bool $enabled;

    /** @var ?string Tooltip text on hover */
    public ?string $tooltip;

    /** @var array Arbitrary metadata (passed to layouts and JS) */
    public array $metadata;

    /** @var int Sort priority (higher = appears first) */
    public int $priority;

    /** @var ?string Custom button layout override */
    public ?string $button_layout;

    /** @var ?string Response layout (modal after execution) */
    public ?string $response_layout;

    /** @var ?string Modal popup title */
    public ?string $modal_title;

    /** @var string Modal size: sm, md, lg, xl */
    public string $modal_size;

    /**
     * Constructor.
     *
     * @param array $config All properties as key-value pairs
     *
     * @since   3.0.0
     */
    public function __construct(array $config = [])
    {
        $this->id = $config['id'] ?? '';
        $this->label = $config['label'] ?? 'Action';
        $this->icon = $config['icon'] ?? 'cog';
        $this->class = $config['class'] ?? 'btn-primary';
        $this->requires_confirmation = $config['requires_confirmation'] ?? false;
        $this->confirmation_message = $config['confirmation_message'] ?? null;
        $this->enabled = $config['enabled'] ?? true;
        $this->tooltip = $config['tooltip'] ?? null;
        $this->metadata = $config['metadata'] ?? [];
        $this->priority = $config['priority'] ?? 100;
        $this->button_layout = $config['button_layout'] ?? null;
        $this->response_layout = $config['response_layout'] ?? null;
        $this->modal_title = $config['modal_title'] ?? null;
        $this->modal_size = $config['modal_size'] ?? 'md';
    }

    // =========================================================================
    //  FLUENT SETTERS - Chain with $event->add() for clean API
    // =========================================================================

    /**
     * Set the icon name (e.g. 'checkmark', 'truck', 'eye').
     *
     * @param string $icon Icon name
     * @return static For chaining
     * @since   3.5.0
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Set the Bootstrap button CSS class (e.g. 'btn-success').
     *
     * @param string $class CSS class
     * @return static For chaining
     * @since   3.5.0
     */
    public function css(string $class): static
    {
        $this->class = $class;

        return $this;
    }

    /**
     * Enable confirmation dialog before executing.
     *
     * @param string $message Confirmation dialog text
     * @return static For chaining
     * @since   3.5.0
     */
    public function confirm(string $message): static
    {
        $this->requires_confirmation = true;
        $this->confirmation_message = $message;

        return $this;
    }

    /**
     * Set tooltip text shown on hover.
     *
     * @param string $text Tooltip text
     * @return static For chaining
     * @since   3.5.0
     */
    public function tooltip(string $text): static
    {
        $this->tooltip = $text;

        return $this;
    }

    /**
     * Set sort priority (higher = appears first).
     *
     * @param int $priority Sort priority
     * @return static For chaining
     * @since   3.5.0
     */
    public function priority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Disable this action (button appears grayed out).
     *
     * @return static For chaining
     * @since   3.5.0
     */
    public function disabled(): static
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * Add arbitrary metadata (passed to layouts and JS).
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return static For chaining
     * @since   3.5.0
     */
    public function meta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Set a custom button layout template.
     *
     * @param string $layout Layout name
     * @return static For chaining
     * @since   3.5.0
     */
    public function buttonLayout(string $layout): static
    {
        $this->button_layout = $layout;

        return $this;
    }

    /**
     * Set a response layout that renders in a modal after execution.
     *
     * @param string $layout Layout name
     * @param string $title Modal title
     * @param string $size Modal size: 'sm', 'md', 'lg', 'xl'
     * @return static For chaining
     * @since   3.5.0
     */
    public function modal(string $layout, string $title = '', string $size = 'md'): static
    {
        $this->response_layout = $layout;
        $this->modal_title = $title ?: null;
        $this->modal_size = $size;

        return $this;
    }

    // =========================================================================
    //  UTILITY
    // =========================================================================

    /**
     * Check if the action has a response layout (will show a modal).
     *
     * @since   3.0.0
     */
    public function hasResponseLayout(): bool
    {
        return !empty($this->response_layout);
    }

    /**
     * Check if the action is valid (has required id and label).
     *
     * @since   3.0.0
     */
    public function isValid(): bool
    {
        return !empty($this->id) && !empty($this->label);
    }

    /**
     * Convert to array for serialization.
     *
     * @since   3.0.0
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
