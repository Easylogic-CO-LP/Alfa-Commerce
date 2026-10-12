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
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Projection\ItemFieldValueProjection;
use Alfa\Component\Alfa\Administrator\Service\Projection\PriceProjection;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Search\CatalogDocumentSource;
use Alfa\Component\Alfa\Administrator\Service\Search\ItemSearchProjection;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildableEngine;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildRunner;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildStore;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineInterface;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineRegistry;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchScope;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
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
 * @since  1.5.0
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
     * @since  1.5.0
     */
    public const OWNER_GROUP = 'core';

    /**
     * Owner name reserved for the component itself.
     *
     * @var string
     *
     * @since  1.5.0
     */
    public const OWNER_NAME = 'com_alfa';

    /**
     * Items per chunk when rebuilding the catalogue projections.
     *
     * A request-time and memory bound on core's own work, unrelated to any engine's
     * write batch: these projections have no engine to ask, which is precisely what
     * distinguishes them from a search index.
     *
     * @var int
     *
     * @since  1.5.0
     */
    private const PROJECTION_CHUNK = 200;

    /**
     * Records per backfill chunk. Larger than the projection chunk because a backfill
     * row is one INSERT of copied text, while a projection row fans out per language
     * and per index.
     *
     * @var int
     *
     * @since  1.5.0
     */
    private const BACKFILL_CHUNK = 500;

    /**
     * Stock movements per chunk of the apply-outstanding action. Small, because each
     * one can run a stock reconciliation and the inventory listeners behind it.
     *
     * @var int
     *
     * @since  1.10.0
     */
    private const STOCK_EFFECTS_CHUNK = 25;

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
     * @since  1.5.0
     */
    public function onGetToolActions(GetToolActionsEvent $event): void
    {
        // The setup checklist's manual re-run: an unknown surface id opens its own
        // Tools tab (ToolRegistry::extraSurfaces), so "Setup" appears without the
        // Tools template learning anything.
        $event->add('setup.checklist', 'COM_ALFA_TOOLS_SETUP_TITLE')
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
        $event->add('tours.reseed', 'COM_ALFA_TOOLS_TOURS_RESEED_TITLE')
            ->icon('map-signs')
            ->mutating()
            ->css('btn-secondary')
            ->priority(400)
            ->meta('surface', 'setup')
            ->meta('surface_label', 'COM_ALFA_TOOLS_TAB_SETUP')
            ->meta('description', 'COM_ALFA_TOOLS_TOURS_RESEED_DESC');

        $event->add('tours.export', 'COM_ALFA_TOOLS_TOURS_EXPORT_TITLE')
            ->icon('download')
            ->mutating()
            ->css('btn-secondary')
            ->priority(300)
            ->meta('surface', 'setup')
            ->meta('surface_label', 'COM_ALFA_TOOLS_TAB_SETUP')
            ->meta('description', 'COM_ALFA_TOOLS_TOURS_EXPORT_DESC');

        $event->add('tools.users', 'COM_ALFA_TOOLS_USERS_TITLE')
            ->icon('users')
            ->mutating()
            ->css('btn-secondary')
            ->priority(400)
            ->meta('surface', 'dbsync')
            ->meta('description', 'COM_ALFA_TOOLS_USERS_DESC');

        $event->add('tools.languages', 'COM_ALFA_TOOLS_LANG_SCHEMA_TITLE')
            ->icon('refresh')
            ->mutating()
            ->css('btn-primary')
            ->priority(300)
            ->meta('surface', 'dbsync')
            ->meta('description', 'COM_ALFA_TOOLS_LANG_SCHEMA_DESC');

        $event->add('tools.backfill', 'COM_ALFA_TOOLS_BACKFILL_TITLE')
            ->icon('language')
            ->mutating()
            ->css('btn-warning')
            ->priority(200)
            ->meta('surface', 'dbsync')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_BACKFILL_DESC');

        $event->add('tools.projections', 'COM_ALFA_TOOLS_PROJECTIONS_TITLE')
            ->icon('refresh')
            ->mutating()
            ->css('btn-warning')
            ->priority(100)
            ->meta('surface', 'dbsync')
            ->meta('chunked', true)
            ->meta('description', 'COM_ALFA_TOOLS_PROJECTIONS_DESC');

        // THE LIST PRICES OF A NEW COMBINATION OF CUSTOMER GROUPS are prepared by a scheduled
        // task, never by the request that brought the combination. The card says what waits and
        // how the task last ran; its button reads that again and prepares nothing.
        $priceSets = $this->priceSetsState();

        $priceSetsAction = $event->add('pricing.groupsets', 'COM_ALFA_TOOLS_PRICE_SETS_TITLE')
            ->icon('clock')
            ->readOnly()
            ->css('btn-secondary')
            ->priority(95)
            ->meta('surface', 'dbsync')
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

        $stockAction = $event->add('inventory.effects', 'COM_ALFA_TOOLS_STOCK_EFFECTS_TITLE')
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

            $action = $event->add('search.rebuild', $engine->rebuildLabel())
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
     * @since  1.5.0
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
            'pricing.groupsets' => $event->setMessage($this->priceSetsState()['text']),
            'inventory.effects' => $this->applyStockEffects($event),
            'setup.checklist' => $this->refreshSetupChecklist($event),
            'tours.reseed' => $this->reseedTours($event),
            'tours.export' => $this->exportTours($event),
            default => null,
        };
    }

    /**
     * Apply every stock movement an order or a document still owes.
     *
     * This is what the notification centre sends the merchant to, in place of a scheduled
     * task. The plan is a count, for the progress bar only; control flow is the drain. A
     * chunk that settles nothing (applies nothing and refuses nothing) ends the run,
     * because a movement that failed a moment ago is claimable again at once and would
     * otherwise be retried for ever. Finishing retires the warnings whose movement is no
     * longer owed.
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
     * @since  1.10.0
     */
    private function applyStockEffects(ExecuteToolActionEvent $event): void
    {
        try {
            if ($event->isPlanning()) {
                $event->setTotal(max(1, InventoryEffectOutbox::outstandingCount()));

                return;
            }

            // The effects dispatch events the `alfa` group listens to; a listener that is
            // not imported silently misses the movement.
            PluginHelper::importPlugin('alfa');

            $offset = $event->getOffset();
            $stats = InventoryEffectOutbox::drain(limit: self::STOCK_EFFECTS_CHUNK);
            $remaining = InventoryEffectOutbox::outstandingCount();

            $event->setTotal($offset + $stats['claimed'] + $remaining);

            if (($stats['applied'] + $stats['refused']) > 0 && $remaining > 0) {
                $event->setNextOffset($offset + $stats['claimed']);

                return;
            }

            InventoryEffectOutbox::doSyncNotice();

            $message = $remaining === 0
                ? Text::_('COM_ALFA_TOOLS_STOCK_EFFECTS_DONE')
                : Text::sprintf('COM_ALFA_TOOLS_STOCK_EFFECTS_REMAINING', $remaining);

            // Refused movements are never claimed again, so $remaining and later
            // plans leave them out; every one on record is reported on its own.
            $refused = InventoryEffectOutbox::refusedCount();

            if ($refused > 0) {
                $message .= ' ' . Text::sprintf('COM_ALFA_TOOLS_STOCK_EFFECTS_REFUSED', $refused);
            }

            $event->setMessage($message);
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
     * Both projections are in-place overwrites with no build state: the rows ARE
     * the state, so a chunk that fails leaves the previous rows rather than a lie, and
     * the next save self-heals the gap. That is why this needs no checkpoint table
     * while a search rebuild does.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  1.5.0
     */
    private function rebuildProjections(ExecuteToolActionEvent $event): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $total = (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__alfa_items')),
            )->loadResult();

            if ($event->isPlanning()) {
                $event->setTotal($total);

                return;
            }

            $offset = $event->getOffset();

            $ids = array_map('intval', (array) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__alfa_items'))
                    ->order($db->quoteName('id') . ' ASC'),
                $offset,
                self::PROJECTION_CHUNK,
            )->loadColumn());

            if ($ids !== []) {
                (new ProjectionManager([new PriceProjection(), new ItemFieldValueProjection()]))->syncItems($ids);
            }

            $next = $offset + self::PROJECTION_CHUNK;

            $event->setTotal($total);

            if ($next < $total) {
                $event->setNextOffset($next);

                return;
            }

            $event->setMessage(Text::sprintf('COM_ALFA_TOOLS_PROJECTIONS_DONE', $total));
        } catch (Throwable $e) {
            $event->setError($e->getMessage());
        }
    }

    /**
     * What the staff are told of the list prices of customer-group combinations: the ones that
     * wait for the scheduled task, the task's last run, and whether an enabled task runs it on a schedule.
     *
     * @return array{owed: bool, text: string} Whether it asks for the merchant's eye, and what it says.
     *
     * @since  1.14.0
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

        if ($state['lastRun'] === null) {
            $parts[] = Text::_('COM_ALFA_TOOLS_PRICE_SETS_NEVER_RUN');
        } else {
            $parts[] = Text::sprintf(
                match ($state['lastRun']['exit']) {
                    0 => 'COM_ALFA_TOOLS_PRICE_SETS_LAST_RUN_OK',
                    123 => 'COM_ALFA_TOOLS_PRICE_SETS_LAST_RUN_RESUME',
                    default => 'COM_ALFA_TOOLS_PRICE_SETS_LAST_RUN_FAILED',
                },
                HTMLHelper::_('date', $state['lastRun']['at'], Text::_('DATE_FORMAT_LC2')),
                $state['lastRun']['exit'],
            );
        }

        if ($state['scheduledTasks'] === 0) {
            $parts[] = Text::_('COM_ALFA_TOOLS_PRICE_SETS_NO_TASK');
        }

        return ['owed' => $state['waiting'] !== [] || $state['leftOut'] !== [] || $state['scheduledTasks'] === 0, 'text' => implode(' ', $parts)];
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
     * @since  1.5.0
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
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  1.5.0
     */
    private function backfillTranslations(ExecuteToolActionEvent $event): void
    {
        try {
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
                }

                return;
            }

            // Every table boundary consumed the cursor: the catalogue shrank mid-run.
            $event->setMessage(Text::_('COM_ALFA_TOOLS_BACKFILL_DONE'));
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
     * @since  1.5.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.5.0
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
     * @since  1.8.0
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
     * Run the search-index rebuild, one chunk per request.
     *
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  1.8.0
     */
    private function rebuildSearchIndex(ExecuteToolActionEvent $event): void
    {
        try {
            $engine = (new SearchEngineRegistry())->configured();

            if ($engine === null) {
                $event->setError(Text::sprintf('COM_ALFA_SEARCH_REBUILD_FAILED', 'no configured engine'));

                return;
            }

            $store = new RebuildStore();
            $runner = new RebuildRunner(new CatalogDocumentSource(), $engine->indexer());

            if ($event->isPlanning()) {
                $this->beginRebuild($engine, $store, $runner, $event);

                return;
            }

            $this->advanceRebuild($engine, $store, $runner, $event);
        } catch (Throwable $e) {
            $event->setError(Text::sprintf('COM_ALFA_SEARCH_REBUILD_FAILED', $e->getMessage()));
        }
    }

    /**
     * How long to wait for an asynchronous engine to drain before activation.
     *
     * @return float Seconds.
     *
     * @since  1.8.0
     */
    private function rebuildTimeout(): float
    {
        return 30.0;
    }

    /**
     * Open a generation and report how much work it represents.
     *
     * The planning pass is the ONE call the client makes exactly once, which makes it
     * the only place a generation can be opened without racing a retried chunk.
     *
     * @param SearchEngineInterface $engine The engine.
     * @param RebuildStore $store Durable checkpoint.
     * @param RebuildRunner $runner Core's rebuild algorithm.
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  1.5.0
     */
    private function beginRebuild(
        SearchEngineInterface $engine,
        RebuildStore $store,
        RebuildRunner $runner,
        ExecuteToolActionEvent $event,
    ): void {
        $progress = $runner->start();

        try {
            $store->open($engine->key(), $progress);
        } catch (Throwable $e) {
            // The slot was taken (a rebuild is already running, 409) or the
            // checkpoint could not be written. Without this, the generation opened
            // one line up stays pending forever, and pending generations receive
            // every future live write through writeTargets().
            $runner->discard($progress);

            throw $e;
        }

        // AN EMPTY CATALOGUE FINISHES HERE. With zero records the client's chunk loop
        // never runs, so a generation opened now would stay pending forever, with the
        // checkpoint holding the engine's rebuild slot until it goes stale. Walking
        // the empty stream to completion is one step, and ACTIVATING the empty
        // generation is correct rather than defensive: an emptied catalogue is
        // correctly served by an empty index, and readiness already knows the
        // difference between "empty because the shop is empty" and "empty because
        // never built".
        if ($progress->total === 0) {
            $progress = $runner->step($progress);
            $runner->finish($progress, $this->rebuildTimeout(), $store->writtenDuringRebuild($engine->key()));
            $store->close($engine->key());
        }

        $event->setTotal($progress->total);
    }

    /**
     * Advance the walk by one chunk, and finish when it runs dry.
     *
     * THE CLIENT'S OFFSET IS IGNORED, deliberately. The browser counts records
     * processed so it can draw a bar; the position the walk actually resumes from is
     * a keyset cursor held in the checkpoint. Trusting the client's number would make
     * a retried request re-walk from a stale point, and would put a resumable
     * operation's correctness in the hands of a page that may have been reloaded.
     *
     * @param SearchEngineInterface $engine The engine.
     * @param RebuildStore $store Durable checkpoint.
     * @param RebuildRunner $runner Core's rebuild algorithm.
     * @param ExecuteToolActionEvent $event The execution event.
     *
     *
     * @since  1.5.0
     */
    private function advanceRebuild(
        SearchEngineInterface $engine,
        RebuildStore $store,
        RebuildRunner $runner,
        ExecuteToolActionEvent $event,
    ): void {
        $progress = $store->current($engine->key());

        if ($progress === null) {
            // Nothing in flight: the checkpoint was cleared, or this is a stray call
            // after completion. Reporting done is honest and idempotent.
            $event->setTotal(0);

            return;
        }

        $progress = $runner->step($progress);
        $store->save($engine->key(), $progress);

        if (!$progress->enumerated) {
            $event->setTotal($progress->total)->setNextOffset($progress->processed);

            return;
        }

        // Reconcile against everything saved while the walk was running, then let core
        // decide whether the generation may serve. The plugin never makes that call.
        $runner->finish($progress, $this->rebuildTimeout(), $store->writtenDuringRebuild($engine->key()));
        $store->close($engine->key());

        $scope = SearchScope::fromConfig()->enabled();

        $event->setTotal($progress->total)
            ->setMessage(Text::sprintf(
                'COM_ALFA_SEARCH_REBUILD_DONE',
                $progress->processed,
                $scope === [] ? Text::_('COM_ALFA_SEARCH_REBUILD_CORE_ONLY') : implode(', ', $scope),
            ));
    }
}
