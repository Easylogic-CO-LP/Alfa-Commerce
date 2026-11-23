<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Task\ExtensionJobEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Task\EnqueueResult;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use InvalidArgumentException;
use RuntimeException;

/**
 * Background work an alfa plugin owes, run by the task runner: the one way a plugin's own work is retried on
 * the runner's backoff and shown in Tools, Tasks.
 *
 * One active job a plugin and key: a request of the same key while one waits joins it (the payload is never
 * rewritten), and one made while it runs makes it run once more, so the plugin always tells the latest values it reads
 * when the job runs. The job runs outside the runner's chunk transaction (ownTransactions), because its work may call
 * another system; the plugin's handler, onAlfaExtensionJob ({@see ExtensionJobEvent}), returns when the work is done
 * and throws to be retried.
 *
 * @since  2.2.2
 */
final class ExtensionJob implements JobHandler
{
    /**
     * The handler's name; its scopes are extension.job:<group>.<element>:<key>.
     *
     * @since  2.2.2
     */
    public const NAME = 'extension.job';

    /**
     * Owe a plugin's job: a new one for its key, or the one already waiting or running joined.
     *
     * @api WRITE: enqueue background work for an alfa plugin; the runner hands it back to the plugin (onAlfaExtensionJob)
     *      with the data of the first request of that key, retries a failure on its backoff and lists the job in Tools,
     *      Tasks.
     *
     * @param PluginId $plugin The plugin that does the work (a plugin of an alfa group).
     * @param string $key What the job covers, unique within the plugin: 1 to 100 of a to z, 0 to 9, '_', '.', ':' and '-'.
     * @param array<string, mixed> $data What the plugin needs to do it.
     *
     * @throws InvalidArgumentException When the plugin is not of an alfa group or the key is not of that shape.
     *
     * @since  2.2.2
     */
    public static function enqueue(PluginId $plugin, string $key, array $data = []): EnqueueResult
    {
        if (!str_starts_with($plugin->group, 'alfa')) {
            throw new InvalidArgumentException('An extension job belongs to a plugin of an alfa group (given: ' . $plugin . ').');
        }

        if (preg_match('/^[a-z0-9_.:-]{1,100}$/', $key) !== 1) {
            throw new InvalidArgumentException('An extension job\'s key is 1 to 100 of a to z, 0 to 9, _, ., : and - (given: ' . $key . ').');
        }

        return Runner::enqueue(
            handler: self::NAME,
            scopeKey: self::NAME . ':' . $plugin . ':' . $key,
            payload: ['plugin' => (string) $plugin, 'key' => $key, 'data' => $data],
        );
    }

    /**
     * Light, behind the shop's own work, one unit a job, outside the runner's chunk transaction.
     *
     * @since  2.2.2
     */
    public function policy(): JobPolicy
    {
        return new JobPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_EXTENSION_JOB',
            weight: JobPolicy::LIGHT,
            priority: 5,
            initialUnits: 1,
            rerunOnMerge: true,
            ownTransactions: true,
        );
    }

    /**
     * One unit: the plugin's work.
     *
     * @param JobRun $run The job.
     *
     * @since  2.2.2
     */
    public function total(JobRun $run): int
    {
        return 1;
    }

    /**
     * Hand the work to the plugin: booted alone for the job, whatever its Access (the job runs as the system, not as
     * the visitor whose request ran the runner: AlfaPluginHelper::bootForWork()), its onAlfaExtensionJob() method
     * called; its Throwable reaches the runner, which retries the job.
     *
     * @param JobRun $run The job.
     * @param int $units At most this many units (one).
     *
     * @throws RuntimeException When the payload names no plugin, or the plugin has no onAlfaExtensionJob() method.
     * @throws PluginUnavailable When the plugin cannot be loaded.
     *
     * @since  2.2.2
     */
    public function chunk(JobRun $run, int $units): JobChunk
    {
        $plugin = PluginId::of((string) ($run->payload['plugin'] ?? ''));
        $helper = AlfaPluginHelper::get();
        $loaded = $helper->bootForWork($plugin);

        if (!$helper->declares($loaded, 'onAlfaExtensionJob')) {
            throw new RuntimeException('The plugin ' . $plugin . ' has no onAlfaExtensionJob() method; its job ' . $run->id . ' waits.');
        }

        $helper->dispatchTo($loaded, new ExtensionJobEvent(
            jobId: $run->id,
            key: (string) ($run->payload['key'] ?? ''),
            data: (array) ($run->payload['data'] ?? []),
        ));

        return new JobChunk(units: 1, checkpoint: null, finished: true);
    }
}
