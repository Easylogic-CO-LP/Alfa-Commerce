<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\Task\Alfapricesets\Extension;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Scheduler task: prepare the list prices of the combinations of customer
 * groups that wait for them.
 *
 * The catalogue sorts and filters each customer by a figure prepared for the
 * combination of pricing groups they hold. A combination nobody held before
 * costs a pass over the whole catalogue, so the request that brings one (an
 * account, a group or a pricing rule saved) only asks for it, and this task
 * prepares it: a chunk of products at a time, from where the last run stopped,
 * one run at a time. A run works for the time it is given; while combinations
 * still wait it leaves the task due again, and the scheduler runs it at its
 * next trigger (a page view with the lazy scheduler, or the next cron run).
 * A product whose pricing throws is left out of the combination (logged here
 * with its error); it does not stop the preparation.
 *
 * @since  2.0.0
 */
class Alfapricesets extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * Task routines advertised to the scheduler.
     *
     * @var array
     *
     * @since  2.0.0
     */
    protected const TASKS_MAP = [
        PriceIndexSyncService::PREPARE_TASK => [
            'langConstPrefix' => 'PLG_TASK_ALFAPRICESETS_PREPARE',
            'method' => 'prepare',
            'form' => 'prepare',
        ],
    ];

    /**
     * Load the plugin language files automatically.
     *
     * @var bool
     *
     * @since  2.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Events this plugin subscribes to.
     *
     * @return array<string, string>
     *
     * @since  2.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Remove the scheduler's tasks of this plugin's routines, with their assets:
     * the plugin is being uninstalled, and the scheduler must not meet a task
     * whose routine is gone.
     *
     * @return int The tasks removed.
     *
     * @since  2.0.0
     */
    public static function removeTasks(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $ids = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__scheduler_tasks'))
                ->whereIn($db->quoteName('type'), array_keys(static::TASKS_MAP), \Joomla\Database\ParameterType::STRING),
        )->loadColumn();
        $table = Factory::getApplication()->bootComponent('com_scheduler')->getMVCFactory()->createTable('Task', 'Administrator');
        $removed = 0;

        foreach ($ids as $id) {
            $removed += $table->delete((int) $id) ? 1 : 0;
        }

        return $removed;
    }

    /**
     * Prepare what waits, for as long as the task allows.
     *
     * @param ExecuteTaskEvent $event The scheduler execution event.
     *
     * @return int A Status constant: OK when nothing waits (or another run is at work; products left out of a set do
     *             not count as a failure), WILL_RESUME when sets still wait after the time given, KNOCKOUT when a chunk
     *             failed for another reason.
     *
     * @since  2.0.0
     */
    protected function prepare(ExecuteTaskEvent $event): int
    {
        $seconds = max(1, (int) ($event->getArgument('params')->seconds ?? 20));
        $run = (new PriceIndexSyncService())->prepareGroupSets(seconds: (float) $seconds);

        if ($run['busy']) {
            $this->logTask('Another run is preparing the list prices; this one did nothing.');

            return Status::OK;
        }

        foreach ($run['leftOut'] as $left) {
            $this->logTask(sprintf(
                'Product %d could not be priced for a combination of customer groups and is left out of it: %s',
                $left['product'],
                $left['message'],
            ), 'warning');
        }

        $this->logTask(sprintf(
            'List prices for customer groups: %d set(s) prepared, %d row(s) written, %d product(s) left out, %d set(s) waiting, %d set(s) failed.',
            $run['prepared'],
            $run['written'],
            \count($run['leftOut']),
            $run['waiting'],
            \count($run['failed']),
        ));

        if ($run['failed'] !== []) {
            return Status::KNOCKOUT;
        }

        return $run['waiting'] > 0 ? Status::WILL_RESUME : Status::OK;
    }
}
