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
 * GET v1/alfa/orders/:id/payments: the payments of one order, as the order read gives them.
 *
 * @since  2.2.2
 */
class OrderpaymentsController extends ApiController
{
    use HasApiGuards, ReadsOrderParts {
        ReadsOrderParts::aclSection insteadof HasApiGuards;
    }

    /**
     * The content type of the item.
     *
     * @var string
     * @since  2.2.2
     */
    protected $contentType = 'orderpayments';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  2.2.2
     */
    protected $default_view = 'orderpayments';
}
