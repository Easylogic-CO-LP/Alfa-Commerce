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
 * Cart rule table.
 *
 * @since  2.0.0
 */
class CartruleTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database:
     * the rule's `code` MUST stay NULL for automatic rules (an empty string
     * would collide on the unique key and break the coupon semantics).
     *
     * @var bool
     * @since  2.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor
     *
     * @param DatabaseDriver $db A database connector object.
     *
     * @since  2.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.cartrule';
        parent::__construct('#__alfa_cart_rules', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    /**
     * Normalise the discount base before validation.
     *
     * A line-item rule ALWAYS measures on its matching lines: the engine takes
     * that branch whatever `discount_base` says, so "Whole cart" is a choice
     * with no effect. Rather than store a value that lies about the behaviour,
     * fold it onto the one that describes it. The merchant sees their choice
     * corrected to "Matching lines" on reload, which is also the clearest way
     * to teach the rule.
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

        if ((string) $this->discount_class === 'product' && (string) $this->discount_base === 'cart') {
            $this->discount_base = 'matching';
        }

        return true;
    }

    /**
     * Normalise before storing: a blank code becomes NULL (automatic rule).
     *
     * @param bool $updateNulls True to update fields even if they are null.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function store($updateNulls = true)
    {
        if (isset($this->code) && trim((string) $this->code) === '') {
            $this->code = null;
        } elseif (isset($this->code)) {
            $this->code = strtoupper(trim((string) $this->code));
        }

        return parent::store($updateNulls);
    }
}
