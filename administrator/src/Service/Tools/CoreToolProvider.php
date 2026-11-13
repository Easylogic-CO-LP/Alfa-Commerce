<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Tools;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Tools\ExecuteToolActionEvent;
use Alfa\Component\Alfa\Administrator\Event\Tools\GetToolActionsEvent;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\EntityChangeNotifier;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Media\ThumbnailRecreation;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Search\ItemSearchProjection;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildableEngine;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildStore;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineRegistry;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchScope;
use Alfa\Component\Alfa\Administrator\Service\Setup\ContentLanguageAddedNotice;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\CatalogueRebuildJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\SearchRebuildJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\StockEffectsDrainJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\ThumbnailsRecreateJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskAttention;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRetention;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * The component's own maintenance tools, offered through the same registry as any
 * plugin's.
 *
 * WHY CORE GOES THROUGH THE SAME DOOR. An extension point the host does not use
 * itself becomes second-class and quietly rots: this component already has an admin
 * column builder whose docblock promises it serves "ANY admin list screen" and which
 * exactly one screen uses. Running core's own tools through the registry means the
 * path an engine plugin takes is exercised on every Tools page load, so a defect
 * in it surfaces here rather than in someone else's extension.
 *
 * NOT A PLUGIN, DELIBERATELY. Registering these in a plugin group would let an
 * administrator disable the recovery tools of the very screen that exists to repair a
 * broken installation, and a disabled plugin is exactly the state someone reaches
 * for Tools to fix. Core's actions are unconditional; the registry reserves the owner
 * `core` for them and calls this class directly instead of booting a plugin.
 *
 * Same shape as a plugin nonetheless: the two methods below have the identical
 * signatures a contributing plugin implements, so nothing about the collection,
 * rendering, routing or execution pipeline has a second branch for core.
 *
 * @since  2.0.0
 */
final class CoreToolProvider
{
    /**
     * Owner group reserved for the component itself.
     *
     * Not a real plugin group and never looked up as one: {@see \Alfa\Component\Alfa\Administrator\Helper\ToolRegistry}
     * matches on it and dispatches here instead of calling `bootPlugin()`.
     *
     * @var string
     *
     * @since  2.0.0
     */
    public const OWNER_GROUP = 'core';

    /**
     * Owner name reserved for the component itself.
     *
     * @var string
     *
     * @since  2.0.0
     */
    public const OWNER_NAME = 'com_alfa';

    /**
     * Core's contributor identity, group.element (2.2.0): the reserved owner, never booted as a plugin.
     *
     * @since  2.2.0
     */
    public const ID = self::OWNER_GROUP . '.' . self::OWNER_NAME;

    /**
     * The wall-clock budget of one step of a tool that works a job (2.2.0), in milliseconds: the 2,000 ms tick on that
     * job the progress of a job is fed by.
     *
     * @var int
     *
     * @since  2.2.0
     */
    private const JOB_STEP_MS = 2000;

    /**
     * Records per backfill chunk: a backfill row is one INSERT of copied text.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const BACKFILL_CHUNK = 500;

    /**
     * The wall-clock budget of one chunk of Run outstanding tasks, in milliseconds.
     *
     * @var int
     *
     * @since  2.1.4
     */
    private const TASKS_CHUNK_MS = 3000;

    /**
     * The language key of each part a search index can cover, in the order Options lists them: the
     * rebuild's message names the parts in words, never by their codes.
     *
     * @var array<string, string>
     *
     * @since  2.1.1
     */
    private const SCOPE_PART_LABELS = [
        SearchScope::PART_ALIASES => 'COM_ALFA_SEARCH_PART_ALIASES',
        SearchScope::PART_MANUFACTURERS => 'COM_ALFA_SEARCH_PART_MANUFACTURERS',
        SearchScope::PART_CATEGORIES => 'COM_ALFA_SEARCH_PART_CATEGORIES',
        SearchScope::PART_SHORT_DESC => 'COM_ALFA_SEARCH_PART_SHORT_DESC',
        SearchScope::PART_FULL_DESC => 'COM_ALFA_SEARCH_PART_FULL_DESC',
        SearchScope::PART_META_TEXT => 'COM_ALFA_SEARCH_PART_META_TEXT',
    ];

    /**
     * Declare the component's own tools.
     *
     * Priorities descend in the order the tools should be RUN, not merely shown: a
     * language table that does not exist yet cannot be backfilled, and a backfill that
     * has not happened leaves the indexes rebuilding text that is about to change.
     *
     * EVERY TOOL THAT WRITES SAYS SO. A tool changes state unless it declares itself
     * read-only, and the endpoint then demands a POST with a one-time key instead of a
     * replayable URL. Every tool here writes, the setup checklist's re-run included: its
     * evaluation reconciles the setup notification.
     *
     * @param GetToolActionsEvent $event The collection event.
     *
     *
     * @since  2.0.0
     */
    public function onGetToolActions(GetToolActionsEvent $event): void
    {
        // The setup checklist's manual re-run: an unknown surface id opens its own
        // Tools tab (ToolRegistry::extraSurfaces), so "Setup" appears without the
        // Tools template learning anything.
        $event->add(self::ID, 'setup.checklist', 'COM_ALFA_TOOLS_SETUP_TITLE')
            ->icon('checkbox')
            ->mutating()
            ->css('btn-secondary')
            ->priority(500)
            ->meta('surface', 'setup')
            ->meta('surface_label', 'COM_ALFA_TOOLS_TAB_SETUP')
            ->meta('description', 'COM_ALFA_TOOLS_SETUP_DESC');

        // The guided-tour pipeline: reseed makes the DB match the shipped JSON files
        // (what postflight does, on demand and forced); export writes the DB back to
        // the files: author visually in Guided Tours, export, commit.
        $event->add(self::ID, 'tours.reseed', 'COM_ALFA_TOOLS_TOURS_RESEED_TITLE')
            ->icon('map-signs')
            ->mutating()
            ->css('btn-secondary')
            ->priority(400)
            ->meta('surface', 'setup')
            ->meta('surface_label', 'COM_ALFA_TOOLS_TAB_SETUP')
            ->meta('description', 'COM_ALFA_TOOLS_TOURS_RESEED_DESC');

        $event->add(self::ID, 'tours.export', 'COM_ALFA_TOOLS_TOURS_EXPORT_TITLE')
            ->icon('download')
            ->mutating()
            ->css('btn-secondary')
            ->priority(300)
            ->meta('surface', 'setup')
            ->meta('surface_label', 'COM_ALFA_TOOLS_TAB_SETUP')
            ->meta('description', 'COM_ALFA_TOOLS_TOURS_EXPORT_DESC');

        $event->add(self::ID, 'tools.users', 'COM_ALFA_TOOLS_USERS_TITLE')
            ->icon('users')
            ->mutating()
            ->css('btn-secondary')
            ->priority(400)
            ->meta('surface', 'dbsync')
            ->meta('description', 'COM_ALFA_TOOLS_USERS_DESC');

        $event->add(self::ID, 'tools.languages', 'COM_ALFA_TOOLS_LANG_SCHEMA_TITLE')
            ->icon('refresh')
            ->mutating()
            ->css('btn-primary')
            ->priority(300)
            ->meta('surface', 'dbsync')
            ->meta('description', 'COM_ALFA_TOOLS_LANG_SCHEMA_DESC');

        $event->add(self::ID, 'tools.backfill', 'COM_ALFA_TOOLS_BACKFILL_TITLE')
            ->icon('language')
            ->mutating()
            ->css('btn-warning')
            ->priority(200)
            ->meta('surface', 'dbsync')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_BACKFILL_DESC');

        $event->add(self::ID, 'tools.projections', 'COM_ALFA_TOOLS_PROJECTIONS_TITLE')
            ->icon('refresh')
            ->mutating()
            ->css('btn-warning')
            ->priority(100)
            ->meta('surface', 'dbsync')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_PROJECTIONS_DESC');

        // THE GALLERY'S THUMBNAILS RECREATED (2.2.0): one job, two modes, on the Media tab. "Missing" first, the
        // everyday and safer one, for the images that have none, whose thumbnail is the full image, or whose thumbnail is
        // smaller than the size allows; "all" after a change of the thumbnail size, asked first (UX-19, UX-91). Each
        // button queues the job and opens its progress page.
        $event->add(self::ID, 'media.thumbnails.missing', 'COM_ALFA_TOOLS_THUMBNAILS_MISSING_TITLE')
            ->icon('images')
            ->mutating()
            ->css('btn-secondary')
            ->priority(200)
            ->meta('surface', 'media')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_THUMBNAILS_MISSING_DESC');

        $event->add(self::ID, 'media.thumbnails.all', 'COM_ALFA_TOOLS_THUMBNAILS_ALL_TITLE')
            ->icon('images')
            ->mutating()
            ->confirm('COM_ALFA_TOOLS_THUMBNAILS_ALL_CONFIRM')
            ->css('btn-secondary')
            ->priority(190)
            ->meta('surface', 'media')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_THUMBNAILS_ALL_DESC');

        // THE LIST PRICES OF A NEW COMBINATION OF CUSTOMER GROUPS are prepared by its
        // price.set.prepare job (2.2.0), never by the request that brought the combination. The
        // card says what waits and how far each job is; its button works the waiting sets' jobs now.
        $priceSets = $this->priceSetsState();

        // The card names what it is about; its button says what it does, with the run buttons' icon (UX-22).
        $priceSetsAction = $event->add(self::ID, 'pricing.groupsets', 'COM_ALFA_TOOLS_PRICE_SETS_TITLE')
            ->icon('sync')
            ->meta('button_label', 'COM_ALFA_TOOLS_PRICE_SETS_BTN')
            ->mutating()
            ->css('btn-secondary')
            ->priority(95)
            ->meta('surface', 'dbsync')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_PRICE_SETS_DESC');

        if ($priceSets['owed']) {
            $priceSetsAction->meta('notice', $priceSets['text']);
        }

        // STOCK MOVEMENTS THAT NEVER HAPPENED. An order status change or an issued document
        // records the movement it owes and applies it at once; this finishes any that failed
        // or were cut off. It replaces a scheduled task: the notification centre says when
        // it is needed, and the merchant runs it from here.
        $stockOwed = false;

        try {
            $stockOwed = InventoryEffectOutbox::outstandingCount() > 0;
        } catch (Throwable) {
            // A count that fails must not remove the tool that fixes it.
        }

        $stockAction = $event->add(self::ID, 'inventory.effects', 'COM_ALFA_TOOLS_STOCK_EFFECTS_TITLE')
            ->icon('loop')
            ->mutating()
            ->css($stockOwed ? 'btn-warning' : 'btn-secondary')
            ->priority(80)
            ->meta('surface', 'dbsync')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_STOCK_EFFECTS_DESC');

        if ($stockOwed) {
            $stockAction->meta('notice', 'COM_ALFA_TOOLS_STOCK_EFFECTS_OWED');
        }

        // WORK A SAVE LEFT UNFINISHED. A save runs what it owes right after its commit; this runs what a request did not
        // finish and the retries whose time has come, as the notification bell does every few minutes. Recovery
        // only: a task whose outcome is unknown is never run again here.
        $tasksOwed = false;

        try {
            $tasksOwed = Tasks::due();
        } catch (Throwable) {
            // A check that fails must not remove the tool that runs them.
        }

        $tasksAction = $event->add(self::ID, 'tasks.run', 'COM_ALFA_TOOLS_TASKS_RUN_TITLE')
            ->icon('loop')
            ->mutating()
            ->css($tasksOwed ? 'btn-warning' : 'btn-secondary')
            ->priority(100)
            ->meta('surface', 'tasks')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_TASKS_RUN_DESC');

        if ($tasksOwed) {
            $tasksAction->meta('notice', 'COM_ALFA_TOOLS_TASKS_RUN_OWED');
        }

        // FINISHED TASKS DELETED NOW (2.2.0): what the runner's hourly prune deletes, by the same rules
        // (TaskRetention), until nothing they let go is left; the count of what went.
        $event->add(self::ID, 'tasks.prune', 'COM_ALFA_TOOLS_TASKS_PRUNE_TITLE')
            ->icon('trash')
            ->mutating()
            ->css('btn-secondary')
            ->priority(90)
            ->meta('surface', 'tasks')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_TASKS_PRUNE_DESC');

        // THE PRODUCT INDEX IS THE COMPONENT'S, so the button that rebuilds it is too. The engine
        // is asked only what the component cannot know: what to call this for the engine in use,
        // and whether a rebuild is owed. An engine that maintains itself does not implement
        // RebuildableEngine, and no button appears for it.
        //
        // Note CONFIGURED, not active. Active additionally requires readiness, and the state in
        // which this button matters most (an engine selected but never built) is exactly the
        // state in which readiness is false.
        $engine = $this->rebuildableEngine();

        if ($engine !== null) {
            $owed = false;

            try {
                $owed = $engine->isRebuildOwed();
            } catch (Throwable) {
                // A diagnostic that fails must not remove the panel explaining the fix.
            }

            $action = $event->add(self::ID, 'search.rebuild', $engine->rebuildLabel())
                ->icon('search')
                ->mutating()
                ->css($owed ? 'btn-warning' : 'btn-secondary')
                ->priority(90)
                ->meta('surface', 'dbsync')
                ->meta('chunked', true)
                ->meta('description', 'COM_ALFA_SEARCH_REBUILD_DESC');

            // Shown only when the stored index really no longer matches the settings.
            // A warning that is always present is one nobody reads.
            if ($owed) {
                $action->meta('notice', 'COM_ALFA_SEARCH_REBUILD_OWED');
            }
        }
    }

    /**
     * Run one of the component's own tools.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    public function onExecuteToolAction(ExecuteToolActionEvent $event): void
    {
        if ($event->getAction() === 'search.rebuild') {
            $this->rebuildSearchIndex($event);

            return;
        }
        match ($event->getAction()) {
            'tools.users' => $this->syncUsers($event),
            'tools.languages' => $this->syncLanguages($event),
            'tools.backfill' => $this->backfillTranslations($event),
            'tools.projections' => $this->rebuildProjections($event),
            'media.thumbnails.all' => $this->recreateThumbnails(event: $event, mode: ThumbnailRecreation::MODE_ALL),
            'media.thumbnails.missing' => $this->recreateThumbnails(event: $event, mode: ThumbnailRecreation::MODE_MISSING),
            'pricing.groupsets' => $this->preparePriceSets($event),
            'inventory.effects' => $this->applyStockEffects($event),
            'tasks.run' => $this->runOutstandingTasks($event),
            'tasks.prune' => $this->pruneFinishedTasks($event),
            'setup.checklist' => $this->refreshSetupChecklist($event),
            'tours.reseed' => $this->reseedTours($event),
            'tours.export' => $this->exportTours($event),
            default => null,
        };
    }

    /**
     * Apply every stock movement an order or a document still owes.
     *
     * This is what the notification centre sends the merchant to. The plan owes the
     * stock.effects.drain job (2.2.0); each step works that job for a moment
     * ({@see self::stepJob()}), whose own rules end the run (a chunk that settles nothing)
     * and retire the warnings whose movement is no longer owed.
     *
     * A movement the inventory service refused (an order line with no stock identity to
     * move against) is settled for good by the chunk that met it, so it counts as
     * progress and is never claimed again: later plans and the remaining count leave it
     * out, and the final message reports how many wait for the operator to resolve their
     * order lines on the Inventory audit page, which queues them again.
     *
     * @param ExecuteToolActionEvent $event The tool request.
     *
     *
     * @since  2.0.0
     */
    private function applyStockEffects(ExecuteToolActionEvent $event): void
    {
        try {
            if ($event->isPlanning()) {
                $joined = self::activeJob(scope: StockEffectsDrainJob::NAME) !== null;
                // Staff asked: every failed movement is tried now, those waiting for their next automatic try included (2.2.1).
                InventoryEffectOutbox::reopenFailed();
                StockEffectsDrainJob::enqueue();
                $event->setTotal(max(1, InventoryEffectOutbox::outstandingCount()));
                self::followJob(event: $event, scope: StockEffectsDrainJob::NAME, joined: $joined);

                return;
            }

            $this->stepJob(event: $event, scope: StockEffectsDrainJob::NAME, done: static function (): string {
                $remaining = InventoryEffectOutbox::outstandingCount();
                $message = $remaining === 0
                    ? Text::_('COM_ALFA_TOOLS_STOCK_EFFECTS_DONE')
                    : Text::sprintf('COM_ALFA_TOOLS_STOCK_EFFECTS_REMAINING', $remaining);
                // Refused movements are never claimed again; every one on record is reported on its own.
                $refused = InventoryEffectOutbox::refusedCount();

                return $refused > 0 ? $message . ' ' . Text::sprintf('COM_ALFA_TOOLS_STOCK_EFFECTS_REFUSED', $refused) : $message;
            });
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Run the tasks a save left unfinished and the retries whose time has come ({@see Tasks::sweepSingles()}), a chunk
     * at a time. The plan is a count, for the progress bar only. A chunk that takes nothing ends the run, and the
     * message says what still waits: retries whose time has not come, and the tasks that need staff, listed above.
     *
     * @param ExecuteToolActionEvent $event The tool request.
     *
     * @since  2.1.4
     */
    private function runOutstandingTasks(ExecuteToolActionEvent $event): void
    {
        try {
            if ($event->isPlanning()) {
                $event->setTotal(max(1, Tasks::dueCount()));

                return;
            }

            $offset = $event->getOffset();
            $stats = Tasks::sweepSingles(budgetMs: self::TASKS_CHUNK_MS, trigger: 'tools');
            $remaining = Tasks::dueCount();

            $event->setTotal($offset + $stats['claimed'] + $remaining);

            if ($stats['claimed'] > 0 && $remaining > 0) {
                $event->setNextOffset($offset + $stats['claimed']);

                return;
            }

            TaskAttention::sync();

            $message = $remaining === 0
                ? Text::_('COM_ALFA_TOOLS_TASKS_RUN_DONE')
                : Text::sprintf('COM_ALFA_TOOLS_TASKS_RUN_REMAINING', $remaining);
            $waiting = Tasks::waitingCount();
            $attention = TaskStaff::attentionTotal();

            if ($waiting > 0) {
                $message .= ' ' . Text::sprintf('COM_ALFA_TOOLS_TASKS_RUN_WAITING', $waiting);
            }

            if ($attention > 0) {
                $message .= ' ' . Text::sprintf('COM_ALFA_TOOLS_TASKS_RUN_ATTENTION', $attention);
            }

            $event->setMessage($message);
            $event->setRefresh();
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Delete the finished tasks the retention rules let go (TaskRetention), a chunk of at most a few seconds at a
     * time, until none is left. The plan is the count they let go now, for the progress bar; the message says how many
     * went.
     *
     * @param ExecuteToolActionEvent $event The tool request.
     *
     * @since  2.2.0
     */
    private function pruneFinishedTasks(ExecuteToolActionEvent $event): void
    {
        try {
            if ($event->isPlanning()) {
                $event->setTotal(max(1, TaskRetention::eligibleCount()));

                return;
            }

            $offset = $event->getOffset();
            $deleted = TaskRetention::prune(budgetMs: self::TASKS_CHUNK_MS);
            $remaining = $deleted > 0 ? TaskRetention::eligibleCount() : 0;

            $event->setTotal($offset + $deleted + $remaining);

            if ($deleted > 0 && $remaining > 0) {
                $event->setNextOffset($offset + $deleted);

                return;
            }

            $total = $offset + $deleted;
            $event->setMessage($total === 0 ? Text::_('COM_ALFA_TOOLS_TASKS_PRUNE_NONE') : Text::plural('COM_ALFA_TOOLS_TASKS_PRUNE_DONE', $total));
            $event->setRefresh();
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Rebuild the projections the CATALOGUE owns, and only those.
     *
     * DELIBERATELY SAYS NOTHING ABOUT SEARCH. Price and field-value indexes exist in
     * every installation and drive filtering and sorting, so core rebuilds them
     * unconditionally. A search index exists only when an engine plugin is installed,
     * belongs to that engine, and is rebuilt by the action that engine contributes,
     * and if core also claimed to rebuild it, an administrator would face two buttons
     * asserting the same job, with a second engine adding a third.
     *
     * Every item is marked owed a rebuild (#__alfa_items.projection_dirty_at, ranges of
     * 1,000 ids) and the catalogue.rebuild job is owed (2.2.0); each step works that job for
     * a moment ({@see self::stepJob()}). The job clears each mark as its item is rebuilt.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    private function rebuildProjections(ExecuteToolActionEvent $event): void
    {
        try {
            if ($event->isPlanning()) {
                $joined = self::activeJob(scope: CatalogueRebuildJob::NAME) !== null;
                ProjectionManager::markAllForRebuild();
                CatalogueRebuildJob::enqueue();
                $event->setTotal(max(1, ProjectionManager::markedCount()));
                self::followJob(event: $event, scope: CatalogueRebuildJob::NAME, joined: $joined);

                return;
            }

            $this->stepJob(event: $event, scope: CatalogueRebuildJob::NAME, done: static fn (array $job): string => Text::sprintf('COM_ALFA_TOOLS_PROJECTIONS_DONE', $job['done']));
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Recreate the gallery's thumbnails in a mode (2.2.0): the plan asks for the job
     * (ThumbnailRecreation::request(), "all" winning over "missing") and opens its progress page; each step works the
     * job for a moment ({@see self::stepJob()}), its report the message when it is done.
     *
     * @param ExecuteToolActionEvent $event The tool request.
     * @param string $mode ThumbnailRecreation::MODE_ALL or ThumbnailRecreation::MODE_MISSING.
     *
     * @since  2.2.0
     */
    private function recreateThumbnails(ExecuteToolActionEvent $event, string $mode): void
    {
        try {
            if ($event->isPlanning()) {
                $joined = self::activeJob(scope: ThumbnailsRecreateJob::NAME) !== null;
                ThumbnailRecreation::request(mode: $mode);
                $event->setTotal(max(1, ThumbnailRecreation::rowCount()));
                self::followJob(event: $event, scope: ThumbnailsRecreateJob::NAME, joined: $joined);

                return;
            }

            $this->stepJob(event: $event, scope: ThumbnailsRecreateJob::NAME, done: static function (array $job): string {
                $report = ThumbnailRecreation::lastReport() ?? ThumbnailRecreation::emptyReport();

                return Text::sprintf('COM_ALFA_TOOLS_THUMBNAILS_DONE', (int) $report['made'], (int) $report['kept'], (int) $report['missing'], (int) $report['failed']);
            });
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Prepare the waiting sets of customer groups now: each step works the oldest waiting set's job for a moment, until
     * no set waits.
     *
     * @param ExecuteToolActionEvent $event The tool request.
     *
     * @since  2.2.0
     */
    private function preparePriceSets(ExecuteToolActionEvent $event): void
    {
        try {
            $waiting = PriceIndexSyncService::preparationState()['waiting'];

            if ($event->isPlanning()) {
                (new PriceIndexSyncService())->queueWaitingSets();
                $event->setTotal(max(1, array_sum(array_map(static fn (array $set): int => $set['products'] - $set['done'], $waiting))));

                return;
            }

            $next = null;

            foreach ($waiting as $set) {
                if (\in_array($set['job']['state'] ?? '', [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true)) {
                    $next = $set['job'];

                    break;
                }
            }

            if ($next !== null) {
                $tick = Runner::tick(trigger: 'tools', budgetMs: self::JOB_STEP_MS, onlyJob: $next['id']);

                if ($tick->chunks > 0) {
                    $event->setNextOffset($event->getOffset() + max(1, $tick->units));

                    return;
                }
            }

            $event->setMessage($this->priceSetsState()['text']);
            $event->setRefresh();
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * One step of a tool that works a job (2.2.0): a tick on the scope's newest job, then its progress for the bar.
     * The tool's request goes on while the job advances; it ends when the job is done (the message the tool gives),
     * failed, or could not advance now (its retry waits, or another tick holds it), each said in the message.
     *
     * @param ExecuteToolActionEvent $event The tool request.
     * @param string $scope The job's scope.
     * @param callable(array{id: int, state: string, done: int, total: int, error: string}): string $done The message when the job is done.
     *
     * @since  2.2.0
     */
    private function stepJob(ExecuteToolActionEvent $event, string $scope, callable $done): void
    {
        $job = Runner::latestJob(scopeKey: $scope);
        $tick = null;

        if ($job !== null && \in_array($job['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true)) {
            $tick = Runner::tick(trigger: 'tools', budgetMs: self::JOB_STEP_MS, onlyJob: $job['id']);
            $job = Runner::latestJob(scopeKey: $scope) ?? $job;
        }

        if ($job === null) {
            $event->setTotal(0);

            return;
        }

        $event->setTotal(max($job['total'], $job['done'] + 1));

        if (\in_array($job['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true) && $tick !== null && $tick->chunks > 0) {
            $event->setNextOffset(max($event->getOffset() + 1, $job['done']));

            return;
        }

        $label = Text::_((string) Runner::handler(name: explode(':', $scope)[0])?->policy()->label);
        $event->setMessage(match ($job['state']) {
            Tasks::STATE_DONE => $done($job),
            Tasks::STATE_FAILED => Text::sprintf('COM_ALFA_TOOLS_JOB_FAILED', $label, $job['error']),
            default => Text::sprintf('COM_ALFA_TOOLS_JOB_WAITS', $label, $job['error'] !== '' ? $job['error'] : Text::_('COM_ALFA_TOOLS_JOB_HELD_ELSEWHERE')),
        });
        $event->setRefresh();
    }

    /**
     * What the staff are told of the list prices of customer-group combinations: the ones that
     * wait for their job, how far each is, and the products a preparation left out.
     *
     * @return array{owed: bool, text: string} Whether it asks for the merchant's eye, and what it says.
     *
     * @since  2.0.0
     */
    private function priceSetsState(): array
    {
        try {
            $state = PriceIndexSyncService::preparationState();
            $db = Factory::getContainer()->get('DatabaseDriver');
            $ids = array_values(array_unique(array_merge([], ...array_column($state['waiting'], 'groups'))));
            $titles = $ids === [] ? [] : $db->setQuery(
                'SELECT ' . implode(', ', $db->quoteName(['id', 'title'])) . ' FROM ' . $db->quoteName('#__usergroups')
                . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')',
            )->loadAssocList('id', 'title');
        } catch (Throwable) {
            // A state that cannot be read must not remove the card that explains it.
            return ['owed' => false, 'text' => ''];
        }

        $parts = [];

        if ($state['waiting'] === []) {
            $parts[] = Text::_('COM_ALFA_TOOLS_PRICE_SETS_NONE_WAITING');
        } else {
            $sets = [];

            foreach (\array_slice($state['waiting'], 0, 5) as $set) {
                $names = $set['groups'] === []
                    ? Text::_('COM_ALFA_TOOLS_PRICE_SETS_NO_GROUP')
                    : implode(' + ', array_map(static fn (int $id): string => (string) ($titles[$id] ?? '#' . $id), $set['groups']));
                $sets[] = Text::sprintf('COM_ALFA_TOOLS_PRICE_SETS_SET', $names, $set['products'] > 0 ? intdiv(100 * $set['done'], $set['products']) : 100);
            }

            $parts[] = Text::sprintf(
                'COM_ALFA_TOOLS_PRICE_SETS_WAITING',
                \count($state['waiting']),
                HTMLHelper::_('date', $state['waiting'][0]['requested_at'], Text::_('DATE_FORMAT_LC2')),
                implode('; ', $sets),
            );
        }

        if ($state['leftOut'] !== []) {
            $parts[] = Text::sprintf('COM_ALFA_TOOLS_PRICE_SETS_LEFT_OUT', \count($state['leftOut']), implode(', ', \array_slice($state['leftOut'], 0, 10)));
        }

        return ['owed' => $state['waiting'] !== [] || $state['leftOut'] !== [], 'text' => implode(' ', $parts)];
    }

    /**
     * (Re)create the per-language translation tables and columns.
     *
     * One request, however many languages: the work is DDL against missing structures
     * only, so a site where everything already exists pays a handful of information
     * probes and changes nothing.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    private function syncLanguages(ExecuteToolActionEvent $event): void
    {
        try {
            $result = SyncHelper::syncLanguageSchema();
            $created = 0;

            foreach ($result['tables'] as $tableSummary) {
                $created += \count($tableSummary['created'] ?? []);
            }

            $message = Text::sprintf('COM_ALFA_SYNC_LANGUAGES_DONE', \count($result['tables']), $created);

            if (!empty($result['errors'])) {
                $message .= ' ' . Text::sprintf('COM_ALFA_SYNC_LANGUAGES_ERRORS', implode(', ', array_keys($result['errors'])));
            }

            $event->setMessage($message);
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Fill empty translations, one chunk per request, across EVERY translatable table.
     *
     * THE MANY TABLES ARE FLATTENED ONTO ONE CURSOR. The chunk contract carries a
     * single offset, so the tables are laid end to end (offset 0 to N1 is the first
     * table, N1 to N1+N2 the second), and each chunk maps the global offset back to
     * (table, local offset). The mapping is recomputed from live COUNTs on every
     * chunk rather than frozen at planning. Because a backfill only ever fills EMPTY
     * translations, drift can never overwrite anything, but it is not symmetric:
     * rows ADDED behind the cursor merely get rows re-examined, while rows DELETED
     * behind it shift unexamined rows underneath the cursor, and those are missed by
     * THIS run. That is why a shrink is reported as "run it again" rather than as
     * plain success: the tool is a manual catch-up, and the honest completion
     * message is the only scheduler it has.
     *
     * THE PLAN MAKES THE MISSING LANGUAGE TABLES FIRST (2.1.0). A content language published
     * without the language sync (from the Content Languages list, or by a language pack) has no
     * language tables, and the fill leaves a language without them out: the run would say it
     * completed and fill nothing in that language. The plan therefore runs the language sync, as
     * Language tables does, and stops with its errors when a table could not be made; the chunks
     * are later requests and fill the new tables.
     *
     * THE COUNTS OF A LANGUAGE ADDED LATER are brought up to date when the run is over
     * ({@see ContentLanguageAddedNotice::afterBackfill()}): its notice clears when nothing is left.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    private function backfillTranslations(ExecuteToolActionEvent $event): void
    {
        try {
            if ($event->isPlanning()) {
                $sync = SyncHelper::syncLanguageSchema();

                if (!empty($sync['errors'])) {
                    $event->setError(Text::sprintf('COM_ALFA_SYNC_LANGUAGES_ERRORS', implode(', ', array_keys($sync['errors']))));

                    return;
                }
            }

            $db = Factory::getContainer()->get('DatabaseDriver');
            $schema = SyncHelper::getMultilingualSchema();
            $plan = [];
            $total = 0;

            foreach ($schema as $table => $definition) {
                $count = (int) $db->setQuery(
                    $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName($db->replacePrefix($table))),
                )->loadResult();

                $plan[] = ['table' => $table, 'definition' => $definition, 'total' => $count];
                $total += $count;
            }

            if ($event->isPlanning()) {
                $event->setTotal($total);

                return;
            }

            $offset = $event->getOffset();
            $event->setTotal($total);

            if ($offset >= $total) {
                // Reaching here with offset STRICTLY past the total means tables shrank
                // while the run was walking: rows shifted under the cursor and some
                // were not examined. Finish honestly: this run is over, and the message
                // says a re-run is owed instead of reporting untruthful completeness.
                $event->setMessage(Text::_(
                    $offset > $total ? 'COM_ALFA_TOOLS_BACKFILL_RERUN' : 'COM_ALFA_TOOLS_BACKFILL_DONE',
                ));
                ContentLanguageAddedNotice::afterBackfill();

                return;
            }

            // Map the global cursor onto (table, local offset).
            $cursor = $offset;

            foreach ($plan as $entry) {
                if ($cursor >= $entry['total']) {
                    $cursor -= $entry['total'];

                    continue;
                }

                $step = min(self::BACKFILL_CHUNK, $entry['total'] - $cursor);
                $result = MultilingualHelper::backfillEmptyTranslations(
                    tableName:         $entry['table'],
                    primaryColumnName: $entry['definition']['pk'],
                    fields:            $entry['definition']['fields'],
                    aliasFields:       $entry['definition']['aliasFields'],
                    aliasUniqueScope:  $entry['definition']['aliasScope'],
                    offset:            $cursor,
                    limit:             $step,
                );

                if (($result['written'] ?? 0) > 0) {
                    $this->refreshAfterBackfill($entry['table'], $cursor, $step);
                }

                $next = $offset + $step;

                if ($next < $total) {
                    $event->setNextOffset($next);
                } else {
                    $event->setMessage(Text::_('COM_ALFA_TOOLS_BACKFILL_DONE'));
                    ContentLanguageAddedNotice::afterBackfill();
                }

                return;
            }

            // Every table boundary consumed the cursor: the catalogue shrank mid-run.
            $event->setMessage(Text::_('COM_ALFA_TOOLS_BACKFILL_DONE'));
            ContentLanguageAddedNotice::afterBackfill();
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Refresh what a backfill chunk silently invalidated.
     *
     * Scoped to the tables whose per-language text is COPIED somewhere rather than
     * read live: `#__alfa_items` feeds the stored search document's title and alias;
     * `#__alfa_categories` feeds the category cache; categories AND manufacturers are
     * both materialised as search entity documents, so each announces its chunk
     * through the entity notifier. The other backfillable tables are read live and
     * need nothing.
     *
     * @param string $table The table just backfilled.
     * @param int $offset Local offset of the chunk within that table.
     * @param int $limit Rows the chunk covered.
     *
     *
     * @since  2.0.0
     */
    private function refreshAfterBackfill(string $table, int $offset, int $limit): void
    {
        try {
            // The backfill rewrote per-language text directly (no model save ran), so
            // the entity signal is emitted here for BOTH materialised entities: the
            // chunk's ids only, never the whole table.
            $entityByTable = ['#__alfa_categories' => 'category', '#__alfa_manufacturers' => 'manufacturer'];

            if (isset($entityByTable[$table])) {
                if ($table === '#__alfa_categories') {
                    CategoryHelper::clearCache();
                }

                $db = Factory::getContainer()->get('DatabaseDriver');

                $ids = array_map('intval', (array) $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName('id'))
                        ->from($db->quoteName($table))
                        ->order($db->quoteName('id') . ' ASC'),
                    $offset,
                    $limit,
                )->loadColumn());

                if ($ids !== []) {
                    EntityChangeNotifier::changed(entity: $entityByTable[$table], ids: $ids);
                }

                return;
            }

            if ($table !== '#__alfa_items') {
                return;
            }

            $db = Factory::getContainer()->get('DatabaseDriver');

            $ids = array_map('intval', (array) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__alfa_items'))
                    ->order($db->quoteName('id') . ' ASC'),
                $offset,
                $limit,
            )->loadColumn());

            if ($ids !== []) {
                (new ProjectionManager([new ItemSearchProjection()]))->syncItems($ids);
            }
        } catch (Throwable $e) {
            // A refresh that fails must not fail the backfill that triggered it; the
            // next save or rebuild self-heals what this pass missed.
        }
    }

    /**
     * Re-evaluate the setup checklist right now: bypasses the 6h cache and, through
     * the evaluation itself, reconciles the `alfa.setup` notification.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    private function refreshSetupChecklist(ExecuteToolActionEvent $event): void
    {
        try {
            $status = SetupHelper::status(fresh: true);
            $counts = $status['counts'] ?? [];

            $event->setMessage(Text::sprintf(
                'COM_ALFA_TOOLS_SETUP_DONE',
                (int) ($counts['done'] ?? 0),
                (int) ($counts['total'] ?? 0),
                (int) ($counts['required_missing'] ?? 0),
            ));
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Force the guided tours back to what the shipped JSON files say.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    private function reseedTours(ExecuteToolActionEvent $event): void
    {
        try {
            $result = \Alfa\Component\Alfa\Administrator\Setup\TourSeeder::seedAll(force: true);

            if ($result['errors'] !== []) {
                $event->setError(implode('; ', $result['errors']));

                return;
            }

            $event->setMessage(Text::sprintf(
                'COM_ALFA_TOOLS_TOURS_RESEED_DONE',
                \count($result['seeded']),
                \count($result['removed']),
            ));
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Dump our tours from the DB back into the JSON sources (dev round-trip).
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    private function exportTours(ExecuteToolActionEvent $event): void
    {
        try {
            $written = \Alfa\Component\Alfa\Administrator\Setup\TourSeeder::exportAll();

            $event->setMessage(Text::sprintf('COM_ALFA_TOOLS_TOURS_EXPORT_DONE', \count($written), implode(', ', $written)));
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * Catch Joomla users and usergroups up into the component's own tables.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  2.0.0
     */
    private function syncUsers(ExecuteToolActionEvent $event): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $users = SyncHelper::bulkSyncUsers($db);
            $groups = SyncHelper::bulkSyncUsergroups($db);

            $event->setMessage(Text::sprintf('COM_ALFA_SYNC_USERS_DONE', $users, $groups));
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * The configured engine, when it can be rebuilt at all.
     *
     *
     * @since  2.0.0
     */
    private function rebuildableEngine(): ?RebuildableEngine
    {
        try {
            $engine = (new SearchEngineRegistry())->configured();
        } catch (Throwable) {
            return null;
        }

        return $engine instanceof RebuildableEngine ? $engine : null;
    }

    /**
     * Rebuild the search index of the configured engine: the plan owes the search.rebuild job of that engine (2.2.0);
     * each step works it for a moment ({@see self::stepJob()}). The job opens the new generation, walks the catalogue
     * into it and activates it once, in its last chunk.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     * @since  2.0.0
     */
    private function rebuildSearchIndex(ExecuteToolActionEvent $event): void
    {
        try {
            $engine = (new SearchEngineRegistry())->configured();

            if (!$engine instanceof RebuildableEngine) {
                $event->setError(Text::sprintf('COM_ALFA_SEARCH_REBUILD_FAILED', 'no configured engine'));

                return;
            }

            if ($event->isPlanning()) {
                $joined = self::activeJob(scope: SearchRebuildJob::scopeFor(engine: $engine->key())) !== null;
                SearchRebuildJob::enqueueFor(engine: $engine->key());
                $event->setTotal(max(1, (new RebuildStore())->current($engine->key())->total ?? 1));
                self::followJob(event: $event, scope: SearchRebuildJob::scopeFor(engine: $engine->key()), joined: $joined);

                return;
            }

            $this->stepJob(event: $event, scope: SearchRebuildJob::scopeFor(engine: $engine->key()), done: static function (array $job): string {
                $scope = self::scopeLabels(parts: SearchScope::fromConfig()->enabled());

                return Text::sprintf('COM_ALFA_SEARCH_REBUILD_DONE', $job['done'], $scope === [] ? Text::_('COM_ALFA_SEARCH_REBUILD_CORE_ONLY') : implode(', ', $scope));
            });
        } catch (Throwable $e) {
            $event->setError(Text::sprintf('COM_ALFA_SEARCH_REBUILD_FAILED', $e->getMessage()));
        }
    }

    /**
     * Send the Tools screen to the progress page of the job just queued for a scope (Tools > Tasks, &job=N), whose
     * 2-second ticks follow it to its end. A request that joined a job already waiting or running (2.6.4: it merges
     * into that job) is told so, by the job's state, in the message area of the page that opens (2.2.0, entry 20, the
     * owner's s118, UX-85 and UX-86): a button pressed again never just goes grey.
     *
     * @param ExecuteToolActionEvent $event The tool request.
     * @param string $scope The job's scope.
     * @param bool $joined Whether a job of the scope was waiting or running before this request.
     *
     * @since  2.2.0
     */
    private static function followJob(ExecuteToolActionEvent $event, string $scope, bool $joined = false): void
    {
        $job = Runner::latestJob(scopeKey: $scope);

        if ($job === null) {
            return;
        }

        $event->setLocation(Route::_('index.php?option=com_alfa&view=tools&job=' . (int) $job['id'], false) . '#tasks');

        if ($joined && \in_array($job['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true)) {
            $event->setMessage(Text::sprintf(
                $job['state'] === Tasks::STATE_RUNNING ? 'COM_ALFA_TOOLS_JOB_JOINED_RUNNING' : 'COM_ALFA_TOOLS_JOB_JOINED_WAITING',
                Text::_((string) Runner::handler(name: explode(':', $scope)[0])?->policy()->label),
            ));
        }
    }

    /**
     * The job of a scope that is waiting or running now, or null: asked before a plan queues work, so a request that
     * joins it is told so ({@see self::followJob()}).
     *
     * @param string $scope The job's scope.
     *
     * @return array{id: int, state: string, done: int, total: int, error: string}|null
     *
     * @since  2.2.0
     */
    private static function activeJob(string $scope): ?array
    {
        $job = Runner::latestJob(scopeKey: $scope);

        return $job !== null && \in_array($job['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true) ? $job : null;
    }

    /**
     * The parts a rebuilt index covers, each in the operator's language, in the order Options lists them.
     *
     * @param string[] $parts The SearchScope parts in scope.
     *
     * @return string[] Their labels.
     *
     * @since  2.1.1
     */
    private static function scopeLabels(array $parts): array
    {
        $labels = [];

        foreach (self::SCOPE_PART_LABELS as $part => $key) {
            if (\in_array($part, $parts, true)) {
                $labels[] = Text::_($key);
            }
        }

        return $labels;
    }
}
