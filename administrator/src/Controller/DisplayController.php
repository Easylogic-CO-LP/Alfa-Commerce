<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

/**
 * Alfa master display controller.
 *
 * @since  1.0.1
 */
class DisplayController extends BaseController
{
    /**
     * The default view.
     *
     * @var string
     * @since  1.0.1
     */
    protected $default_view = 'items';

    /**
     * Method to display a view.
     *
     * @param bool $cachable If true, the view output will be cached
     * @param array $urlparams An array of safe URL parameters and their variable types, for valid values see {@link InputFilter::clean()}.
     *
     * @return BaseController|bool This object to support chaining.
     *
     * @since   1.0.1
     */
    public function display($cachable = false, $urlparams = [])
    {
        return parent::display();
    }
}
