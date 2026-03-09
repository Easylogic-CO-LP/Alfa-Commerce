<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

/**
 * Manufacturers class.
 *
 * @since  1.0.1
 */
class ManufacturersController extends FormController
{
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
    public function getModel($name = 'Manufacturers', $prefix = 'Site', $config = [])
    {
        return parent::getModel($name, $prefix, ['ignore_request' => true]);
    }
}
