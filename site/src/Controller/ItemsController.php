<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

/**
 * Items class.
 *
 * @since  1.0.1
 */
class ItemsController extends FormController
{
    public function display($cachable = false, $urlparams = [])
    {
        $viewType = $this->input->get('format', 'html');
        $view = $this->input->get('view', 'items');

        $this->input->set('format', $viewType); // Force JSON format
        parent::display($cachable, $urlparams);

        return $this;
    }

    /**
     * Proxy for getModel.
     *
     * @param string $name The model name. Optional.
     * @param string $prefix The class prefix. Optional
     * @param array $config Configuration array for model. Optional
     *
     * @return object The model
     *
     * @since   1.0.1
     */
    public function getModel($name = 'Items', $prefix = 'Site', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
