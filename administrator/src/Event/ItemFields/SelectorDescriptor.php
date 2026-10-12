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
 * The wiring of ONE buyer-facing selector control, the SINGLE place that knows
 * the framework's input/reload contract:
 *
 *   variant -> name="sel[fieldId]"  + reload the whole item (the SKU changes)
 *   option  -> name="opt[fieldId]"  + re-price only (an extra)
 *
 * Owned by SurfaceBuilder (the surface that renders the control declares its kind
 * with ->variantSelector()/->optionSelector()); flattened into the layout data at
 * render so the dumb template just reads $inputName/$reloadScope. The `sel[]`/`opt[]`
 * names are the framework convention enforced by the read side
 * (VariantHelper::requestSelection), so this object is their one authoritative source.
 *
 * Immutable + value-semantic: a future selector trait (group / trigger / debounce /
 * target / request strategy) is added HERE and flows to templates through
 * toLayoutData(), without touching the SurfaceBuilder API or any template.
 *
 * @since  1.3.0
 */
final class SelectorDescriptor
{
    /**
     * Reload scope: a VARIATION change re-fetches the whole item (resolved SKU
     * changes); an OPTION change only re-prices. Emitted as data-item-reload,
     * read by site/item/variant.js.
     *
     * @since  1.3.0
     */
    public const RELOAD_ITEM = 'item';
    public const RELOAD_PRICE = 'price';

    /**
     * @param string $inputName The control's form name (sel[id] / opt[id]).
     * @param string $reloadScope The reload scope (RELOAD_ITEM / RELOAD_PRICE).
     *
     * @since  1.3.0
     */
    private function __construct(
        private string $inputName,
        private string $reloadScope,
    ) {
    }

    /**
     * A variation-axis selector: its change re-fetches the whole item.
     *
     * @param int $fieldId The field id.
     *
     *
     * @since  1.3.0
     */
    public static function variant(int $fieldId): self
    {
        return new self('sel[' . $fieldId . ']', self::RELOAD_ITEM);
    }

    /**
     * An option (extra) selector: its change only re-prices.
     *
     * @param int $fieldId The field id.
     *
     *
     * @since  1.3.0
     */
    public static function option(int $fieldId): self
    {
        return new self('opt[' . $fieldId . ']', self::RELOAD_PRICE);
    }

    /**
     * Flatten to the layout-data keys the selector templates read. Future selector
     * attributes are added here; templates and the SurfaceBuilder API stay put.
     *
     * @return array{inputName: string, reloadScope: string}
     *
     * @since  1.3.0
     */
    public function toLayoutData(): array
    {
        return [
            'inputName' => $this->inputName,
            'reloadScope' => $this->reloadScope,
        ];
    }
}
