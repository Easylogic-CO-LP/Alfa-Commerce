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

/**
 * Notification table.
 *
 * @since  1.0.0
 */
class NotificationTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database.
     *
     * @var bool
     * @since  1.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor.
     *
     * @param DatabaseDriver $db A database connector object.
     *
     * @since  1.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.notification';
        parent::__construct('#__alfa_notifications', 'id', $db);
    }
}
