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
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemSurface;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;

/**
 * One label a product shows: a short text in a badge, with its colours, an extra class for the template, whether and
 * where it shows on a catalogue card and on the product page, and its corner of the image. Four kinds wear the same
 * badge (the item.label layout): a discount's sale tag, the automatic New label, one of the shop's predefined labels
 * (Options > Labels) and a product's own label. {@see Labels} decides which labels a product shows.
 *
 * @since  2.3.0
 */
final readonly class Label
{
    /**
     * A discount's sale tag: its customer name, flagged on the discount.
     *
     * @since  2.3.0
     */
    public const KIND_SALE = 'sale';

    /**
     * The automatic label of a new product (NewProducts), set once in Options > General > Products.
     *
     * @since  2.3.0
     */
    public const KIND_NEW = 'new';

    /**
     * One of the shop's predefined labels (Options > Labels), ticked on the product.
     *
     * @since  2.3.0
     */
    public const KIND_PREDEFINED = 'predefined';

    /**
     * A product's own label, kept with the product, shown between its dates.
     *
     * @since  2.3.0
     */
    public const KIND_CUSTOM = 'custom';

    /**
     * @param string $key What names the label: new, a predefined label's key, custom, or sale-<discount id>.
     * @param string $kind One of the KIND_ constants.
     * @param string $name The text shown, in the request's language.
     * @param string $bg The background colour (CSS); '' = the storefront's token.
     * @param string $color The text colour (CSS); '' = the storefront's token.
     * @param string $class Extra CSS class(es) on the badge; '' = none.
     * @param bool $listShow Whether it shows on a catalogue card.
     * @param string $listSlot The card's slot (an ItemListSurface value).
     * @param bool $itemShow Whether it shows on the product page.
     * @param string $itemSlot The page's slot (an ItemSurface value).
     * @param LabelCorner $corner Its corner of the image, when its slot is the image.
     *
     * @since  2.3.0
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $name,
        public string $bg,
        public string $color,
        public string $class,
        public bool $listShow,
        public string $listSlot,
        public bool $itemShow,
        public string $itemSlot,
        public LabelCorner $corner,
    ) {
    }

    /**
     * A label from its stored settings: a row of Options > Labels, the Options' New label, or a product's own label.
     * The name is a text by language ({@see MultilingualHelper::resolveLangMap()}) or a plain text.
     *
     * @param string $key The label's key.
     * @param string $kind One of the KIND_ constants.
     * @param array<string, mixed> $settings name, bg, color, class, list_show, list_slot, item_show, item_slot, corner.
     *
     * @since  2.3.0
     */
    public static function fromSettings(string $key, string $kind, array $settings): self
    {
        $name = $settings['name'] ?? '';

        return new self(
            key: $key,
            kind: $kind,
            name: trim(MultilingualHelper::resolveLangMap(\is_object($name) ? (array) $name : $name)),
            bg: trim((string) ($settings['bg'] ?? '')),
            color: trim((string) ($settings['color'] ?? '')),
            class: trim((string) ($settings['class'] ?? '')),
            listShow: (bool) ($settings['list_show'] ?? 1),
            listSlot: (string) ($settings['list_slot'] ?? ItemListSurface::Image->value),
            itemShow: (bool) ($settings['item_show'] ?? 1),
            itemSlot: (string) ($settings['item_slot'] ?? ItemSurface::Image->value),
            corner: LabelCorner::of(isset($settings['corner']) ? (string) $settings['corner'] : null),
        );
    }

    /**
     * Whether the label shows in a context: it has a text and is flagged for a card or for the product page.
     *
     * @param bool $isListing True for a catalogue card, false for the product page.
     *
     * @since  2.3.0
     */
    public function shownOn(bool $isListing): bool
    {
        return $this->name !== '' && ($isListing ? $this->listShow : $this->itemShow);
    }

    /**
     * The slot the label lands in, validated against the context's own surface enum; a stored value that is not a
     * slot of this context lands in the image.
     *
     * @param bool $isListing True for a catalogue card, false for the product page.
     *
     * @since  2.3.0
     */
    public function slotFor(bool $isListing): ItemListSurface|ItemSurface
    {
        return $isListing
            ? (ItemListSurface::tryFrom($this->listSlot) ?? ItemListSurface::Image)
            : (ItemSurface::tryFrom($this->itemSlot) ?? ItemSurface::Image);
    }

    /**
     * The same label in another corner: a product's own choice over the label's.
     *
     * @param LabelCorner $corner The corner.
     *
     * @since  2.3.0
     */
    public function inCorner(LabelCorner $corner): self
    {
        return new self(
            key: $this->key,
            kind: $this->kind,
            name: $this->name,
            bg: $this->bg,
            color: $this->color,
            class: $this->class,
            listShow: $this->listShow,
            listSlot: $this->listSlot,
            itemShow: $this->itemShow,
            itemSlot: $this->itemSlot,
            corner: $corner,
        );
    }

    /**
     * The label as the REST API states it: its key, its text and its corner.
     *
     * @return array{key: string, name: string, corner: string}
     *
     * @since  2.3.0
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'corner' => $this->corner->value];
    }
}
