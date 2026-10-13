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
 * Item field value table.
 *
 * Backs the global value pool (#__alfa_item_field_values). The translatable
 * name/alias live in #__alfa_item_field_values_<langtag>; presentational
 * metadata (swatch color, images, ...) lives in the plugin-owned params JSON.
 *
 * @since  2.0.0
 */
class ItemfieldvalueTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database
     *
     * @var bool
     * @since  2.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor
     *
     * @param DatabaseDriver $db A database connector object
     *
     * @since  2.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.itemfieldvalue';
        parent::__construct('#__alfa_item_field_values', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }
}
