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

use Alfa\Component\Alfa\Administrator\Service\Catalog\PublishPeriod;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * Tax table
 *
 * @since  1.0.0
 */
class TaxTable extends Table
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
        $this->typeAlias = 'com_alfa.tax';
        parent::__construct('#__alfa_taxes', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    /**
     * A tax whose period ends at or before its start would never apply: refused for every writer that reaches the
     * table, the admin screen and the REST route alike, as the price rows and the discounts refuse it.
     *
     * @return bool True when the row may be stored.
     *
     * @since  2.3.0
     */
    public function check()
    {
        if (!parent::check()) {
            return false;
        }

        if (!PublishPeriod::endsAfterStart(start: $this->publish_up ?? null, end: $this->publish_down ?? null)) {
            $this->setError(Text::_('COM_ALFA_TAX_PERIOD_END_BEFORE_START'));

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
