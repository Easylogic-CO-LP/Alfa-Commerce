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
}
