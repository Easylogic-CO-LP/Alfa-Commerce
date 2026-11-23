<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\JoomlaPluginLayer;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Service\Task\DueRead;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\WorkCancel;
use Alfa\Component\Alfa\Administrator\Service\Task\WorkDeclarations;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The one entry to background work: a plugin hands itself, then asks in one sentence.
 *
 *     BackgroundWork::of($this)->job('stock.check')->scope((string) $itemId)->with(['item' => $itemId])->enqueue();
 *     BackgroundWork::of($this)->task('invoice.submit')->about('order', $orderId)->with(['order' => $orderId])->enqueue();
 *     $status = BackgroundWork::of($this)->find($id);
 *     BackgroundWork::of($this)->cancel($id);
 *
 * The owner is the plugin object itself: core reads its group and element from it and checks that it is the very
 * instance Joomla booted for them, so a plugin's code reaches its own work only, and never core's. The owner is a
 * scope for your own work, not a permission: who may trigger your work is your own route's or screen's permission.
 *
 * @api WRITE: enqueue, find and cancel the background work an alfa plugin declared in onAlfaDeclareWork.
 *
 * @since  2.3.0
 */
final class BackgroundWork
{
    /**
     * @param string $owner com_alfa, or the plugin as group.element.
     * @param PluginId|null $plugin The plugin; null for core.
     *
     * @since  2.3.0
     */
    private function __construct(private readonly string $owner, private readonly ?PluginId $plugin)
    {
    }

    /**
     * The work of the plugin that hands itself: an installed, enabled plugin of an alfa group, as Joomla booted it.
     * A plugin's own component or module hands the plugin Joomla boots for it:
     * BackgroundWork::of(Factory::getApplication()->bootPlugin('vendor', 'alfa')).
     *
     * @param CMSPlugin $plugin The plugin, $this in its own code.
     *
     * @throws InvalidArgumentException When it is not of an alfa group, is not the instance Joomla booted for its
     *                                  group and element, or is not installed and enabled.
     *
     * @since  2.3.0
     */
    public static function of(CMSPlugin $plugin): self
    {
        $identity = (new JoomlaPluginLayer())->identity($plugin);

        if ($identity === null || !str_starts_with($identity[0], 'alfa')) {
            throw new InvalidArgumentException('BackgroundWork::of() takes a plugin of an alfa group, as Joomla booted it (given: ' . $plugin::class . ').');
        }

        [$group, $element] = $identity;
        $id = PluginId::from(group: $group, element: $element);
        $app = Factory::getApplication();
        $app->bootComponent('com_alfa');

        if ($app->bootPlugin($element, $group) !== $plugin) {
            throw new InvalidArgumentException('BackgroundWork::of() takes the plugin ' . $id . ' as Joomla booted it, not another instance of its class.');
        }

        if (!self::enabled(plugin: $id)) {
            throw new InvalidArgumentException('The plugin ' . $id . ' is not installed and enabled: it has no background work.');
        }

        return new self(owner: (string) $id, plugin: $id);
    }

    /**
     * Core's own work, on the same chain. Core's code only: a build check keeps every call of it inside com_alfa's
     * administrator, site and api folders.
     *
     * @internal
     *
     * @since  2.3.0
     */
    public static function core(): self
    {
        return new self(owner: DueRead::CORE_OWNER, plugin: null);
    }

    /**
     * Ask for a task the owner declared: short work done in one go.
     *
     * @param string $name Its declared name.
     *
     * @throws InvalidArgumentException When the owner declares no task of that name, or the name is a job.
     *
     * @since  2.3.0
     */
    public function task(string $name): TaskRequest
    {
        $this->assertKind(name: $name, kind: Tasks::TYPE_SINGLE);

        return new TaskRequest(owner: $this->owner, name: $name);
    }

    /**
     * Ask for a job the owner declared: long work done in steps.
     *
     * @param string $name Its declared name.
     *
     * @throws InvalidArgumentException When the owner declares no job of that name, or the name is a task.
     *
     * @since  2.3.0
     */
    public function job(string $name): JobRequest
    {
        $this->assertKind(name: $name, kind: Tasks::TYPE_JOB);

        return new JobRequest(owner: $this->owner, name: $name);
    }

    /**
     * One of the owner's tasks or jobs as it stands; null when it is another owner's, never existed, or was pruned (a
     * row goes 30 days after it ends).
     *
     * @param int $id The task or job.
     *
     * @since  2.3.0
     */
    public function find(int $id): ?WorkStatus
    {
        if ($id <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $owner = $this->owner;
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type', 'handler', 'state', 'done_units', 'total_units', 'history', 'result', 'last_error', 'finished_at']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('owner') . ' = :owner')
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':owner', $owner),
        )->loadAssoc();

        return \is_array($row) ? WorkStatus::fromRow(row: $row) : null;
    }

    /**
     * Cancel one of the owner's tasks or jobs that has not finished: a waiting one at once; a running job when its step
     * has ended (its scope stays taken until then). False for a running task (one go, its effect would be unknown), a
     * finished one, another owner's, and core's correctness work.
     *
     * @param int $id The task or job.
     *
     * @since  2.3.0
     */
    public function cancel(int $id): bool
    {
        return WorkCancel::cancel(id: $id, owner: $this->owner, trigger: 'request', userId: Tasks::userId(), reason: '');
    }

    /**
     * Refuse a name the owner does not declare as that kind. A plugin's declarations are read again once in the request
     * when the copy does not hold the name.
     *
     * @param string $name The work.
     * @param string $kind single or job.
     *
     * @throws InvalidArgumentException When the owner has no such work of that kind.
     *
     * @since  2.3.0
     */
    private function assertKind(string $name, string $kind): void
    {
        if ($this->plugin === null) {
            $task = Tasks::handler(name: $name) !== null;
            $job = Runner::handler(name: $name) !== null;
            $found = $task || $job ? ($task ? Tasks::TYPE_SINGLE : Tasks::TYPE_JOB) : null;
        } else {
            $found = WorkDeclarations::findOrRead(plugin: $this->plugin, name: $name)['kind'] ?? null;
        }

        if ($found === null) {
            throw new InvalidArgumentException($this->owner . ' declares no background work named ' . $name . '.');
        }

        if ($found !== $kind) {
            throw new InvalidArgumentException($name . ' of ' . $this->owner . ' is a ' . ($found === Tasks::TYPE_JOB ? 'job: ask for it with job()' : 'task: ask for it with task()') . '.');
        }
    }

    /**
     * Whether a plugin is installed and enabled, read once a request.
     *
     * @param PluginId $plugin The plugin.
     *
     * @since  2.3.0
     */
    private static function enabled(PluginId $plugin): bool
    {
        static $known = [];

        return $known[(string) $plugin] ??= (new JoomlaPluginLayer())->rowEnabled($plugin) === true;
    }
}
