<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

/**
 * Everything an add to the cart needs, and nothing about how it was asked for.
 *
 * Adding a product is not an HTTP request. The chosen options, the uploaded
 * files and "am I editing an existing line" are read once, into this value, so
 * an admin order taken over the phone, an import, a scheduled reorder and a
 * test can all add to a cart without being a browser.
 *
 * A storefront POST becomes one of these in the application service, through
 * the request adapter; every writer beneath takes the command and never reads
 * a superglobal.
 *
 * @since  2.0.0
 */
final class AddToCartCommand
{
    /**
     * @param int $itemId The product being added.
     * @param int|float $quantity How many, before the item's own rules clamp it.
     *                            A float is tolerated ONLY because the field
     *                            plugins' validation event takes one and a
     *                            caller may pass one; storage is always int.
     * @param array $selection The buyer's chosen configuration, in the shape
     *                         VariantHelper::requestSelection() produces:
     *                         ['selection' => [], 'optionValueIds' => [],
     *                         'payloads' => []].
     * @param array $fullPayloads The heavyweight payload per field, which a
     *                            field plugin materialises for storage
     *                            (an upload becoming a path, say). Keyed by
     *                            field id.
     * @param array $files Uploaded files, keyed field id => slot.
     * @param int $editLineId The line being re-configured, or 0 to add.
     *                        Ownership is checked by the caller; never
     *                        trusted from here.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly int|float $quantity,
        public readonly array $selection = ['selection' => [], 'optionValueIds' => [], 'payloads' => []],
        public readonly array $fullPayloads = [],
        public readonly array $files = [],
        public readonly int $editLineId = 0,
    ) {
    }

    /**
     * The simplest possible add: a product and a quantity, no configuration.
     *
     * This is the programmatic entry point (an admin placing an order for a
     * customer, a reorder, a test), and it is deliberately the shortest thing
     * to write, because the request-shaped case is the exception, not the rule.
     * It carries no selection, so it adds a plain product or one whose every
     * dimension is fixed; a combination product needs a command whose selection
     * answers each dimension that has a choice, or the add gate refuses it.
     *
     * @param int $itemId The product.
     * @param int $quantity How many.
     *
     *
     * @since  2.0.0
     */
    public static function of(int $itemId, int $quantity): self
    {
        return new self($itemId, $quantity);
    }

    /**
     * The same command, aimed at re-configuring an existing line rather than
     * adding a new one.
     *
     * @param int $lineId The line to replace.
     *
     *
     * @since  2.0.0
     */
    public function editingLine(int $lineId): self
    {
        return new self(
            $this->itemId,
            $this->quantity,
            $this->selection,
            $this->fullPayloads,
            $this->files,
            max(0, $lineId),
        );
    }
}
