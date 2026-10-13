<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

defined('_JEXEC') or die;

/**
 * One line of the cart, as anything outside the cart reads it.
 *
 * THE ONE WAY IN. A carrier used to be handed its own line type, built by the
 * dispatcher, carrying the same lines in the same quantities as the cart's own
 * - the only difference was that it had flattened the measurements and the
 * virtual flag out of the product hanging off each line. That is formatting,
 * not scope, and it is not worth a second way to ask what is in a cart: two
 * access paths mean two definitions of "the lines", and they drift.
 *
 * MEASUREMENTS ARE THE RESOLVED ONES, PER UNIT. What the shopper actually
 * bought: the product's own width, height, depth and weight, overwritten by the
 * chosen variant's wherever that variant sets them. A combination column that
 * is NULL (or blank) means the variant said nothing and inherits, which is
 * the schema's own rule and is applied by the hydration layer before a line
 * ever reaches here. Nothing in this class queries anything.
 *
 * THE UNIT IS THE SHOP'S. Alfa records one weight unit and one length unit for
 * the whole catalogue, so these are bare numbers in whatever the merchant
 * configured, exactly as they were entered on the product and the variant. A
 * carrier comparing them against its own bands compares like with like as long
 * as the merchant set the bands up in the same unit, which is the merchant's
 * decision, and not one the cart can make for them.
 *
 * @since  2.0.0
 */
final class CartLine
{
    /**
     * @param object $row The hydrated cart line, with its product as ->data.
     *
     * @since  2.0.0
     */
    private function __construct(private readonly object $row)
    {
    }

    /**
     * @param object $row The hydrated cart line.
     *
     *
     * @since  2.0.0
     */
    public static function of(object $row): self
    {
        return new self($row);
    }

    /** @return int The cart line's own id. @since 2.0.0 */
    public function id(): int
    {
        return (int) ($this->row->id ?? 0);
    }

    /** @return int The product on it. @since 2.0.0 */
    public function itemId(): int
    {
        return (int) ($this->row->id_item ?? 0);
    }

    /** @return int|null The chosen variant, or null for a plain product. @since 2.0.0 */
    public function combinationId(): ?int
    {
        $id = $this->row->combination_id ?? null;

        return $id === null ? null : (int) $id;
    }

    /** @return int How many units. @since 2.0.0 */
    public function quantity(): int
    {
        return (int) ($this->row->quantity ?? 0);
    }

    /** @return string What the shopper bought, in the active language. @since 2.0.0 */
    public function name(): string
    {
        return (string) ($this->row->data->name ?? '');
    }

    /** @return float Resolved width, per unit. @since 2.0.0 */
    public function width(): float
    {
        return $this->measure('width');
    }

    /** @return float Resolved height, per unit. @since 2.0.0 */
    public function height(): float
    {
        return $this->measure('height');
    }

    /** @return float Resolved depth, per unit. @since 2.0.0 */
    public function depth(): float
    {
        return $this->measure('depth');
    }

    /** @return float Resolved weight, per unit. @since 2.0.0 */
    public function weight(): float
    {
        return $this->measure('weight');
    }

    /**
     * Whether there is nothing here to carry.
     *
     * Read off the product's explicit flag, never inferred from a zero weight:
     * a heavy thing whose weight nobody recorded still has to be shipped.
     *
     *
     * @since  2.0.0
     */
    public function isVirtual(): bool
    {
        return !empty($this->row->is_virtual) || !empty($this->row->data->is_virtual);
    }

    /**
     * Whether the product has left the catalogue: the line survives, named as
     * what it was, and nothing may be priced against it.
     *
     *
     * @since  2.0.0
     */
    public function isUnavailable(): bool
    {
        return !empty($this->row->unavailable);
    }

    /**
     * A resolved measurement off the product, as the hydration layer left it.
     *
     * #__alfa_cart_items carries no dimensions and never has, so reading them
     * from the line row handed every carrier a 0x0x0 parcel weighing nothing
     * and compared every band a merchant had set against zero.
     *
     * @param string $column The measurement.
     *
     *
     * @since  2.0.0
     */
    private function measure(string $column): float
    {
        return (float) ($this->row->data->{$column} ?? 0);
    }
}
