<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedItems;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;

/**
 * A related row's variant cell: what the linked product's card opens on. A Variant select (the product's default, each
 * current combination by its grid label, an unpublished one marked so, or Choose values), a select for each dimension
 * of the product's combinations (Any, then its values) used by Choose values, and a select for each option field the
 * product offers (None, then its values) for every choice. It reads its row's product (the row's item_id); a row
 * with no product yet draws a hint. Drawn by form.field.relatedvariant, which a template overrides at
 * html/layouts/com_alfa/form/field/relatedvariant.php.
 *
 * It posts <name>[combination] ('' for the default, a combination id, or 'choose'), <name>[dims][<field id>] and
 * <name>[options][<field id>], read by {@see self::posted()}; the row's value is {combination_id, values}.
 *
 * @since  2.3.0
 */
class RelatedVariantField extends FormField
{
    /**
     * The form field type.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'RelatedVariant';

    /**
     * The layout.
     *
     * @var string
     * @since  2.3.0
     */
    protected $layout = 'form.field.relatedvariant';

    /**
     * The Variant select's value for Choose values.
     *
     * @since  2.3.0
     */
    public const CHOOSE = 'choose';

    /**
     * The cell's value from what it posts: Choose values takes the dimensions' selects and the options', a combination
     * or the default the options' only.
     *
     * @param array $variant The cell's post: combination, dims, options.
     *
     * @return array{combination_id: int, values: array<int, int>}
     *
     * @since  2.3.0
     */
    public static function posted(array $variant): array
    {
        $choice = (string) ($variant['combination'] ?? '');
        $values = [];
        $lists = $choice === self::CHOOSE ? [$variant['dims'] ?? [], $variant['options'] ?? []] : [$variant['options'] ?? []];

        foreach ($lists as $list) {
            foreach (\is_array($list) ? $list : [] as $fieldId => $valueId) {
                if ((string) $valueId !== '') {
                    $values[(int) $fieldId] = is_numeric($valueId) ? (int) $valueId : 0;
                }
            }
        }

        return [
            'combination_id' => ctype_digit($choice) ? (int) $choice : 0,
            'values' => $values,
        ];
    }

    /**
     * The layout's data: the row's product, what it offers ({@see RelatedItems::choices()}) and the cell's value.
     *
     * @since  2.3.0
     */
    protected function getLayoutData()
    {
        $itemId = (int) ($this->form?->getValue('item_id') ?? 0);
        $value = \is_array($this->value) ? $this->value : [];
        $combinationId = (int) ($value['combination_id'] ?? 0);
        $values = array_map('intval', (array) ($value['values'] ?? []));
        $choices = RelatedItems::choices(db: Factory::getContainer()->get('DatabaseDriver'), itemId: $itemId);
        $dimensionIds = array_map(static fn (array $field): int => $field['id'], $choices['dimensions']);
        $chosenDimensions = array_intersect_key($values, array_flip($dimensionIds));

        return array_merge(parent::getLayoutData(), [
            'itemId' => $itemId,
            'choices' => $choices,
            'choice' => $combinationId > 0 ? (string) $combinationId : ($chosenDimensions !== [] ? self::CHOOSE : ''),
            'values' => $values,
            'control' => $this->name,
        ]);
    }
}
