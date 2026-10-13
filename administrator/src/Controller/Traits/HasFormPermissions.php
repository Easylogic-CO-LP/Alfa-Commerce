<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller\Traits;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;

/**
 * A record controller's create and edit decisions, made by its own section's permissions.
 *
 * Joomla's FormController answers allowAdd() with core.create and allowEdit() with core.edit,
 * rights a group inherits from Global Configuration. Alfa grants neither: the section the
 * controller's record belongs to ({@see AlfaHelper::PERMISSION_SECTIONS}, keyed by `view_item`)
 * decides, so add, edit, save, apply, save2new, save2copy, reload and the version history all
 * follow `alfa.<section>.create` / `alfa.<section>.edit`.
 *
 * @since  2.0.0
 */
trait HasFormPermissions
{
    /**
     * May the current user create a record of this controller's section.
     *
     * @param array $data The data for the new record.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function allowAdd($data = [])
    {
        return AlfaHelper::can(section: $this->view_item, action: 'create', user: $this->app->getIdentity());
    }

    /**
     * May the current user edit a record of this controller's section.
     *
     * @param array $data The record data.
     * @param string $key The record key name.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function allowEdit($data = [], $key = 'id')
    {
        return AlfaHelper::can(section: $this->view_item, action: 'edit', user: $this->app->getIdentity());
    }

    /**
     * Joomla's generic batch, which no Alfa screen offers.
     *
     * Reachable as a task only because every public method is one, and it could not even run
     * that way (it needs a model a task call never passes). Refused outright; a controller with
     * a batch of its own overrides this and checks its section's edit permission there.
     *
     * @param mixed $model Unused.
     *
     * @return bool
     *
     * @throws NotAllowed Always.
     *
     * @since  2.0.0
     */
    public function batch($model = null)
    {
        throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
    }
}
