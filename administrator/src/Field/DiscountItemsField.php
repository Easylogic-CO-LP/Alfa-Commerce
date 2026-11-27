<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\SubformField;
use Joomla\Registry\Registry;
use SimpleXMLElement;

/**
 * A list of products on a discount rule (its product scope, its excluded products): Joomla's repeatable subform of
 * discount_item.xml rows, one product picker a row, added and removed as any subform's rows and drawn by Joomla's own
 * table layout. Its value, read and saved, is a list of product ids, the shape every other scope part has (categories,
 * manufacturers): the form posts rows {item_id}, a REST body or a write built in code gives ids, and this field reads
 * both ({@see self::ids()}); the form is shown the ids as rows. A row left empty names nothing and is dropped.
 *
 * @since  2.3.0
 */
class DiscountItemsField extends SubformField
{
    /**
     * The form field type.
     *
     * @var string
     *
     * @since  2.3.0
     */
    protected $type = 'DiscountItems';

    /**
     * A row's form.
     *
     * @since  2.3.0
     */
    public const ROW_FORM = 'administrator/components/com_alfa/forms/discount_item.xml';

    /**
     * The rows: a repeatable subform of discount_item.xml.
     *
     * @param SimpleXMLElement $element The field's XML element.
     * @param mixed $value The record's product ids, or the posted rows.
     * @param string|null $group The field's group.
     *
     * @since  2.3.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $element['multiple'] = 'true';
        $element['formsource'] = self::ROW_FORM;
        $element['buttons'] = 'add,remove';

        if ((string) $element['layout'] === '') {
            $element['layout'] = 'joomla.form.field.subform.repeatable-table';
        }

        if (!parent::setup($element, $value, $group)) {
            return false;
        }

        // Each product as its row binds it.
        $this->value = array_map(static fn (int $id): array => ['item_id' => $id], self::ids(value: $this->value));

        return true;
    }

    /**
     * The product ids the value names, whichever way they came.
     *
     * @param mixed $value The posted value.
     * @param string|null $group The field's group.
     * @param Registry|null $input The whole form's data.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    public function filter($value, $group = null, ?Registry $input = null)
    {
        return self::ids(value: $value);
    }

    /**
     * The product ids a value names: rows {item_id} as the subform posts them, ids as the record and a REST body hold
     * them, or a JSON text of either. Each positive id once, in the order given; a row naming nothing is dropped.
     *
     * @param mixed $value The value.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    public static function ids(mixed $value): array
    {
        if (\is_string($value)) {
            $value = $value === '' ? [] : json_decode($value, true);
        }

        // The form's filter hands the post through a Registry, which turns every keyed array into an object.
        if (\is_object($value)) {
            $value = get_object_vars($value);
        }

        $ids = [];

        foreach (\is_array($value) ? $value : [] as $row) {
            if (\is_object($row)) {
                $row = get_object_vars($row);
            }

            $id = \is_array($row) ? ($row['item_id'] ?? 0) : $row;

            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }
}
