<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Lists;

use Closure;

defined('_JEXEC') or die;

/**
 * Fluent descriptor for ONE plugin-declared list column: the table's kind of
 * surface, usable by ANY admin list screen (orders, items, ...). It mirrors
 * SurfaceBuilder (setLayout/setData/setPluginType/setPluginName, layout +
 * data, never HTML) and adds only what a table structurally requires beyond a
 * free slot: a header label and a position.
 *
 *   $event->column('subscription')
 *       ->title('PLG_ALFA_SUBSCRIPTIONS_ORDERS_COLUMN')
 *       ->setLayout('column_subscription')
 *       ->after('payment')
 *       ->setData(fn (object $row): ?array => $map[(int) $row->id] ?? null);
 *
 * The layout owner defaults to the invoked plugin (the caller fills it, as
 * in every boot-and-call dispatch); setPluginType/setPluginName exist only
 * to render another plugin's layout.
 *
 * setData() takes a per-row closure, evaluated once per row at render time;
 * returning null leaves that row's cell empty (every row always renders a
 * cell, so the table stays rectangular). The anchor keys accepted by after()
 * belong to the dispatching screen (each list documents its own).
 *
 * @since  2.0.0
 */
final class ColumnBuilder
{
    /**
     * @var string Header language key for the column's <th>.
     * @since  2.0.0
     */
    private string $title = '';

    /**
     * @var string The cell layout (without .php).
     * @since  2.0.0
     */
    private string $layout = '';

    /**
     * @var Closure|null Per-row data resolver: fn (object $row): array|object|null.
     * @since  2.0.0
     */
    private ?Closure $data = null;

    /**
     * @var string The plugin group owning the cell layout.
     * @since  2.0.0
     */
    private string $pluginType = '';

    /**
     * @var string The plugin element owning the cell layout.
     * @since  2.0.0
     */
    private string $pluginName = '';

    /**
     * @var string Core column key the column anchors after ('' = appended last).
     * @since  2.0.0
     */
    private string $after = '';

    /**
     * @param string $key The column's identity key (unique per contributor).
     *
     * @since  2.0.0
     */
    public function __construct(private readonly string $key)
    {
    }

    /**
     * Set the header language key rendered in the column's <th>.
     *
     * @param string $title Language constant.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set the cell layout, resolved inside the owner plugin's tmpl folder.
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
     * Set the per-row data resolver. Called once per row at render time;
     * null skips the row (an empty cell keeps the table rectangular).
     *
     * @param Closure $data fn (object $row): array|object|null.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function setData(Closure $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Declare the plugin group that owns the cell layout.
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
     * Declare the plugin element that owns the cell layout.
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
     * Anchor the column right after one of the dispatching screen's core
     * columns. Unset or unknown keys append the column at the end.
     *
     * @param string $coreColumn The core column key.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function after(string $coreColumn): static
    {
        $this->after = $coreColumn;

        return $this;
    }

    /**
     * Whether a (non-empty) cell layout was set.
     *
     *
     * @since  2.0.0
     */
    public function hasLayout(): bool
    {
        return $this->layout !== '';
    }

    /**
     * The plain column descriptor the list template renders from.
     *
     * @return array{key: string, title: string, after: string, layout: string,
     *               layoutPluginType: string, layoutPluginName: string, data: ?Closure}
     *
     * @since  2.0.0
     */
    public function toDescriptor(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'after' => $this->after,
            'layout' => $this->layout,
            'layoutPluginType' => $this->pluginType,
            'layoutPluginName' => $this->pluginName,
            'data' => $this->data,
        ];
    }
}
