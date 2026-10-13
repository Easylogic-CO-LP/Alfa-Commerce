<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Table;

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * Item table
 *
 * @since  1.0.0
 */
class DiscountTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database
     *
     * @var bool
     * @since  1.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor
     *
     * @param JDatabase &$db A database connector object
     * @since  1.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.discount';
        parent::__construct('#__alfa_discounts', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    /**
     * Enforce the discount value contract for every writer that reaches the table.
     *
     * The form rule says the same thing with a field-level message; this holds when
     * a caller stores a row without going through Form::validate(), which is how a
     * programmatic save reaches this table. AdminModel::save() turns the refusal
     * into the model's own error, so the admin screen and the REST route both read
     * it without a second message.
     *
     * @return bool True when the row may be stored.
     *
     * @since  2.0.0
     */
    public function check()
    {
        if (!parent::check()) {
            return false;
        }

        if (!is_numeric($this->value ?? null)) {
            $this->setError(Text::_('COM_ALFA_ERROR_DISCOUNT_VALUE_NOT_A_NUMBER'));

            return false;
        }

        $value = (float) $this->value;

        if ($value < 0) {
            $this->setError(Text::_('COM_ALFA_ERROR_DISCOUNT_VALUE_NEGATIVE'));

            return false;
        }

        // One column, two meanings: only the percentage reading has a ceiling, and a
        // NULL is_amount IS the percentage reading, exactly as the engine reads it.
        if ((int) ($this->is_amount ?? 0) !== 1 && $value > 100) {
            $this->setError(Text::_('COM_ALFA_ERROR_DISCOUNT_PERCENT_RANGE'));

            return false;
        }

        return true;
    }

    /**
     * Method to store a row in the database from the Table instance properties.
     *
     * If a primary key value is set the row with that primary key value will be updated with the instance property values.
     * If no primary key value is set a new row will be inserted into the database with the properties from the Table instance.
     *
     * @param bool $updateNulls True to update fields even if they are null.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function store($updateNulls = true)
    {
        return parent::store($updateNulls);
    }
}
