<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaItemFields\Choice\Extension;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListPrepareEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemViewEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ValueFacetGroupsEvent;
use Alfa\Component\Alfa\Administrator\Plugin\ItemFieldsPlugin;
use Alfa\Plugin\AlfaItemFields\Choice\Color\ChoiceColors;
use Joomla\CMS\Log\Log;
use Joomla\Event\SubscriberInterface;
use LogicException;

/**
 * Choice item-field type: the workhorse of the product fields system.
 *
 * One field of this type carries a set of pool values (S/M/L/XL, Red/Blue, ...)
 * and renders them per surface according to its field config:
 *   - item page:  render_as         (select / radio / buttons / swatches / cards)
 *   - card:       listing_render_as (inherit = the item page's mode, any item-page
 *                                    mode, or text = read-only labels with no selector)
 *   - filters:    filter_render_as  (checkboxes / buttons / swatch grid / select)
 * render_as and listing_render_as name an item-<name> layout of this plugin's
 * tmpl or of a template override; the item page never draws text.
 * Per-value swatch presentation (hex color, image) lives in the value's
 * params (declared by params/value.xml); core stores it opaquely.
 *
 * Frontend rendering / validation / filter handlers land with the site
 * stages; this class stays the single home for them.
 *
 * @since  1.0.0
 */
final class Choice extends ItemFieldsPlugin implements SubscriberInterface
{
    /**
     * How this plugin renders a variant value that is NOT reachable with the
     * buyer's current selection: a PRESENTATION policy owned by this renderer,
     * NOT field domain data (so it lives in the field's plugin params, never a
     * core column). 'disabled' = shown greyed + non-selectable (the default,
     * keeps the axis navigable); 'removed' = not rendered at all. A field's
     * `unavailable_display` param of 'inherit' (or unset) falls back to this, so
     * a future shop-wide default only changes this one constant.
     *
     * @var string
     *
     * @since  1.3.0
     */
    private const DEFAULT_UNAVAILABLE_DISPLAY = 'disabled';

    /**
     * The product-page layout when render_as stores nothing, and the one drawn
     * instead of a stored 'text' (the card's read-only layout, which gives the
     * buyer nothing to pick on the product page).
     *
     * @var string
     *
     * @since  1.10.0
     */
    private const DEFAULT_RENDER_AS = 'select';

    /**
     * Item page: render the combination-aware selector for this choice field.
     * The component has already resolved the buyer's current selection (see
     * CombinationResolver), so we only pick the layout (per render_as) and hand
     * it the field's values plus which are selectable / in stock / chosen. The
     * inputs are named sel[fieldId] so a change re-fetches the item component.
     *
     * Variant and option fields render their selector in the buy form;
     * informational fields render their values read-only into the
     * Specifications slot.
     *
     * @param ItemViewEvent $event Item view event.
     *
     *
     * @since  1.3.0
     */
    public function onItemView($event): void
    {
        $this->renderSelector($event);
    }

    /**
     * Listing card: render the combination-aware selector chosen by
     * listing_render_as, so a catalog card behaves like the inner product
     * (changing a variant re-fetches the card, an option re-prices it). The
     * model dispatches only fields flagged "Show on listings" and resolves the
     * card's variant before dispatching. listing_render_as 'text' draws the
     * values read-only with no selector declared, so the card's buy decision
     * counts the field as not drawn, and a dimension drawn so constrains nothing
     * on the card (the model resolves and draws it again as hidden).
     * Informational values render read-only into the card's Specifications slot.
     *
     * @param ItemListPrepareEvent $event Item list-prepare event.
     *
     *
     * @since  1.3.0
     */
    public function onItemListPrepare($event): void
    {
        $this->renderSelector($event);
    }

    /**
     * Report which facet groups each of this field's values belongs to.
     *
     * THIS IS WHERE COLOUR SEMANTICS LIVE, and the only place they do. The codes are in
     * this plugin's own per-value params, so this plugin decides what counts as a colour,
     * that `#00F` and `#0000ff` are one identity, and how a two-tone value is painted. What
     * the catalogue receives is a finished membership list with an OPAQUE id: it cannot
     * tell these are colours, which is precisely what lets a Brand or Material plugin use
     * the same filter with no change to the component or the module.
     *
     * A value carrying several colours reports several memberships: a Blue/Red value is in
     * Blue AND in Red, so one product answers both filters without being duplicated.
     *
     * `layout` names a template in this plugin's own tmpl/; the module hands it to the
     * shared plugin-layout renderer to draw the chip interior. No HTML is returned here,
     * and layoutData reaches that interior only; the input, its name, the token and the
     * field id stay the module's.
     *
     * @param ValueFacetGroupsEvent $event The membership request.
     *
     *
     * @since  1.10.0
     */
    public function onItemFieldValueFacetGroups($event): void
    {
        if (!$event instanceof ValueFacetGroupsEvent) {
            return;
        }

        // The field's own answer to "should my values be grouped?", from the type settings
        // beside filter_render_as. The component carries it; a module may follow it.
        $event->putFieldGrouping(
            (bool) ($event->getField()->params_decoded['filter_group_values'] ?? 0),
        );

        foreach ($event->getValues() as $valueId => $value) {
            $params = $value['params'] ?? [];

            if (!\is_array($params)) {
                $params = json_decode((string) $params, true) ?: [];
            }

            $codes = ChoiceColors::codes($params);
            $image = (string) ($params['swatch_image'] ?? '');

            // How this value draws itself when the panel lists it on its own.
            if (!empty($codes) || $image !== '') {
                $event->putValueLayout(
                    (int) $valueId,
                    'facet-chip',
                    ['codes' => array_keys($codes), 'image' => $image],
                );
            }

            if (empty($codes)) {
                continue;
            }

            // The whole list travels with EVERY membership: a Blue/Red value shows the same
            // two-tone chip under Blue as under Red, so a shopper can see why it matched.
            $allCodes = array_keys($codes);
            $groups = [];

            foreach ($codes as $code => $label) {
                $groups[] = [
                    'type' => 'color',
                    'id' => $code,
                    'label' => $label,
                    'layout' => 'facet-chip',
                    'layoutData' => ['codes' => $allCodes, 'image' => $image],
                ];
            }

            $event->putValueGroups((int) $valueId, $groups);
        }
    }

    /**
     * Pick the layout (render_as on the product page, listing_render_as on the
     * card) and hand it the field's values plus which are selectable / in stock /
     * chosen, shared by the product page (onItemView) and the catalog card
     * (onItemListPrepare). A variant field renders a sel[fieldId] selector (a
     * change re-fetches the item/card), an option field an opt[] selector (a
     * change re-prices it), both in the buy form; the card's 'text' mode draws
     * the same values read-only in the buy form with no selector declared. An
     * informational field renders its values read-only into the Specifications
     * slot, on the product page and the card alike.
     *
     * @param ItemViewEvent|ItemListPrepareEvent $event The surface event (both
     *                                                  expose getField/getItem,
     *                                                  buyForm() and
     *                                                  specifications()).
     *
     *
     * @since  1.3.0
     */
    private function renderSelector($event): void
    {
        $field = $event->getField();
        $item = $event->getItem();
        $variant = $item->variant ?? null;
        $fieldId = (int) $field->id;
        $onCard = $event instanceof ItemListPrepareEvent;

        // Variant field (combination-aware selector): availability + stock come
        // from the resolved variant, and the input drives a full re-fetch (sel[]).
        if ($variant && in_array($fieldId, $variant->variantFieldIds ?? [], true)) {
            $renderAs = $this->renderMode($field, $onCard);

            if ($renderAs === 'text') {
                self::renderText($event, $field, $field->values ?? []);

                return;
            }

            $event->buyForm()
                ->setLayout('item-' . $renderAs)
                ->variantSelector($fieldId)
                ->setData([
                    'field' => $field,
                    'values' => $field->values ?? [],
                    'selected' => (int) ($variant->selection[$fieldId] ?? 0),
                    'available' => $variant->availableValueIds[$fieldId] ?? [],
                    'inStock' => $variant->inStockValueIds[$fieldId] ?? [],
                    'unavailableMode' => $this->unavailableMode($field),
                ]);

            return;
        }

        // Informational field, a read-only specs surface (no selector, no input):
        // the value(s) this item carries for the field, in the Specifications slot
        // of the product page and of the card alike.
        if (!empty($field->informational)) {
            $infoValues = self::informationalValuesFor($item, $field);

            if ($infoValues !== []) {
                $event->specifications()
                    ->setLayout('spec-default')
                    ->setData([
                        'field' => $field,
                        'values' => $infoValues,
                    ]);
            }

            return;
        }

        $optionValues = self::optionValuesFor($item, $field);

        if ($optionValues === []) {
            return;
        }

        // Resolved only where a layout is drawn: an informational field never reads it.
        $renderAs = $this->renderMode($field, $onCard);

        if ($renderAs === 'text') {
            self::renderText($event, $field, $optionValues);

            return;
        }

        $selectable = [];

        foreach ($optionValues as $value) {
            $selectable[(int) $value->id] = true;
        }

        $chosen = array_flip(array_map('intval', $item->selectedOptionValueIds ?? []));
        $selected = 0;

        foreach ($optionValues as $value) {
            if (isset($chosen[(int) $value->id])) {
                $selected = (int) $value->id;

                break;
            }
        }

        $event->buyForm()
            ->setLayout('item-' . $renderAs)
            ->optionSelector($fieldId)
            ->setData([
                'field' => $field,
                'values' => $optionValues,
                'selected' => $selected,
                'available' => $selectable,   // every offered option is selectable
                'inStock' => $selectable,     // options aren't combination-stock gated
                'unavailableMode' => self::DEFAULT_UNAVAILABLE_DISPLAY, // inert: options never unavailable
            ]);
    }

    /**
     * The layout mode for one surface: render_as on the product page;
     * listing_render_as on the card, where 'inherit' (or nothing stored) follows
     * the product page. A stored value is used as it is: it names an item-<mode>
     * layout of this plugin or of a template override, and one that does not
     * exist is reported by the layout helper under debug.
     *
     * The product page never draws 'text': a stored render_as 'text' fails loud
     * in development (JDEBUG -> throw) and is drawn as the default layout and
     * logged in production, the same policy as Surfaces::assertSelectorRole().
     *
     * @param object $field The field (carries ->params_decoded).
     * @param bool $onCard Whether the surface is a listing card.
     *
     * @return string The layout name after item- (select, radio, buttons, swatches,
     *                cards, a template layout), or 'text' on a card.
     *
     * @throws LogicException Under JDEBUG, when render_as stores 'text'.
     *
     * @since  1.10.0
     */
    private function renderMode(object $field, bool $onCard): string
    {
        $params = $field->params_decoded ?? [];

        if ($onCard) {
            $listingAs = (string) ($params['listing_render_as'] ?? 'inherit');

            if ($listingAs !== 'inherit') {
                return $listingAs;
            }
        }

        $renderAs = (string) ($params['render_as'] ?? self::DEFAULT_RENDER_AS);

        if ($renderAs !== 'text') {
            return $renderAs;
        }

        $message = sprintf(
            'alfa-item-fields: choice field %d stores render_as "text", the read-only listing layout. The product page'
            . ' must draw a selector the buyer can answer, so the field is drawn as "%s".',
            (int) $field->id,
            self::DEFAULT_RENDER_AS,
        );

        if (\defined('JDEBUG') && JDEBUG) {
            throw new LogicException($message);
        }

        Log::add($message, Log::WARNING, 'alfa-item-fields');

        return self::DEFAULT_RENDER_AS;
    }

    /**
     * Draw a field's values as read-only labels in the buy form (layout
     * item-text). No selector is declared, so nothing is posted for the field
     * and the card's buy decision counts it as not drawn.
     *
     * @param ItemListPrepareEvent $event The card event.
     * @param object $field The field definition.
     * @param object[] $values The value objects to list.
     *
     *
     * @since  1.10.0
     */
    private static function renderText($event, object $field, array $values): void
    {
        if ($values === []) {
            return;
        }

        $event->buyForm()
            ->setLayout('item-text')
            ->setData([
                'field' => $field,
                'values' => $values,
            ]);
    }

    /**
     * The effective unavailable-value display mode for a field: its
     * `unavailable_display` plugin param, with 'inherit'/unset resolving to this
     * plugin's default. Returns 'disabled' or 'removed'.
     *
     * @param object $field The field (carries ->params_decoded).
     *
     *
     * @since  1.3.0
     */
    private function unavailableMode(object $field): string
    {
        $mode = (string) ($field->params_decoded['unavailable_display'] ?? 'inherit');

        return $mode === 'removed' ? 'removed' : self::DEFAULT_UNAVAILABLE_DISPLAY;
    }

    /**
     * The field's values that this item offers as OPTIONS: their ids appear in
     * the item's option rows ($item->options). A pure option field returns all
     * its values; a mixed field returns only its option-side values.
     *
     * @param object $item The item (carries ->options).
     * @param object $field The field (carries ->values).
     *
     * @return object[] The option value objects (may be empty).
     *
     * @since  1.3.0
     */
    private static function optionValuesFor(object $item, object $field): array
    {
        $optionIds = [];

        foreach ($item->options ?? [] as $option) {
            $optionIds[(int) $option->value_id] = true;
        }

        if ($optionIds === []) {
            return [];
        }

        $values = [];

        foreach ($field->values ?? [] as $value) {
            if (isset($optionIds[(int) $value->id])) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The field's values that this item carries as INFORMATIONAL (spec) values:
     * their ids appear in the item's informational rows ($item->informational).
     * Returns the pool value objects (with display_name) for read-only rendering.
     *
     * @param object $item The item (carries ->informational).
     * @param object $field The field (carries ->values).
     *
     * @return object[] The informational value objects (may be empty).
     *
     * @since  1.3.0
     */
    private static function informationalValuesFor(object $item, object $field): array
    {
        $fieldId = (int) $field->id;
        $infoIds = [];

        foreach ($item->informational ?? [] as $value) {
            if ((int) ($value->item_field_id ?? 0) === $fieldId) {
                $infoIds[(int) $value->id] = true;
            }
        }

        if ($infoIds === []) {
            return [];
        }

        $values = [];

        foreach ($field->values ?? [] as $value) {
            if (isset($infoIds[(int) $value->id])) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
