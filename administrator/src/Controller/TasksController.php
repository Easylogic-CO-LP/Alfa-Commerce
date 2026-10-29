<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskAttention;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use DomainException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * The staff actions of Tools > Tasks on a failed or held task: Resend, Run again and Mark resolved
 * ({@see TaskStaff}). Each is a POST with the form token, by a user with the Tools permission, and goes back to the
 * Tasks tab with what happened.
 *
 * @since  2.1.4
 */
class TasksController extends BaseController
{
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
        $reason = $this->input->post->getString('reason', '');
        $this->act(call: static function (int $id, int $userId) use ($reason): string {
            TaskStaff::resolve(id: $id, userId: $userId, reason: $reason);

            return Text::sprintf('COM_ALFA_TASKS_RESOLVED', $id);
        });
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

        $id = $this->input->post->getInt('id', 0);

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
}
