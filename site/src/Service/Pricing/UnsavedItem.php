<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

/**
 * A product as its item form holds it before it is saved, as far as pricing is
 * concerned: the categories and manufacturers the form holds now.
 *
 * A saved product reaches the pricing engine through what it has stored: its
 * category and manufacturer links decide which taxes and discounts apply
 * (PriceDataLoader matches every rule's scope against them), and its own price
 * rows give the base an adjustment applies to. A product being created has
 * none of them stored, and a saved product being edited may hold other
 * memberships in its form than it has stored. PriceDataLoader::forUnsavedItem()
 * loads the taxes and discounts with these memberships in place of the link
 * tables, so the same queries and the same engine price the product as it
 * will be once saved; everything else (its stored price rows) is read as
 * stored. A product not saved yet (id 0) has no stored price row.
 *
 * The memberships are the ones a save of the same form stores: the default
 * category joins the categories, and each id is kept once (ItemModel::save(),
 * AlfaHelper::setAssocsToDb()). Only positive ids are kept: an id is a row.
 *
 * @since  2.1.0
 */
final class UnsavedItem
{
    /**
     * @param int $id The product's id; 0 for a product not saved yet.
     * @param int[] $categoryIds The categories, the default category among them.
     * @param int[] $manufacturerIds The manufacturers.
     *
     * @since  2.1.0
     */
    private function __construct(
        private readonly int $id,
        private readonly array $categoryIds,
        private readonly array $manufacturerIds,
    ) {
    }

    /**
     * The product of an item form as posted: its default category, its
     * categories and its manufacturers.
     *
     * @param mixed $defaultCategory The default category id ('' when none is chosen).
     * @param mixed $categories The category ids (a list, or one id).
     * @param mixed $manufacturers The manufacturer ids (a list, or one id).
     * @param int $itemId The product's id; 0 (the default) for a product not saved yet.
     *
     * @since  2.1.0
     */
    public static function fromForm(mixed $defaultCategory, mixed $categories, mixed $manufacturers, int $itemId = 0): self
    {
        return new self(
            id: max(0, $itemId),
            categoryIds: self::ids(values: array_merge((array) $categories, (array) $defaultCategory)),
            manufacturerIds: self::ids(values: (array) $manufacturers),
        );
    }

    /**
     * The product's id: 0 for a product not saved yet.
     *
     * @since  2.1.0
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * The categories, the default category among them.
     *
     * @return int[]
     *
     * @since  2.1.0
     */
    public function categoryIds(): array
    {
        return $this->categoryIds;
    }

    /**
     * The manufacturers.
     *
     * @return int[]
     *
     * @since  2.1.0
     */
    public function manufacturerIds(): array
    {
        return $this->manufacturerIds;
    }

    /**
     * Whether the product has a category: without one, no tax or discount can
     * be matched to it, and the item form cannot be saved.
     *
     * @since  2.1.0
     */
    public function hasCategory(): bool
    {
        return $this->categoryIds !== [];
    }

    /**
     * A key that tells two unsaved products apart by everything they price under.
     *
     * @since  2.1.0
     */
    public function cacheKey(): string
    {
        $categories = $this->categoryIds;
        $manufacturers = $this->manufacturerIds;
        sort($categories);
        sort($manufacturers);

        return 'unsaved:' . $this->id . ':' . implode(',', $categories) . '|' . implode(',', $manufacturers);
    }

    /**
     * Each positive id once, in the order given.
     *
     * @param array $values The posted values.
     *
     * @return int[]
     *
     * @since  2.1.0
     */
    private static function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            $id = \is_scalar($value) ? (int) $value : 0;

            if ($id > 0 && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
