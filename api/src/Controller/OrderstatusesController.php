<?php
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
     * @var    string
     * @since  1.0.1
     */
    protected $contentType = 'orderstatuses';

    /**
     * The default view for the display method.
     *
     * @var    string
     * @since  1.0.1
     */
    protected $default_view = 'orderstatuses';
}