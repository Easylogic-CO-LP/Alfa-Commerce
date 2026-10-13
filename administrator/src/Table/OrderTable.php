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

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;
use RuntimeException;

/**
 * Order table
 *
 * @since  1.0.0
 */
class OrderTable extends Table
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
     * @param Database &$db A database connector object
     * @since  1.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.order';
        parent::__construct('#__alfa_orders', 'id', $db);
        $this->setColumnAlias('published', 'state');
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
        $this->guardMonetaryIdentity();

        return parent::store($updateNulls);
    }

    /**
     * AN ORDER'S CURRENCY AND ITS MONETARY SCALE ARE WRITE-ONCE.
     *
     * THE ONE PLACE EVERY WRITER PASSES, and that is why the guard is here
     * rather than on a form or in a model. `forms/order.xml` marked the field
     * `readonly="true"`, but the string `readonly` appears NOWHERE in Joomla's
     * Form class: only `disabled` is enforced, and a readonly input is still
     * submitted by the browser. The contrast inside this component is the
     * evidence it was an oversight: `OrderModel::getForm()` gives `created_by`
     * and `id_user` an explicit `filter => 'unset'` to keep them out of the
     * save, and `id_currency` was given nothing. The admin save, the REST
     * PATCH and any programmatic writer all reach this method, so this is the
     * only place the rule can be stated once.
     *
     * WHY IT IS A RULE AND NOT A PREFERENCE. Every monetary fact an order
     * publishes (grand total, balance, paid state, payment status, and the
     * status transitions and document auto-issue they drive) is RE-DERIVED
     * from stored money through the currency's scale. Repointing the currency
     * silently re-denominates the whole order, and clearing it leaves an order
     * that names none, which nothing can price, take payment for or invoice.
     *
     * A row with no stored scale is a pre-v1 record with no authority to
     * protect; it is left alone rather than given one, because inferring
     * historical scale from today's configuration is the defect, not the cure.
     *
     *
     * @throws RuntimeException When an existing order's currency or scale is changed or cleared.
     *
     * @since  2.0.0
     */
    private function guardMonetaryIdentity(): void
    {
        $id = (int) ($this->id ?? 0);

        if ($id <= 0) {
            // Creation states the currency and freezes the scale. That is the
            // one moment either may be written.
            return;
        }

        $db = $this->getDbo();
        $stored = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('id_currency'), $db->quoteName('currency_scale')])
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = ' . $id),
        )->loadObject();

        if ($stored === null) {
            return;
        }

        $storedCurrency = (int) $stored->id_currency;

        if (property_exists($this, 'id_currency')) {
            $incoming = $this->id_currency;

            // NULL is refused explicitly and by name. A cleared readonly input
            // posts '' and the model's nullable list turns it into NULL, which
            // is not "leave it alone": it leaves the order naming no currency.
            if ($incoming === null || $incoming === '') {
                throw new RuntimeException(
                    'An order\'s currency cannot be cleared once the order exists (order ' . $id . ').',
                );
            }

            if ((int) $incoming !== $storedCurrency) {
                throw new RuntimeException(
                    'An order\'s currency is write-once and cannot be changed after creation (order ' . $id
                    . ': ' . $storedCurrency . ' -> ' . (int) $incoming . ').',
                );
            }
        }

        if ($stored->currency_scale !== null && property_exists($this, 'currency_scale')) {
            $incomingScale = $this->currency_scale;

            // null, never a falsy test: 0 is a real zero-decimal scale.
            if ($incomingScale !== null && (int) $incomingScale !== (int) $stored->currency_scale) {
                throw new RuntimeException(
                    'An order\'s frozen monetary scale cannot be changed (order ' . $id
                    . ': ' . (int) $stored->currency_scale . ' -> ' . (int) $incomingScale . ').',
                );
            }
        }
    }
}
