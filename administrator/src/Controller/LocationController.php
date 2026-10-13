<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Joomla\CMS\MVC\Controller\FormController;

/**
 * Inventory location edit controller.
 *
 * @since  2.0.0
 */
class LocationController extends FormController
{
    use HasFormPermissions;

    /**
     * View used by the cancel/save toolbar buttons.
     *
     * @var string
     */
    protected $view_list = 'locations';
}
