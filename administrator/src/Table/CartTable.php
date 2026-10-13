<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Table;

defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * Cart row.
 *
 * The cart is operational state: it exists while a shopper is assembling an
 * order and is deleted when that order is placed. It carries no workflow
 * columns (no published state, no checked_out, no ordering), so this table
 * deliberately declares none of the aliases the catalogue tables use.
 *
 * `added` and `updated` are set explicitly rather than by the table's own
 * hooks. `updated` is the abandonment clock the Carts screen and the retention
 * policy both read, so it must move only when the shopper's cart actually
 * changes, never as a side effect of a row being rewritten.
 *
 * @since  2.0.0
 */
class CartTable extends Table
{
    /**
     * Constructor.
     *
     * @param DatabaseDriver $db A database connector object.
     *
     * @since  2.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.cart';

        parent::__construct('#__alfa_cart', 'id', $db);
    }

    /**
     * Validate the row before it is stored.
     *
     * Two invariants the schema cannot express on its own:
     *
     * - a cart belongs to exactly one shopper, identified either by a member
     *   account or by the browser key, never by neither;
     * - the browser key is the value the cart lookup matches on, so a malformed
     *   one produces a row nothing can ever find again.
     *
     * @return bool True when the row may be stored.
     *
     * @since  2.0.0
     */
    public function check()
    {
        $this->id_customer = (int) $this->id_customer;
        $this->recognize_key = trim((string) $this->recognize_key);

        if ($this->id_customer <= 0 && $this->recognize_key === '') {
            $this->setError('A cart needs an owner: a member id or a browser key.');

            return false;
        }

        if ($this->recognize_key !== '' && preg_match('/^[0-9a-f]{64}$/', $this->recognize_key) !== 1) {
            $this->setError('The browser key must be 64 lowercase hexadecimal characters.');

            return false;
        }

        return true;
    }
}
