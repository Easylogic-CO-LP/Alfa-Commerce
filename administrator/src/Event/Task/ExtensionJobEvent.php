<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Task;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * The background work an extension owes, handed back to it by the task runner (onAlfaExtensionJob, 2.2.2): addressed
 * to the plugin that enqueued the job ({@see \Alfa\Component\Alfa\Administrator\Service\Task\Job\ExtensionJob}), never
 * raised on the shared dispatcher.
 *
 * The job runs outside any transaction of the runner's, so the handler may call another system. A handler that
 * returns has done the work; one that throws is retried on the runner's backoff, and after the last retry the job
 * waits for staff in Tools, Tasks. The data is what the job was enqueued with first: a later request of the same key
 * joins the waiting job and never rewrites it, so the handler reads the current values it tells, never the ones of
 * the moment the job was owed.
 *
 * @since  2.2.2
 */
final class ExtensionJobEvent extends AbstractEvent
{
    /**
     * @param int $jobId The job.
     * @param string $key The plugin's key of the job (what it covers, for example stock:600:0).
     * @param array<string, mixed> $data What the plugin enqueued it with.
     *
     * @since  2.2.2
     */
    public function __construct(private readonly int $jobId, private readonly string $key, private readonly array $data)
    {
        parent::__construct('onAlfaExtensionJob', ['jobId' => $jobId, 'key' => $key]);
    }

    /**
     * The job's id, as Tools, Tasks lists it.
     *
     * @since  2.2.2
     */
    public function getJobId(): int
    {
        return $this->jobId;
    }

    /**
     * The plugin's key of the job.
     *
     * @since  2.2.2
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * What the plugin enqueued the job with.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    public function getData(): array
    {
        return $this->data;
    }
}
