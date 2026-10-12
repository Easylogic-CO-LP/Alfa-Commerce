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
 * Item field form controller class.
 *
 * @since  1.3.0
 */
class ItemfieldController extends FormController
{
    use HasFormPermissions;

    protected $view_list = 'itemfields';
}
