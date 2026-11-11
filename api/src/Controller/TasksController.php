<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskAccess;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskDocument;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\User\User;

/**
 * The Tasks controller: what a save left to the shop's background work, and that work (design 2.9).
 *
 * GET v1/alfa/tasks/{id} answers a task to its creator, and to anyone with the permission of its work (Tools for a
 * job, the orders view for an order's single); to anyone else, and for a task that is gone, 404, so a task's existence
 * is never revealed. GET v1/alfa/tasks lists every task for Tools, filtered by filter[state], filter[type] and
 * filter[owner]. POST v1/alfa/tasks/{id}/process works that job for up to 2 seconds (Tools only): 200 with the task
 * when it is no longer waiting, else 202 Accepted with Location and Retry-After.
 *
 * @since  2.2.0
 */
class TasksController extends ApiController
{
    use HasApiGuards;

    /**
     * The seconds a caller of a job still waiting is asked to wait before it asks again: the endpoint's gap.
     *
     * @since  2.2.0
     */
    public const RETRY_AFTER_SECONDS = 5;

    /**
     * The most tasks one page of the list holds.
     *
     * @since  2.2.0
     */
    private const PAGE_MAX = 100;

    /**
     * The tasks a page holds when the caller names no page[limit].
     *
     * @since  2.2.0
     */
    private const PAGE_DEFAULT = 20;

    /**
     * The content type of the item.
     *
     * @var string
     * @since  2.2.0
     */
    protected $contentType = 'tasks';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  2.2.0
     */
    protected $default_view = 'tasks';

    /**
     * The list, for Tools only, a page at a time.
     *
     * @return static
     *
     * @since  2.2.0
     */
    public function displayList()
    {
        $filter = InputFilter::getInstance();
        $apiFilterInfo = $this->input->get('filter', [], 'array');

        foreach (['state', 'type', 'owner'] as $name) {
            $value = $apiFilterInfo[$name] ?? null;

            if (\is_string($value) && $value !== '') {
                $this->modelState->set('filter.' . $name, $filter->clean($value, 'CMD'));
            }
        }

        // Joomla's displayList() reads page[limit] and page[offset] from the input and sets them as given, so the capped
        // values are written there: an absent, non-numeric or lower limit is the default, a higher one the most.
        $page = $this->input->get('page', [], 'array');
        $limit = is_numeric($page['limit'] ?? null) ? (int) $page['limit'] : self::PAGE_DEFAULT;
        $offset = is_numeric($page['offset'] ?? null) ? max(0, (int) $page['offset']) : 0;
        $this->input->set('page', ['limit' => $limit < 1 ? self::PAGE_DEFAULT : min(self::PAGE_MAX, $limit), 'offset' => $offset]);

        return parent::displayList();
    }

    /**
     * One task, to whoever may read it; 404 to anyone else.
     *
     * @param int|null $id The task, or null for the route's.
     *
     * @return static
     *
     * @throws ResourceNotFound When there is no such task, or the caller may not read it.
     *
     * @since  2.2.0
     */
    public function displayItem($id = null)
    {
        $id ??= $this->input->getInt('id', 0);
        $this->readable(id: (int) $id);
        // The item's model, chosen here and never by the caller (the guards clear any ?model=): one named after the type
        // would share the state's "task" with this controller's own task, and never find its id.
        $this->input->set('model', 'taskdocument');

        return parent::displayItem((int) $id);
    }

    /**
     * Process now: a 2-second tick on this job, then the job.
     *
     * POST v1/alfa/tasks/{id}/process
     *
     * The answer is 200 with the task when it is no longer waiting or running (done, failed or resolved), and 202
     * Accepted with Location and Retry-After while it still is; a tick that could not run (maintenance, the runner
     * paused, another tick on it) leaves it waiting. A single is not processed: 409.
     *
     * @return static
     *
     * @throws ResourceNotFound When there is no such task, or the caller may not read it.
     * @throws NotAllowed When the caller may read it but may not use Tools.
     * @throws Save When it is a single (409).
     *
     * @since  2.2.0
     */
    public function process()
    {
        // On a POST the router puts the route's variables in the post bag, as for the order verbs.
        $id = $this->input->post->getInt('id', 0);
        $row = $this->readable(id: $id);

        if (!TaskAccess::canUseTools(user: $this->identity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($row['type'] !== Tasks::TYPE_JOB) {
            throw new Save('Only a job can be processed; a single runs after the save that wrote it.', 409);
        }

        if (self::waiting(state: (string) $row['state'])) {
            // A job waiting out its backoff is made due first: the caller asked for it now.
            TaskStaff::dueNow(id: $id);
            Runner::tick(trigger: 'processnow', budgetMs: Runner::PROCESS_MS, onlyJob: $id);
            $row = TaskDocument::row(id: $id) ?? $row;
        }

        if (self::waiting(state: (string) $row['state'])) {
            // Location before the status: PHP answers 302 for a Location sent while the status is not 201 or 3xx, and
            // the status sent after it puts 202 back (the headers go out in the order they were set).
            $this->app->setHeader('Location', TaskDocument::path(id: $id), true);
            $this->app->setHeader('Retry-After', (string) self::RETRY_AFTER_SECONDS, true);
            $this->app->setHeader('status', '202', true);
        }

        return $this->displayItem($id);
    }

    /**
     * The checks a task's tasks make before the controller runs: the list is Tools'; one task's checks are its own.
     *
     * @param string $task The task about to run.
     *
     * @throws NotAllowed When the list is asked by a caller who may not use Tools.
     *
     * @since  2.2.0
     */
    protected function assertAuthorisedForTask(string $task): void
    {
        if ($task === 'displayList' && !TaskAccess::canUseTools(user: $this->identity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * The task's row when the caller may read it.
     *
     * @param int $id The task.
     *
     * @return array<string, mixed>
     *
     * @throws ResourceNotFound When there is no such task, or the caller may not read it: the same answer for both.
     *
     * @since  2.2.0
     */
    private function readable(int $id): array
    {
        $row = TaskDocument::row(id: $id);

        if ($row === null || !TaskAccess::canRead(row: $row, user: $this->identity())) {
            throw new ResourceNotFound('Task not found', 404);
        }

        return $row;
    }

    /**
     * Who asks: the API's signed-in user, or a guest when there is none.
     *
     * @since  2.2.0
     */
    private function identity(): User
    {
        return $this->app->getIdentity() ?? new User();
    }

    /**
     * Whether a job in this state still waits for work.
     *
     * @param string $state Its state.
     *
     * @since  2.2.0
     */
    private static function waiting(string $state): bool
    {
        return $state === Tasks::STATE_PENDING || $state === Tasks::STATE_RUNNING;
    }
}
