<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

/**
 * Inventory locations list controller. The model guards the structural
 * promises (default location undeletable/always published; footprint
 * rows disable-only).
 *
 * @since  2.0.0
 */
class LocationsController extends AdminController
{
    /**
     * Proxy to the edit model.
     *
     * @param string $name Model name.
     * @param string $prefix Model prefix.
     * @param array $config Configuration array.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @since  2.0.0
     */
    public function getModel($name = 'Location', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Enable or disable the selected locations. A location is enabled or disabled, the location form's own words,
     * never "published": the message after the change says so (COM_ALFA_LOCATIONS_N_ITEMS_PUBLISHED / _UNPUBLISHED).
     *
     * @return void
     *
     * @since  2.1.1
     */
    public function publish()
    {
        $prefix = $this->text_prefix;
        $this->text_prefix = 'COM_ALFA_LOCATIONS';

        try {
            parent::publish();
        } finally {
            $this->text_prefix = $prefix;
        }
    }
}
