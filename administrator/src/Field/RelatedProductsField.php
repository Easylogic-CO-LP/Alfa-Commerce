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
use Alfa\Component\Alfa\Administrator\Service\Catalog\RelatedLink;
use Joomla\CMS\Form\Field\SubformField;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;
use SimpleXMLElement;

/**
 * The Related tab's list: Joomla's repeatable subform of related_link.xml rows (a product picker and its variant cell,
 * {@see RelatedVariantField}), added, removed and moved as any subform's rows, drawn by its own layout,
 * form.field.relatedproducts, which a template overrides at html/layouts/com_alfa/form/field/relatedproducts.php.
 *
 * Its value, read and saved, is the item record's `related`: a list of {item_id, combination_id (0 for none), values
 * (field id to value id)}, one row a link ({@see RelatedItems::rows()}). The rows come from three places and are read
 * by this field alone ({@see self::filter()}): the tab as posted (each row's variant cell), a REST body or a write built
 * in code (the record's own shape, or {@see RelatedLink} values). What a row names is judged by the product save
 * ({@see RelatedItems::refusals()}).
 *
 * @since  2.3.0
 */
class RelatedProductsField extends SubformField
{
    /**
     * The form field type.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'RelatedProducts';

    /**
     * A row's form.
     *
     * @since  2.3.0
     */
    public const ROW_FORM = 'administrator/components/com_alfa/forms/related_link.xml';

    /**
     * The rows: a repeatable subform of related_link.xml, drawn by form.field.relatedproducts.
     *
     * @param SimpleXMLElement $element The field's XML element.
     * @param mixed $value The record's related rows.
     * @param string|null $group The field's group.
     *
     * @since  2.3.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $element['multiple'] = 'true';
        $element['formsource'] = self::ROW_FORM;
        $element['layout'] = 'form.field.relatedproducts';
        $element['buttons'] = 'add,remove,move';
        // The layout is com_alfa's, whichever component the page that draws the form is.
        $element['component'] = 'com_alfa';
        $element['client'] = 'administrator';

        if (!parent::setup($element, $value, $group)) {
            return false;
        }

        // Each row as its form binds it: the product, and the variant cell's own value.
        $this->value = array_map(
            static fn (array $row): array => ['item_id' => $row['item_id'], 'variant' => ['combination_id' => $row['combination_id'], 'values' => $row['values']]],
            self::rows(value: $this->value),
        );

        return true;
    }

    /**
     * The rows as the record holds them, whichever way they came.
     *
     * @param mixed $value The posted value.
     * @param string|null $group The field's group.
     * @param Registry|null $input The whole form's data.
     *
     * @return list<array{item_id: int, combination_id: int, values: array<int, int>}>
     *
     * @since  2.3.0
     */
    public function filter($value, $group = null, ?Registry $input = null)
    {
        return self::rows(value: $value);
    }

    /**
     * Rows of the record's shape from the tab's post ({item_id, variant}), the record's own rows, plain objects or
     * links. A row naming nothing (a picker left empty) is kept as an empty row, so a refusal names each row by its
     * place as it was sent; the save skips it.
     *
     * @param mixed $value The value.
     *
     * @return list<array{item_id: int, combination_id: int, values: array<int, int>}>
     *
     * @since  2.3.0
     */
    public static function rows(mixed $value): array
    {
        if (\is_string($value)) {
            $value = $value === '' ? [] : json_decode($value, true);
        }

        // The form's filter hands the post through a Registry, which turns every keyed array into an object.
        $value = self::plain(value: $value);
        $rows = [];

        foreach (\is_array($value) ? $value : [] as $row) {
            if ($row instanceof RelatedLink) {
                $rows[] = $row->toRow();

                continue;
            }

            $row = \is_array($row) ? $row : ['item_id' => $row];
            $variant = \is_array($row['variant'] ?? null) ? $row['variant'] : null;
            $own = $variant === null ? $row : RelatedVariantField::posted(variant: $variant);
            $values = [];

            foreach (\is_array($own['values'] ?? null) ? $own['values'] : [] as $fieldId => $valueId) {
                // A value that is not one id (a list, an object) names no value of its field and is refused as such.
                $values[(int) $fieldId] = is_numeric($valueId) ? (int) $valueId : 0;
            }

            ksort($values);
            $rows[] = [
                'item_id' => is_numeric($row['item_id'] ?? null) ? (int) $row['item_id'] : 0,
                'combination_id' => is_numeric($own['combination_id'] ?? null) ? max(0, (int) $own['combination_id']) : 0,
                'values' => $values,
            ];
        }

        return $rows;
    }

    /**
     * A value with every object in it, at any depth, as an array; a link stays a link.
     *
     * @param mixed $value The value.
     *
     * @since  2.3.0
     */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof RelatedLink) {
            return $value;
        }

        if (\is_object($value)) {
            $value = get_object_vars($value);
        }

        return \is_array($value) ? array_map(static fn (mixed $item): mixed => self::plain(value: $item), $value) : $value;
    }

    /**
     * The layout's data: the subform's, and the address a row's variant cell is drawn again from when its product
     * changes ({@see \Alfa\Component\Alfa\Administrator\Controller\ItemController::relatedVariant()}).
     *
     * @since  2.3.0
     */
    protected function getLayoutData()
    {
        return array_merge(parent::getLayoutData(), [
            'variantUrl' => Route::_('index.php?option=com_alfa&task=item.relatedVariant&format=json&' . Session::getFormToken() . '=1', false),
        ]);
    }
}
