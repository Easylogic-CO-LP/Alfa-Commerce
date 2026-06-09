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

class FormfieldgroupsController extends AdminController
{
    /**
     * Get the model instance, defaulting to the singular Formfieldgroup model
     * with request data ignored.
     *
     * @param string $name The model name; defaults to 'Formfieldgroup'
     * @param string $prefix The class prefix; defaults to 'Administrator'
     * @param array $config Model configuration; defaults to ignoring request data
     *
     * @return object The model instance
     * @since  1.0.0
     */
    public function getModel($name = 'Formfieldgroup', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }
}
