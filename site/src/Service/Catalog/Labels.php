<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\SurfaceMap;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemLabels;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\AppliedDiscount;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * The labels a product shows, and how they reach a catalogue card and the product page.
 *
 * Three kinds come from here, in this order: the automatic New label while NewProducts says the product is new (its
 * look set once in Options > General > Products, next to the days; no days, no label; a product may hide it or move it
 * to a corner of its own), the shop's predefined labels (Options > Labels) the product ticks, each in the corner the
 * product chose for it or the label's own, and the product's own label, between its dates. The fourth kind, a
 * discount's sale tag, comes from the product's applied discounts, and {@see self::contribute()} puts all four on the
 * product's surface slots: one item.labels descriptor per slot, per corner in the image slot, so the labels of one
 * corner stack one under the other and a template keeps rendering its slots as before.
 *
 * What the catalogue list pays: one query for the page's products' rows ({@see self::warm()}, ItemLabels::forItems())
 * and nothing more; the New check reads the created and publish_up columns the list already selects, and the shop's
 * labels are read from the component's params once a request.
 *
 * @since  2.3.0
 */
final class Labels
{
    /**
     * The shop's predefined labels, in the component params.
     *
     * @since  2.3.0
     */
    public const PARAM_LABELS = 'labels';

    /**
     * The New label's look, in the component params.
     *
     * @since  2.3.0
     */
    public const PARAM_NEW_LABEL = 'new_label';

    /**
     * The New label's text until the Options are saved once: the text its Options field states as its default.
     *
     * @since  2.3.0
     */
    public const NEW_NAME = 'New';

    /**
     * The shop's predefined labels, by key, in their order; null until read.
     *
     * @var array<string, Label>|null
     * @since  2.3.0
     */
    private static ?array $predefined = null;

    /**
     * The New label's look; read once, whatever the days.
     *
     * @var Label|null
     * @since  2.3.0
     */
    private static ?Label $new = null;

    /**
     * The products' rows read so far, by product id then by label key ([] for a product with none).
     *
     * @var array<int, array<string, object>>
     * @since  2.3.0
     */
    private static array $rows = [];

    /**
     * The shop's predefined labels, by key, in the order of Options > Labels. A row without a key, with the key of the
     * automatic or the product's own label, or with a key an earlier row holds is left out.
     *
     * @return array<string, Label>
     *
     * @since  2.3.0
     */
    public static function predefined(): array
    {
        if (self::$predefined !== null) {
            return self::$predefined;
        }

        $labels = [];

        foreach (self::rowsOf(ComponentHelper::getParams('com_alfa')->get(self::PARAM_LABELS)) as $settings) {
            $key = strtolower(trim((string) ($settings['key'] ?? '')));

            if ($key === '' || $key === ItemLabels::KEY_NEW || $key === ItemLabels::KEY_CUSTOM || isset($labels[$key])) {
                continue;
            }

            $labels[$key] = Label::fromSettings(key: $key, kind: Label::KIND_PREDEFINED, settings: $settings);
        }

        return self::$predefined = $labels;
    }

    /**
     * The automatic New label as the shop set it, or null while the shop counts no product as new (no days).
     *
     * @since  2.3.0
     */
    public static function newLabel(): ?Label
    {
        if (NewProducts::days() <= 0) {
            return null;
        }

        if (self::$new === null) {
            $stored = ComponentHelper::getParams('com_alfa')->get(self::PARAM_NEW_LABEL);
            $settings = $stored === null ? ['name' => self::NEW_NAME] : self::settingsOf($stored);
            self::$new = Label::fromSettings(key: ItemLabels::KEY_NEW, kind: Label::KIND_NEW, settings: $settings);
        }

        return self::$new;
    }

    /**
     * Read the rows of these products in one query, for the products not read yet; the list calls it once for its
     * page, so {@see self::of()} then costs no query per card.
     *
     * @param int[] $itemIds The products.
     *
     * @since  2.3.0
     */
    public static function warm(array $itemIds): void
    {
        $missing = [];

        foreach ($itemIds as $itemId) {
            $itemId = (int) $itemId;

            if ($itemId > 0 && !isset(self::$rows[$itemId])) {
                $missing[$itemId] = $itemId;
            }
        }

        if ($missing === []) {
            return;
        }

        $read = ItemLabels::forItems(db: Factory::getContainer()->get(DatabaseInterface::class), itemIds: array_values($missing));

        foreach ($missing as $itemId) {
            self::$rows[$itemId] = $read[$itemId] ?? [];
        }
    }

    /**
     * The labels a product shows in a context, in order: the New label, the predefined labels it ticks, its own label.
     * A label not flagged for the context, or without a text, is left out; the product's own label outside its dates
     * too. The sale tags are not among them: a discount's tag is the discount's ({@see self::contribute()}).
     *
     * @param object $item The product (id, created, publish_up).
     * @param bool $isListing True for a catalogue card, false for the product page.
     * @param string|null $now The moment (UTC); the pricing engine's clock when omitted.
     *
     * @return list<Label>
     *
     * @since  2.3.0
     */
    public static function of(object $item, bool $isListing, ?string $now = null): array
    {
        $itemId = (int) ($item->id ?? 0);

        if ($itemId <= 0) {
            return [];
        }

        self::warm([$itemId]);
        $rows = self::$rows[$itemId];
        $now ??= ActiveDateCondition::now();
        $labels = [];

        $new = self::newLabel();

        if ($new !== null && NewProducts::isNew(created: $item->created ?? null, publishUp: $item->publish_up ?? null, now: $now)) {
            $row = $rows[ItemLabels::KEY_NEW] ?? null;

            if ($row === null || (int) $row->shown === 1) {
                $labels[] = self::placed($new, $row);
            }
        }

        foreach (self::predefined() as $key => $label) {
            $row = $rows[$key] ?? null;

            if ($row !== null && (int) $row->shown === 1) {
                $labels[] = self::placed($label, $row);
            }
        }

        $own = $rows[ItemLabels::KEY_CUSTOM] ?? null;

        if ($own !== null && (int) $own->shown === 1) {
            $settings = json_decode((string) ($own->custom ?? ''), true);

            if (\is_array($settings) && self::within(settings: $settings, now: $now)) {
                $settings['corner'] = (string) ($own->corner ?? '');
                $labels[] = Label::fromSettings(key: ItemLabels::KEY_CUSTOM, kind: Label::KIND_CUSTOM, settings: $settings);
            }
        }

        return array_values(array_filter($labels, static fn (Label $label): bool => $label->shownOn($isListing)));
    }

    /**
     * Put the product's labels and its sale tags on its surface slots, for the templates' Surface::render(): one
     * item.labels descriptor per slot, and per corner in the image slot, in which the labels stand in order: the sale
     * tags of the applied discounts flagged for the context, then {@see self::of()}. The item's surfaces are read-only
     * once handed out: the labels join a new map of them, made only when a label is added.
     *
     * @param object $item The rendered item (carries ->price and ->surfaces).
     * @param bool $isListing True for a catalogue card (ItemListSurface slots), false for the product page (ItemSurface).
     *
     * @since  2.3.0
     */
    public static function contribute(object $item, bool $isListing): void
    {
        $groups = [];
        $price = $item->price ?? null;

        if (\is_object($price) && method_exists($price, 'getDiscounts')) {
            foreach ($price->getDiscounts()->getApplied() as $discount) {
                $label = self::saleTag($discount);

                if ($label->shownOn($isListing)) {
                    self::group($groups, $label, $isListing);
                }
            }
        }

        foreach (self::of($item, $isListing) as $label) {
            self::group($groups, $label, $isListing);
        }

        if ($groups === []) {
            return;
        }

        $slots = $item->surfaces instanceof SurfaceMap ? $item->surfaces->toArray() : (array) ($item->surfaces ?? []);

        foreach ($groups as $slot => $corners) {
            foreach ($corners as $corner => $labels) {
                $slots[$slot][] = [
                    'layout' => 'item.labels',
                    'layoutData' => [
                        'labels' => $labels,
                        'corner' => (string) $corner,
                        'context' => $isListing ? 'list' : 'item',
                        'slot' => (string) $slot,
                    ],
                    'layoutPluginType' => '',
                    'layoutPluginName' => '',
                    'showon' => '',
                ];
            }
        }

        $item->surfaces = new SurfaceMap($slots);
    }

    /**
     * A discount's sale tag as a label: its customer name, flags, slots, colours, class and corner.
     *
     * @param AppliedDiscount $discount The applied discount.
     *
     * @since  2.3.0
     */
    public static function saleTag(AppliedDiscount $discount): Label
    {
        return new Label(
            key: 'sale-' . $discount->id,
            kind: Label::KIND_SALE,
            name: trim((string) $discount->name),
            bg: trim($discount->tagBg),
            color: trim($discount->tagColor),
            class: trim($discount->tagClass),
            listShow: $discount->tagListShow,
            listSlot: $discount->tagListSlot,
            itemShow: $discount->tagItemShow,
            itemSlot: $discount->tagItemSlot,
            corner: LabelCorner::of($discount->tagCorner),
        );
    }

    /**
     * The labels as the REST API states them: key, name and corner each.
     *
     * @param list<Label> $labels The labels.
     *
     * @return list<array{key: string, name: string, corner: string}>
     *
     * @since  2.3.0
     */
    public static function forWire(array $labels): array
    {
        return array_values(array_map(
            static fn (Label $label): array => $label->toArray(),
            array_filter($labels, static fn (mixed $label): bool => $label instanceof Label),
        ));
    }

    /**
     * Forget what was read: the shop's labels and the products' rows. For a process that changes the Options or the
     * rows and reads again.
     *
     * @since  2.3.0
     */
    public static function forget(): void
    {
        self::$predefined = null;
        self::$new = null;
        self::$rows = [];
    }

    /**
     * Add a label to its slot's group; the image slot groups by corner, the other slots in one row.
     *
     * @param array<string, array<string, list<Label>>> $groups The groups, by slot then by corner.
     * @param Label $label The label.
     * @param bool $isListing The context.
     *
     * @since  2.3.0
     */
    private static function group(array &$groups, Label $label, bool $isListing): void
    {
        $slot = $label->slotFor($isListing)->value;
        $corner = $slot === ItemListSurface::Image->value ? $label->corner->value : '';
        $groups[$slot][$corner][] = $label;
    }

    /**
     * The label in the corner the product's row chose for it, or as it is.
     *
     * @param Label $label The label.
     * @param object|null $row The product's row for it.
     *
     * @since  2.3.0
     */
    private static function placed(Label $label, ?object $row): Label
    {
        $corner = $row === null ? null : LabelCorner::tryFrom(trim((string) ($row->corner ?? '')));

        return $corner === null ? $label : $label->inCorner($corner);
    }

    /**
     * Whether a product's own label is within its period at a moment: from its start (or always) and not past its end.
     *
     * @param array<string, mixed> $settings The label's settings (publish_up, publish_down as UTC texts, '' = none).
     * @param string $now The moment (UTC).
     *
     * @since  2.3.0
     */
    private static function within(array $settings, string $now): bool
    {
        $from = trim((string) ($settings['publish_up'] ?? ''));
        $to = trim((string) ($settings['publish_down'] ?? ''));

        return ($from === '' || $from <= $now) && ($to === '' || $to >= $now);
    }

    /**
     * The rows of a subform param as arrays, in their order: the Registry hands them as an object of row objects.
     *
     * @param mixed $stored The param's value.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.3.0
     */
    private static function rowsOf(mixed $stored): array
    {
        $rows = [];

        foreach ((array) ($stored ?? []) as $row) {
            if (\is_array($row) || \is_object($row)) {
                $rows[] = self::settingsOf($row);
            }
        }

        return $rows;
    }

    /**
     * One label's settings as an array, its name map an array too.
     *
     * @param mixed $stored The settings, an array or an object.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    private static function settingsOf(mixed $stored): array
    {
        $settings = (array) $stored;

        if (isset($settings['name']) && \is_object($settings['name'])) {
            $settings['name'] = (array) $settings['name'];
        }

        return $settings;
    }
}
