<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

/**
 * Cart rules list controller.
 *
 * @since  2.0.0
 */
class CartrulesController extends AdminController
{
    /**
     * Proxy for getModel.
     *
     * @param string $name The model name.
     * @param string $prefix The class prefix.
     * @param array $config Configuration array.
     *
     * @return object The model.
     *
     * @since  2.0.0
     */
    public function getModel($name = 'Cartrule', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }
}
