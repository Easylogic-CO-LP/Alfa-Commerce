<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\ApiController;

/**
 * The Orderstatuses controller
 *
 * @since  1.0.1
 */
class OrderstatusesController extends ApiController
{
    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.0.1
     */
    protected $contentType = 'orderstatuses';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.0.1
     */
    protected $default_view = 'orderstatuses';
}
