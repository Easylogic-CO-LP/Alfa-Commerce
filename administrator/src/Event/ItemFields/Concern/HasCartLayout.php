<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern;

defined('_JEXEC') or die;

/**
 * Shared cart-region layout hand-off: the SAME contract on the product page
 * (ItemViewEvent) and the listing card (ItemListPrepareEvent). The events differ
 * (different dispatch contexts); this is the common vocabulary they speak, so a
 * plugin replaces the add-to-cart MAIN slot with one API on either surface.
 *
 * MAIN and CHILD share the SAME granular contract (setCartLayout... /
 * setCartChildLayout...): each event contributes at most one MAIN replacement and
 * one CHILD sibling (wishlist, buy-now). The ONLY difference is in the
 * orchestrator: it REPLACES $item->cart with the MAIN (last field wins) but
 * APPENDS each field's CHILD to $item->cartChildren (siblings accumulate, and
 * survive even when MAIN is replaced). Cart state lives in plain properties (not
 * event arguments) to avoid the Joomla onSet/set magic-argument trap; the
 * orchestrator (HtmlView / ItemsModel) harvests it onto $item->cart +
 * $item->cartChildren, rendered by the dumb cart.wrapper view.
 *
 * The core default MAIN is cart.add. On a listing card it may instead be
 * cart.options: a Choose options link to the product page, set by the core
 * after the field dispatch when the card hides a dimension or required field the
 * buyer must answer. A MAIN a plugin sets here is never replaced by the core.
 *
 * @since  1.3.0
 */
trait HasCartLayout
{
    /**
     * @var string The layout that replaces the add-to-cart MAIN slot ('' = none).
     *
     * @since  1.3.0
     */
    protected string $cartLayout = '';

    /**
     * @var array|object The cart layout's display data (item passed when empty).
     *
     * @since  1.3.0
     */
    protected array|object $cartLayoutData = [];

    /**
     * @var string The plugin group owning the cart layout ('' = orchestrator defaults it).
     *
     * @since  1.3.0
     */
    protected string $cartLayoutPluginType = '';

    /**
     * @var string The plugin element owning the cart layout ('' = orchestrator defaults it).
     *
     * @since  1.3.0
     */
    protected string $cartLayoutPluginName = '';

    /**
     * Whether a (non-empty) cart layout was set.
     *
     *
     * @since  1.3.0
     */
    public function hasCartLayout(): bool
    {
        return $this->cartLayout !== '';
    }

    /**
     * Set the layout that replaces the add-to-cart MAIN slot.
     *
     * @param string $layout The layout name (without .php).
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartLayout(string $layout): static
    {
        $this->cartLayout = $layout;

        return $this;
    }

    /**
     * The cart layout name ('' when none).
     *
     *
     * @since  1.3.0
     */
    public function getCartLayout(): string
    {
        return $this->cartLayout;
    }

    /**
     * Set the display data passed to the cart layout. Keep the item OUT of here:
     * the renderer passes the item itself when this is empty.
     *
     * @param array|object $data The display data.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartLayoutData(array|object $data): static
    {
        $this->cartLayoutData = $data;

        return $this;
    }

    /**
     * The display data for the cart layout.
     *
     *
     * @since  1.3.0
     */
    public function getCartLayoutData(): array|object
    {
        return $this->cartLayoutData;
    }

    /**
     * Set the plugin group that owns this cart layout. Optional; the orchestrator
     * defaults it to the field's type group.
     *
     * @param string $type The plugin group.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartLayoutPluginType(string $type): static
    {
        $this->cartLayoutPluginType = $type;

        return $this;
    }

    /**
     * The plugin group set for this cart layout ('' = let the orchestrator default it).
     *
     *
     * @since  1.3.0
     */
    public function getCartLayoutPluginType(): string
    {
        return $this->cartLayoutPluginType;
    }

    /**
     * Set the plugin element that owns this cart layout. Optional; the
     * orchestrator defaults it to the field's type.
     *
     * @param string $name The plugin element.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartLayoutPluginName(string $name): static
    {
        $this->cartLayoutPluginName = $name;

        return $this;
    }

    /**
     * The plugin element set for this cart layout ('' = let the orchestrator default it).
     *
     *
     * @since  1.3.0
     */
    public function getCartLayoutPluginName(): string
    {
        return $this->cartLayoutPluginName;
    }

    /**
     * @var string The CHILD layout this event contributes ('' = none): a sibling
     *             the orchestrator APPENDS beside the MAIN slot (vs the MAIN which
     *             it REPLACES). Same granular contract as the MAIN slot above.
     *
     * @since  1.3.0
     */
    protected string $cartChildLayout = '';

    /**
     * @var array|object The child layout's display data (item passed when empty).
     *
     * @since  1.3.0
     */
    protected array|object $cartChildLayoutData = [];

    /**
     * @var string The plugin group owning the child layout ('' = orchestrator defaults it).
     *
     * @since  1.3.0
     */
    protected string $cartChildLayoutPluginType = '';

    /**
     * @var string The plugin element owning the child layout ('' = orchestrator defaults it).
     *
     * @since  1.3.0
     */
    protected string $cartChildLayoutPluginName = '';

    /**
     * Whether a (non-empty) child layout was set.
     *
     *
     * @since  1.3.0
     */
    public function hasCartChild(): bool
    {
        return $this->cartChildLayout !== '';
    }

    /**
     * Set the CHILD layout this event contributes: a sibling APPENDED beside the
     * add-to-cart MAIN slot (vs setCartLayout, which REPLACES the MAIN). Same
     * granular contract; the orchestrator accumulates one per field.
     *
     * @param string $layout The layout name (without .php).
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartChildLayout(string $layout): static
    {
        $this->cartChildLayout = $layout;

        return $this;
    }

    /**
     * The child layout name ('' when none).
     *
     *
     * @since  1.3.0
     */
    public function getCartChildLayout(): string
    {
        return $this->cartChildLayout;
    }

    /**
     * Set the display data passed to the child layout. Keep the item OUT of here:
     * the renderer passes the item itself when this is empty.
     *
     * @param array|object $data The display data.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartChildLayoutData(array|object $data): static
    {
        $this->cartChildLayoutData = $data;

        return $this;
    }

    /**
     * The display data for the child layout.
     *
     *
     * @since  1.3.0
     */
    public function getCartChildLayoutData(): array|object
    {
        return $this->cartChildLayoutData;
    }

    /**
     * Set the plugin group that owns this child layout. Optional; the orchestrator
     * defaults it to the field's type group.
     *
     * @param string $type The plugin group.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartChildLayoutPluginType(string $type): static
    {
        $this->cartChildLayoutPluginType = $type;

        return $this;
    }

    /**
     * The plugin group set for this child layout ('' = let the orchestrator default it).
     *
     *
     * @since  1.3.0
     */
    public function getCartChildLayoutPluginType(): string
    {
        return $this->cartChildLayoutPluginType;
    }

    /**
     * Set the plugin element that owns this child layout. Optional; the
     * orchestrator defaults it to the field's type.
     *
     * @param string $name The plugin element.
     *
     * @return static For chaining.
     *
     * @since  1.3.0
     */
    public function setCartChildLayoutPluginName(string $name): static
    {
        $this->cartChildLayoutPluginName = $name;

        return $this;
    }

    /**
     * The plugin element set for this child layout ('' = let the orchestrator default it).
     *
     *
     * @since  1.3.0
     */
    public function getCartChildLayoutPluginName(): string
    {
        return $this->cartChildLayoutPluginName;
    }
}
