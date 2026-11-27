<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Joomla\CMS\MVC\Controller\AdminController;

/**
 * Shipments list controller class.
 *
 * @since  1.0.0
 */
class ShipmentsController extends AdminController
{
    /**
     * Proxy for getModel.
     *
     * @param string $name Optional. Model name
     * @param string $prefix Optional. Class prefix
     * @param array $config Optional. Configuration array for model
     *
     * @return object The Model
     *
     * @since  1.0.0
     */
    public function getModel($name = 'Shipment', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        $model = parent::getModel($name, $prefix, $config);

        // The operator's confirmation of a delete that products' shipping lists still name (the button printed
        // with the model's refusal posts it): the model judges with it, and judges the lists again.
        $model->setState('delete.confirmed', RequestScalar::int($this->input->post, 'confirm') === 1);

        return $model;
    }
}
