<?php

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\ApiController;

/**
 * The Discounts controller
 *
 * @since  1.0.1
 */
class DiscountsController extends ApiController
{
    /**
     * The content type of the item.
     *
     * @var    string
     * @since  1.0.1
     */
    protected $contentType = 'discounts';

    /**
     * The default view for the display method.
     *
     * @var    string
     * @since  1.0.1
     */
    protected $default_view = 'discounts';
}
