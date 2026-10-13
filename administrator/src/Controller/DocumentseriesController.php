<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

/**
 * Document series list controller (publish/unpublish/delete tasks).
 *
 * Deletion of series with issued documents is refused by SeriesModel.
 *
 * @since  2.0.0
 */
class DocumentseriesController extends AdminController
{
    /**
     * Proxy for getModel.
     *
     * @param string $name The model name.
     * @param string $prefix The class prefix.
     * @param array $config Configuration array.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @since  2.0.0
     */
    public function getModel($name = 'Series', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
