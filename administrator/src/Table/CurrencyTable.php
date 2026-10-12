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

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * Currency table
 *
 * @since  1.0.0
 */
class CurrencyTable extends Table
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
        $this->typeAlias = 'com_alfa.currency';
        parent::__construct('#__alfa_currencies', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    /**
     * Validate the row before it is stored.
     *
     * THE persistence gate for a currency, and the one every writer shares: the
     * admin form, the REST route (v1/alfa/currencies resolves to the
     * Administrator model through the API MVC factory) and any programmatic save
     * all arrive here, so a friendly message elsewhere can never be the only
     * check.
     *
     * What it enforces is generic, never ISO 4217. `decimal_place` is Alfa's
     * authoritative monetary scale and the merchant chooses it (EUR at 0, 2 or
     * 4 places is a configuration, not a mistake), so the only questions asked
     * are whether the code is a code and whether the scale is one Alfa can carry
     * end to end. A custom code is welcome; it must simply be three letters,
     * because that is what the column holds and what every lookup by code
     * matches on.
     *
     * @return bool True when the row may be stored.
     *
     * @since  1.10.0
     */
    public function check()
    {
        if (!parent::check()) {
            return false;
        }

        $this->code = strtoupper(trim((string) ($this->code ?? '')));

        if (preg_match('/^[A-Z]{3}$/', $this->code) !== 1) {
            $this->setError('A currency code must be exactly three letters.');

            return false;
        }

        $scale = \is_string($this->decimal_place ?? null) ? trim($this->decimal_place) : $this->decimal_place ?? null;

        if (
            !is_numeric($scale)
            || (string) (int) $scale !== (string) $scale
            || (int) $scale < Currency::SCALE_MIN
            || (int) $scale > Currency::SCALE_MAX
        ) {
            $this->setError(sprintf(
                'Decimal places must be a whole number between %d and %d.',
                Currency::SCALE_MIN,
                Currency::SCALE_MAX,
            ));

            return false;
        }

        $this->decimal_place = (int) $scale;

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
        // Forward $updateNulls (every sibling table does): #__alfa_currencies has many
        // nullable columns and sets $_supportNullValue, so a cleared field must persist
        // as NULL rather than being silently kept at its old value.
        return parent::store($updateNulls);
    }
}
