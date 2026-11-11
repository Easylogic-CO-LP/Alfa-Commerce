<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskAccess;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ItemModel;
use Joomla\CMS\User\User;

/**
 * One task as the API shows it (GET v1/alfa/tasks/{id}): its document's fields ({@see TaskDocument::fields()}). Who
 * may read it is the API controller's check, made before this model is asked. Not named after the type: the API
 * controller keeps its own task under the state's "task", so a model named task would never find its task.id.
 *
 * @since  2.2.0
 */
class TaskdocumentModel extends ItemModel
{
    /**
     * The task named by the state's taskdocument.id, or false when there is none.
     *
     * @param int|null $pk The task, or null for the state's.
     *
     * @return object|false
     *
     * @since  2.2.0
     */
    public function getItem($pk = null)
    {
        $row = TaskDocument::row(id: (int) ($pk ?? $this->getState($this->getName() . '.id')));

        // The last error is staff's information: shown to a caller with Tools, null to the creator and the orders view.
        return $row === null ? false : (object) TaskDocument::fields(row: $row, withError: TaskAccess::canUseTools(user: Factory::getApplication()->getIdentity() ?? new User()));
    }
}
