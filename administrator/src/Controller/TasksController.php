<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasToolsAjaxTrait;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Card\CoreCardProvider;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerCronKey;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerTrigger;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskAttention;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Alfa\Component\Alfa\Administrator\Service\Task\TickResult;
use DomainException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * The staff actions of Tools > Tasks: Resend, Run again and Mark resolved on a failed or held single, Retry and Mark
 * resolved on a failed job ({@see TaskStaff}); the progress page's calls (process, and jobsCard when its job ends); and a
 * new web-cron key (generateKey). Each is a POST with the form token, by a user with the Tools permission. The actions go back to the
 * Tasks tab with what happened; process answers JSON; generateKey answers the Tools page with the new address, once.
 *
 * @since  2.1.4
 */
class TasksController extends BaseController
{
    use HasToolsAjaxTrait;

    /**
     * What each refusal of TaskStaff says, by its code. Nothing an exception carries is shown.
     *
     * @var array<int, string>
     *
     * @since  2.1.4
     */
    private const REFUSALS = [
        TaskStaff::REFUSED_GONE => 'COM_ALFA_TASKS_ACTION_GONE',
        TaskStaff::REFUSED_NOT_OFFERED => 'COM_ALFA_TASKS_ACTION_NOT_OFFERED',
        TaskStaff::REFUSED_NO_REASON => 'COM_ALFA_TASKS_REASON_REQUIRED',
        TaskStaff::REFUSED_ACTIVE => 'COM_ALFA_TASKS_JOB_ACTIVE',
    ];

    /**
     * Resend an e-mail to the recipients not recorded as sent.
     *
     * @since  2.1.4
     */
    public function resend(): void
    {
        $this->act(call: fn (int $id, int $userId): string => Text::sprintf('COM_ALFA_TASKS_RESENT', $id, TaskStaff::resend(id: $id, userId: $userId)));
    }

    /**
     * Run a plugin's reaction again for the parts not recorded as completed.
     *
     * @since  2.1.4
     */
    public function runAgain(): void
    {
        $this->act(call: fn (int $id, int $userId): string => Text::sprintf('COM_ALFA_TASKS_RUN_AGAIN', $id, TaskStaff::runAgain(id: $id, userId: $userId)));
    }

    /**
     * Close a task with no action, with the reason given.
     *
     * @since  2.1.4
     */
    public function resolve(): void
    {
        $reason = RequestScalar::string($this->input->post, 'reason');
        $this->act(call: static function (int $id, int $userId) use ($reason): string {
            TaskStaff::resolve(id: $id, userId: $userId, reason: $reason);

            return Text::sprintf('COM_ALFA_TASKS_RESOLVED', $id);
        });
    }

    /**
     * Put a failed job back in the queue, its failures reset.
     *
     * @since  2.2.0
     */
    public function retry(): void
    {
        $this->act(call: static function (int $id, int $userId): string {
            TaskStaff::retry(id: $id, userId: $userId);

            return Text::sprintf('COM_ALFA_TASKS_RETRIED', $id);
        });
    }

    /**
     * The progress page's call: a 2-second tick on the job while it waits, then how far it is, as JSON. The session is
     * closed before the tick, so the same member of staff's other pages never wait for it. A job waiting out its
     * backoff is made due first, as staff asked (2.2.0).
     *
     * The answer paces the page: retry_after is 0 while the tick worked the job and it still waits, and the endpoint's
     * gap (5 s) when the job could not be worked, with the reason and its line (another run holds it, a large job holds
     * the heavy slot, the runner is paused, maintenance, its last run failed, or busy).
     *
     * @since  2.2.0
     */
    public function process(): void
    {
        $this->ajaxGuard();

        if (strtoupper($this->input->getMethod()) !== 'POST') {
            $this->ajaxRespond([], false, Text::_('JINVALID_TOKEN_NOTICE'));

            return;
        }

        $id = RequestScalar::int($this->input->post, 'id');
        $job = TaskStaff::job(id: $id);
        $tick = null;

        if ($job !== null && self::waiting(state: $job['state'])) {
            TaskStaff::dueNow(id: $id);
            RunnerTrigger::closeSession();
            $tick = Runner::tick(trigger: 'progress', budgetMs: Runner::PROCESS_MS, onlyJob: $id);
            $job = TaskStaff::job(id: $id);
        }

        if ($job === null) {
            $this->ajaxRespond([], false, Text::_('COM_ALFA_TASKS_JOB_GONE'));

            return;
        }

        $finished = !self::waiting(state: $job['state']);
        $reason = $finished || ($tick !== null && $tick->chunks > 0) ? '' : self::waitReason(tick: $tick, heavy: $job['heavy']);

        $this->ajaxRespond([
            'state' => $job['state'],
            'stateText' => Text::_('COM_ALFA_TASKS_JOB_STATE_' . strtoupper($job['state'])),
            'percent' => $job['percent'],
            'text' => TaskStaff::unitsText(done: $job['done'], total: $job['total'], percent: $job['percent']),
            'error' => Tasks::errorText($job['error']),
            // A thumbnails job's report, printed in the card when the job ends (UX-84).
            'report' => $finished && \is_array($job['report'])
                ? Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_REPORT', (int) $job['report']['made'], (int) $job['report']['kept'], (int) $job['report']['missing'], (int) $job['report']['failed'])
                : '',
            'finished' => $finished,
            'retry_after' => $reason === '' ? 0 : Runner::ENDPOINT_GAP_SECONDS,
            'reason' => $reason,
            'waitText' => $reason === '' ? '' : Text::_('COM_ALFA_TASKS_PROGRESS_WAIT_' . strtoupper($reason)),
        ]);
    }

    /**
     * The jobs card of the Tasks tab, drawn by its own layout from the one source of its data (TaskStaff::jobsCard()), as
     * JSON {html}: the progress page swaps it in when its job ends, so the list below the card is never left stale and a
     * thumbnails job's report shows in its row (UX-84). A POST with the form token, by a user with the Tools permission.
     *
     * @since  2.2.0
     */
    public function jobsCard(): void
    {
        $this->ajaxGuard();

        if (strtoupper($this->input->getMethod()) !== 'POST') {
            $this->ajaxRespond([], false, Text::_('JINVALID_TOKEN_NOTICE'));

            return;
        }

        $this->ajaxRespond([
            'html' => LayoutHelper::render('tasks.jobs', TaskStaff::jobsCard(followedJob: RequestScalar::int($this->input->post, 'id')), JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'),
        ]);
    }

    /**
     * The Dashboard's background-work card, drawn by its own layout from the one source of its data
     * (CoreCardProvider::backgroundWork()), as JSON {html}: the staff kick swaps it in after each of its ticks while the
     * card is on the page, so the card follows the job without a reload (entry 27). Its html holds no
     * [data-alfa-background-card] when there is nothing left to show. A POST with the form token, by a user with the
     * Tools permission.
     *
     * @since  2.2.0
     */
    public function backgroundCard(): void
    {
        $this->ajaxGuard();

        if (strtoupper($this->input->getMethod()) !== 'POST') {
            $this->ajaxRespond([], false, Text::_('JINVALID_TOKEN_NOTICE'));

            return;
        }

        $this->ajaxRespond([
            'html' => LayoutHelper::render(
                CoreCardProvider::BACKGROUND_LAYOUT,
                ['title' => CoreCardProvider::BACKGROUND_TITLE, 'data' => CoreCardProvider::backgroundWork()],
                JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
            ),
        ]);
    }

    /**
     * Why a tick did not work the job: paused, maintenance, leased (another tick holds it), heavy (another job holds the
     * heavy slot), retry (its chunk failed and waits for its retry) or busy.
     *
     * @param TickResult|null $tick The tick, or null when none ran.
     * @param bool $heavy Whether the job is heavy.
     *
     * @since  2.2.0
     */
    private static function waitReason(?TickResult $tick, bool $heavy): string
    {
        return match (true) {
            $tick === null => 'busy',
            $tick->refused === 'paused', $tick->refused === 'maintenance' => $tick->refused,
            $tick->stoppedBy === 'elsewhere' => 'leased',
            $tick->failed > 0 => 'retry',
            $tick->jobs === 0 && $heavy => 'heavy',
            default => 'busy',
        };
    }

    /**
     * Make a new web-cron key, which stops the old one, and answer the Tools page with the full address in it: the one
     * time it is seen. The key is never put in a message, the session or a log.
     *
     * @throws NotAllowed When the user may not use Tools.
     *
     * @since  2.2.0
     */
    public function generateKey(): void
    {
        $page = Route::_('index.php?option=com_alfa&view=tools', false) . '#tasks';

        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($page, Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $key = RunnerCronKey::generate();
        Tasks::log(entry: 'The web-cron key was replaced by user ' . (int) $user->id . '.', priority: Log::INFO);
        $this->input->set('view', 'tools');
        $this->input->set('layout', 'default');
        // The view display() then renders is this one: it carries the key for this answer only.
        $this->getView('tools', $this->app->getDocument()->getType(), '', ['base_path' => $this->basePath, 'layout' => 'default'])->newCronKey = $key;
        $this->display();
    }

    /**
     * The token of a POST and the Tools permission, then the act, then back to the Tasks tab.
     *
     * @param callable(int, int): string $call The act, given the task and the user; answers what to say.
     *
     * @throws NotAllowed When the user may not use Tools.
     *
     * @since  2.1.4
     */
    private function act(callable $call): void
    {
        $page = Route::_('index.php?option=com_alfa&view=tools', false) . '#tasks';

        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($page, Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $id = RequestScalar::int($this->input->post, 'id');

        try {
            $this->setRedirect($page, $call($id, (int) $user->id), 'success');
        } catch (DomainException $e) {
            $this->setRedirect($page, Text::_(self::REFUSALS[$e->getCode()] ?? 'COM_ALFA_TASKS_ACTION_ERROR'), 'warning');
        } catch (Throwable $e) {
            Tasks::log(entry: 'Tools > Tasks (' . $this->getTask() . ') on task #' . $id . ' stopped with ' . $e::class . ': ' . $e->getMessage()
                . ' (' . $e->getFile() . ':' . $e->getLine() . ')', priority: Log::ERROR);
            TaskAttention::sync();
            $this->setRedirect($page, Text::_('COM_ALFA_TASKS_ACTION_ERROR'), 'error');
        }
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
