<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

defined('_JEXEC') or die;

/**
 * Fluent descriptor for ONE field surface (what to render in a given slot):
 *
 *   $event->surface(ItemSurface::Title)->setLayout('badge')->setData([...]);
 *
 * Holds only render METADATA (layout name + data + optional owner), never HTML,
 * never the event. The orchestrator turns it into a plain descriptor array
 * (toDescriptor) which the dumb template renders via Layout::render. Plugin
 * type/name default to the field's own plugin upstream, so they are rarely set.
 *
 * @since  2.0.0
 */
final class SurfaceBuilder
{
    /**
     * @var string The layout fragment to render in this slot.
     * @since  2.0.0
     */
    private string $layout = '';

    /**
     * @var array|object The display data for the layout (item passed when empty).
     * @since  2.0.0
     */
    private array|object $data = [];

    /**
     * @var string The plugin group owning the layout ('' = orchestrator defaults it).
     * @since  2.0.0
     */
    private string $pluginType = '';

    /**
     * @var string The plugin element owning the layout ('' = orchestrator defaults it).
     * @since  2.0.0
     */
    private string $pluginName = '';

    /**
     * @var SelectorDescriptor|null The buyer-input wiring when this surface renders
     *                              an interactive selector (null = a passive slot).
     * @since  2.0.0
     */
    private ?SelectorDescriptor $selector = null;

    /**
     * Set the layout fragment for this slot.
     *
     * @param string $layout Layout name (without .php).
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function setLayout(string $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    /**
     * Set the display data passed to the layout. Keep the item OUT of here: the
     * renderer passes the item itself when this is empty.
     *
     * @param array|object $data The display data.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function setData(array|object $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Override the plugin group that owns this layout (rare; defaults to the
     * field's own plugin).
     *
     * @param string $type The plugin group.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function setPluginType(string $type): static
    {
        $this->pluginType = $type;

        return $this;
    }

    /**
     * Override the plugin element that owns this layout (rare; defaults to the
     * field's type).
     *
     * @param string $name The plugin element.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function setPluginName(string $name): static
    {
        $this->pluginName = $name;

        return $this;
    }

    /**
     * Declare that this surface renders a VARIATION-axis selector for $fieldId:
     * the framework wires name="sel[id]" + whole-item reload. The plugin states
     * only the KIND; the sel[]/reload mapping lives in SelectorDescriptor.
     *
     * @param int $fieldId The field id.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function variantSelector(int $fieldId): static
    {
        $this->selector = SelectorDescriptor::variant($fieldId);

        return $this;
    }

    /**
     * Declare that this surface renders an OPTION (extra) selector for $fieldId:
     * the framework wires name="opt[id]" + re-price-only. Counterpart of
     * variantSelector().
     *
     * @param int $fieldId The field id.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function optionSelector(int $fieldId): static
    {
        $this->selector = SelectorDescriptor::option($fieldId);

        return $this;
    }

    /**
     * Whether a (non-empty) layout was set for this slot.
     *
     *
     * @since  2.0.0
     */
    public function hasLayout(): bool
    {
        return $this->layout !== '';
    }

    /**
     * The plain render descriptor consumed by the dumb template's Layout::render.
     *
     * @return array{layout: string, layoutData: array|object, layoutPluginType: string, layoutPluginName: string}
     *
     * @since  2.0.0
     */
    public function toDescriptor(): array
    {
        $data = $this->data;

        // Flatten the selector wiring INTO the layout data at render time, so the
        // plugin's setData() stays pure view data and the dumb template just reads
        // $inputName/$reloadScope. Selector keys are authoritative (the `+`), so a
        // payload can never override the framework contract.
        if ($this->selector !== null) {
            $data = $this->selector->toLayoutData() + (array) $data;
        }

        return [
            'layout' => $this->layout,
            'layoutData' => $data,
            'layoutPluginType' => $this->pluginType,
            'layoutPluginName' => $this->pluginName,
        ];
    }
}
