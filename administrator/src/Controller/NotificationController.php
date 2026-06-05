<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

/**
 * Single-notification form controller. Drives the standard `save` task → the
 * canonical {@see \Alfa\Component\Alfa\Administrator\Model\NotificationModel::save()},
 * so HTTP and the webservices API create notifications the same way the static
 * helper does.
 *
 * @since  1.0.5
 */
class NotificationController extends FormController
{
    /**
     * The list view to return to.
     *
     * @var string
     * @since 1.0.5
     */
    protected $view_list = 'notifications';
}
