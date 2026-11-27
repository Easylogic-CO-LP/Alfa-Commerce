<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFailedCopyRedirect;
use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Joomla\CMS\MVC\Controller\FormController;

/**
 * Label controller class: one of the shop's labels, edited.
 *
 * @since  2.3.0
 */
class LabelController extends FormController
{
    use HasFormPermissions;
    use HasFailedCopyRedirect;

    protected $view_list = 'labels';
}
