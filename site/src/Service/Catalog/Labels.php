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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
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
 * to a corner of its own), the shop's labels (the Labels screen, `#__alfa_labels`) the product ticks, each in the
 * corner the product chose for it or the label's own, and the product's own label, between its dates. The fourth kind,
 * a discount's sale tag, comes from the product's applied discounts, and {@see self::contribute()} puts all four on the
 * product's surface slots: one item.labels descriptor per slot, per corner in the image slot, so the labels of one
 * corner stack one under the other and a template keeps rendering its slots as before.
 *
 * What the catalogue list pays: one query for the page's products' ticks ({@see self::warm()},
 * ItemLabels::forItems()) and one for the shop's published labels, once a request ({@see self::predefined()}); the New
 * check and the product's own label read the created, publish_up and label_settings columns the list already selects.
 *
 * @since  2.3.0
 */
final class Labels
{
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
     * The key of the automatic New label.
     *
     * @since  2.3.0
     */
    public const KEY_NEW = 'new';

    /**
     * The key of a product's own label.
     *
     * @since  2.3.0
     */
    public const KEY_CUSTOM = 'custom';

    /**
     * The shop's published labels, by id, in their order; null until read.
     *
     * @var array<int, Label>|null
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
     * The products' ticks read so far, by product id then by label id ([] for a product with none).
     *
     * @var array<int, array<int, string|null>>
     * @since  2.3.0
     */
    private static array $rows = [];

    /**
     * The shop's labels a product may show now: published and within their publishing period, by id, in the order of
     * the Labels screen. Their names are in the request's language.
     *
     * @return array<int, Label>
     *
     * @since  2.3.0
     */
    public static function predefined(): array
    {
        if (self::$predefined !== null) {
            return self::$predefined;
        }

        $labels = [];

        foreach (self::records(publishedOnly: true) as $row) {
            $labels[(int) $row->id] = self::fromRecord($row);
        }

        return self::$predefined = $labels;
    }

    /**
     * Every label of the Labels screen that is not trashed or archived, by id, in their order, with its state: what a
     * product's Labels tab offers to tick, an unpublished one among them.
     *
     * @return array<int, object{id: int, state: int, label: Label}>
     *
     * @since  2.3.0
     */
    public static function all(): array
    {
        $labels = [];

        foreach (self::records(publishedOnly: false) as $row) {
            $labels[(int) $row->id] = (object) ['id' => (int) $row->id, 'state' => (int) $row->state, 'label' => self::fromRecord($row)];
        }

        return $labels;
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
            self::$new = Label::fromSettings(key: self::KEY_NEW, kind: Label::KIND_NEW, settings: $settings);
        }

        return self::$new;
    }

    /**
     * Read the ticks of these products in one query, for the products not read yet; the list calls it once for its
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
     * The labels a product shows in a context, in order: the New label, the shop's labels it ticks, its own label.
     * A label not flagged for the context, or without a text, is left out; the product's own label outside its dates
     * too. The sale tags are not among them: a discount's tag is the discount's ({@see self::contribute()}).
     *
     * @param object $item The product (id, created, publish_up, label_settings).
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
        $ticks = self::$rows[$itemId];
        $settings = ItemLabels::settingsOf($item->label_settings ?? null);
        $now ??= ActiveDateCondition::now();
        $labels = [];

        $new = self::newLabel();

        if ($new !== null && $settings['new']['shown'] === 1 && NewProducts::isNew(created: $item->created ?? null, publishUp: $item->publish_up ?? null, now: $now)) {
            $labels[] = self::placed($new, $settings['new']['corner']);
        }

        foreach (self::predefined() as $labelId => $label) {
            if (\array_key_exists($labelId, $ticks)) {
                $labels[] = self::placed($label, $ticks[$labelId]);
            }
        }

        $own = $settings['own'];

        if ($own !== null && self::within(settings: $own, now: $now)) {
            $labels[] = Label::fromSettings(key: self::KEY_CUSTOM, kind: Label::KIND_CUSTOM, settings: $own);
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
     * The labels as the REST API states them: key, name and corner each (a shop label's key is its id).
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
     * Forget what was read: the shop's labels and the products' ticks. For a process that changes the labels, the
     * Options or the ticks and reads again.
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
     * The rows of the Labels screen, in their order, each with its name in the request's language: the published ones
     * within their period, or every one that is not archived or trashed.
     *
     * @param bool $publishedOnly Only the labels a product may show now.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function records(bool $publishedOnly): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.state', 'a.bg', 'a.color', 'a.class', 'a.list_show', 'a.list_slot', 'a.item_show', 'a.item_slot', 'a.corner', 'a.filterable']))
            ->from($db->quoteName('#__alfa_labels', 'a'))
            ->order($db->quoteName('a.ordering') . ' ASC')
            ->order($db->quoteName('a.id') . ' ASC');

        if ($publishedOnly) {
            $now = $db->quote(ActiveDateCondition::now());
            $query->where($db->quoteName('a.state') . ' = 1')
                ->where('(' . $db->quoteName('a.publish_up') . ' IS NULL OR ' . $db->quoteName('a.publish_up') . ' <= ' . $now . ')')
                ->where('(' . $db->quoteName('a.publish_down') . ' IS NULL OR ' . $db->quoteName('a.publish_down') . ' >= ' . $now . ')');
        } else {
            $query->where($db->quoteName('a.state') . ' IN (0, 1)');
        }

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_labels',
            langPrimaryColumn: 'id_label',
            fields:            ['name'],
        );

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * A shop label from its row: its key is its id.
     *
     * @param object $row The row, its name resolved.
     *
     * @since  2.3.0
     */
    private static function fromRecord(object $row): Label
    {
        return Label::fromSettings(key: (string) (int) $row->id, kind: Label::KIND_PREDEFINED, settings: [
            'name' => (string) ($row->name ?? ''),
            'bg' => $row->bg,
            'color' => $row->color,
            'class' => $row->class,
            'list_show' => $row->list_show,
            'list_slot' => $row->list_slot,
            'item_show' => $row->item_show,
            'item_slot' => $row->item_slot,
            'corner' => $row->corner,
        ]);
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
     * The label in the corner the product chose for it, or as it is.
     *
     * @param Label $label The label.
     * @param string|null $corner The product's corner for it; NULL or '' for the label's own.
     *
     * @since  2.3.0
     */
    private static function placed(Label $label, ?string $corner): Label
    {
        $chosen = LabelCorner::tryFrom(trim((string) $corner));

        return $chosen === null ? $label : $label->inCorner($chosen);
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
     * One label's settings as an array, its name map an array too: the Registry hands a subform's value as an object.
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
