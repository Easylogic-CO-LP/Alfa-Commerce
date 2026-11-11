<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Search\CatalogDocumentSource;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildableEngine;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildRunner;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildStore;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineInterface;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineRegistry;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskError;
use Joomla\CMS\Language\Text;
use Throwable;

/**
 * Rebuild the search index of the configured engine (2.2.0), through core's RebuildRunner and its RebuildStore row.
 *
 * The first chunk opens a new generation of the index; every chunk walks the catalogue from the store's cursor and
 * writes into that generation, which nobody reads until it is activated; the last chunk reconciles what was saved while
 * the walk ran, activates the generation once and closes the store. The engine's writes go to the inactive generation,
 * so a chunk the fence rolls back left nothing a reader sees. Heavy. A second full rebuild request while it runs asks
 * one more run, from the start.
 *
 * @since  2.2.0
 */
final class SearchRebuildJob implements JobHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.0
     */
    public const NAME = 'search.rebuild';

    /**
     * How long to wait for an asynchronous engine to drain before activation, in seconds.
     *
     * @since  2.2.0
     */
    private const FINISH_TIMEOUT = 20.0;

    /**
     * The scope of an engine's rebuild.
     *
     * @param string $engine The engine's key.
     *
     * @since  2.2.0
     */
    public static function scopeFor(string $engine): string
    {
        return self::NAME . ':' . $engine;
    }

    /**
     * Owe a full rebuild of an engine's index: the job, or the one already waiting or running.
     *
     * @param string $engine The engine's key.
     *
     * @return int The job.
     *
     * @since  2.2.0
     */
    public static function enqueueFor(string $engine): int
    {
        return Runner::enqueue(handler: self::NAME, scopeKey: self::scopeFor(engine: $engine), payload: ['engine' => $engine])->id;
    }

    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_SEARCH_REBUILD', weight: JobPolicy::HEAVY, priority: 10, initialUnits: 50, rerunOnMerge: true);
    }

    public function total(JobRun $run): int
    {
        $key = (string) ($run->payload['engine'] ?? '');
        $store = new RebuildStore();
        $progress = $store->current($key);

        // The open, a committed step of its own at the claim, before any chunk: the engine's new generation (an effect
        // outside the database), then its store row at once, outside any chunk transaction, so a failed chunk resumes
        // the same generation and the live saves of the walk are noted. A row that cannot be written drops the
        // generation it would have named.
        if ($progress === null) {
            $runner = new RebuildRunner(new CatalogDocumentSource(), $this->engine(key: $key)->indexer());
            $progress = $runner->start();

            try {
                $store->open($key, $progress);
            } catch (Throwable $e) {
                $runner->discard($progress);

                throw $e;
            }
        }

        return $progress->total;
    }

    public function chunk(JobRun $run, int $units): JobChunk
    {
        $key = (string) ($run->payload['engine'] ?? '');
        $store = new RebuildStore();
        $runner = new RebuildRunner(new CatalogDocumentSource(), $this->engine(key: $key)->indexer());
        $progress = $store->current($key);

        if ($progress === null) {
            throw new TaskError('COM_ALFA_TASKS_ERROR_SEARCH_NOT_OPEN', [self::engineName(key: $key)]);
        }

        // The walk is over: the finish, in a chunk of its own, so the heavy slot was renewed at the end of the chunk
        // before and the finish (shorter than the lease) ends inside it. The activation is recorded first, by
        // compare-and-set on the store row that this chunk's transaction holds until its fence: another tick of the job
        // finds it recorded and does not activate the generation again.
        if ($progress->enumerated) {
            if ($store->markActivated($key, $progress->generation)) {
                // Reconcile against everything saved while the walk ran, then let core decide whether it may serve.
                $runner->finish($progress, self::FINISH_TIMEOUT, $store->writtenDuringRebuild($key));
            }

            $store->close($key);

            return new JobChunk(units: 0, checkpoint: null, finished: true);
        }

        $before = $progress->processed;

        do {
            $progress = $runner->step($progress);
        } while (!$progress->enumerated && $progress->processed - $before < $units);

        $store->save($key, $progress);

        return new JobChunk(
            units: $progress->processed - $before,
            checkpoint: $progress->enumerated ? ['finish' => true] : ['cursor' => $progress->cursor],
            finished: false,
        );
    }

    /**
     * The configured rebuildable engine the job is for.
     *
     * @param string $key The engine's key the job names.
     *
     * @throws TaskError When it is no longer the configured rebuildable engine.
     *
     * @since  2.2.0
     */
    private function engine(string $key): SearchEngineInterface&RebuildableEngine
    {
        $engine = (new SearchEngineRegistry())->configured();

        if (!$engine instanceof RebuildableEngine || $engine->key() !== $key) {
            throw new TaskError('COM_ALFA_TASKS_ERROR_SEARCH_ENGINE_CHANGED', [self::engineName(key: $key)]);
        }

        return $engine;
    }

    /**
     * An engine's name as the engine picker shows it, for a failure staff read; the key when no engine of it is
     * registered any more.
     *
     * @param string $key The engine's key.
     *
     * @since  2.2.0
     */
    public static function engineName(string $key): string
    {
        $engine = SearchEngineRegistry::shared()->all()[$key] ?? null;

        return $engine === null ? $key : Text::_($engine->label());
    }
}
