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
 * Inventory location table. Locations are DATA: rows carry the semantic
 * axes (location_type, capabilities) the engine asks about; behavior
 * never depends on a location's existence or identity. Stock quantities
 * are NOT here: they live in the per-location stock rows, written only
 * by the inventory service.
 *
 * @since  2.0.0
 */
class LocationTable extends Table
{
    /**
     * Content type alias for history/tags integrations.
     *
     * @var string
     */
    public $typeAlias = 'com_alfa.location';

    /**
     * Bind the table to its database table.
     *
     * @param DatabaseDriver $db Database connector.
     *
     * @since  2.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__alfa_inventory_locations', 'id', $db);

        // List-view publish/unpublish writes through the generic
        // 'published' alias; our visibility column is 'state'.
        $this->setColumnAlias('published', 'state');
    }
}
