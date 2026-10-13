<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Joomla\CMS\MVC\Controller\FormController;

/**
 * Document series edit controller.
 *
 * @since  2.0.0
 */
class SeriesController extends FormController
{
    use HasFormPermissions;

    /**
     * @var string The URL view list variable.
     * @since  2.0.0
     */
    protected $view_list = 'documentseries';
}
