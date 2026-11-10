<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskDocument;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Throwable;

/**
 * meta.tasks on a com_alfa document: the jobs the request queued or joined, so a save keeps its own status and still
 * says what it left to the shop's background work. The Orders document also lists the request's singles about
 * that order, each in the state it ended in. Only the tasks the caller may read are listed. A list that cannot be read
 * costs the meta only, never the answer.
 *
 * @since  2.2.0
 */
trait ListsRequestTasks
{
    /**
     * Display an item, with meta.tasks when the request wrote or joined a task.
     *
     * @param object|null $item The item, or null for the model's.
     *
     * @return string
     *
     * @since  2.2.0
     */
    public function displayItem($item = null)
    {
        $this->addRequestTasks();

        return parent::displayItem($item);
    }

    /**
     * Add meta.tasks to the document when the request wrote or joined a task.
     *
     * @param string $subjectType The subject whose singles are listed too, '' for jobs only.
     * @param int $subjectId Its id.
     *
     * @since  2.2.0
     */
    protected function addRequestTasks(string $subjectType = '', int $subjectId = 0): void
    {
        try {
            $tasks = TaskDocument::noted(reader: Factory::getApplication()->getIdentity() ?? new User(), subjectType: $subjectType, subjectId: $subjectId);
        } catch (Throwable $e) {
            Tasks::log(entry: 'API: the tasks of this request could not be listed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);

            return;
        }

        if ($tasks !== []) {
            $this->getDocument()->addMeta('tasks', $tasks);
        }
    }
}
