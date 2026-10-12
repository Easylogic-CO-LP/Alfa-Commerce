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
 * Item fields list controller class.
 *
 * @since  1.3.0
 */
class ItemfieldsController extends AdminController
{
    /**
     * Proxy for getModel: list actions (publish, delete, ordering) run through
     * the single-record model so cascading cleanup stays in one place.
     *
     * @param string $name Optional. Model name
     * @param string $prefix Optional. Class prefix
     * @param array $config Optional. Configuration array for model
     *
     * @return object The Model
     *
     * @since  1.3.0
     */
    public function getModel($name = 'Itemfield', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }
}
