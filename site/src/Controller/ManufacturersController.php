<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;

/**
 * Manufacturers class.
 *
 * @since  1.0.0
 */
class ManufacturersController extends FormController
{
    /**
     * The storefront creates no manufacturer records.
     *
     * FormController exposes add, save and reload as tasks and would answer them with
     * Joomla's core.create; refusing here closes those doors for every visitor.
     *
     * @param array $data The data for the new record.
     *
     * @return bool
     *
     * @since  1.10.0
     */
    protected function allowAdd($data = [])
    {
        return false;
    }

    /**
     * The storefront edits no manufacturer records.
     *
     * Closes the inherited edit, save and reload tasks, which Joomla would grant on core.edit.
     *
     * @param array $data The record data.
     * @param string $key The record key name.
     *
     * @return bool
     *
     * @since  1.10.0
     */
    protected function allowEdit($data = [], $key = 'id')
    {
        return false;
    }

    /**
     * Joomla's generic batch, reachable as a task only because it is public.
     *
     * @param mixed $model Unused.
     *
     * @return bool
     *
     * @throws NotAllowed Always.
     *
     * @since  1.10.0
     */
    public function batch($model = null)
    {
        throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
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
     * @since  1.0.0
     */
    public function getModel($name = 'Manufacturers', $prefix = 'Site', $config = [])
    {
        return parent::getModel($name, $prefix, ['ignore_request' => true]);
    }
}
