<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Setup\CollectSetupChecksEvent;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Setup\CoreSetupProvider;
use Alfa\Component\Alfa\Administrator\Setup\SetupCheck;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The store-setup checklist engine: collect the checks, derive their live states,
 * cache the verdict, and keep the `alfa.setup` notification honest.
 *
 * THE VERDICT IS DERIVED, THE DELIVERY IS CACHED. States are always recomputed from
 * what is true right now ({@see SetupCheck}), never from stored progress, but the
 * ambient surfaces (every admin page via the component dispatcher, later the
 * dashboard card) read a cached copy so they cost nothing. Freshness comes from
 * three directions, mirroring {@see IntegrityHelper::cachedVerdict()}:
 *
 *   - TTL           - {@see self::TTL_MINUTES} (6h, deliberately not a setting:
 *                     event-driven invalidation is the real freshness mechanism,
 *                     the TTL is only the backstop), recompute on expiry;
 *   - invalidation  - {@see self::invalidateFor()} from the admin models' save/
 *                     delete/publish path, and {@see self::invalidate()} from
 *                     plg_system_alfasync when the world outside com_alfa changes
 *                     (storefront menu items, gateway plugins, component params);
 *   - demand        - surfaces that show the checklist itself pass `$fresh = true`;
 *   - reading       - the page cache check's reading ({@see CoreSetupProvider::pageCacheReading()})
 *                     is kept beside the verdict and compared on every read: Joomla's page cache
 *                     and plg_system_alfasync change without an event the plugin can hear (its own
 *                     enabling, any switch while it is off), and a verdict computed under another
 *                     reading is computed again. Its cost on every status() call: two indexed reads
 *                     of #__extensions (the two plugins' rows) and the guest's view levels, which
 *                     Joomla reads once per request (the view levels table and the guest group).
 *
 * Notification push/clear happens ONLY inside a recompute, and re-alerts only when
 * the verdict actually transitions; a cache hit can never nag.
 *
 * @since  2.0.0
 */
final class SetupHelper
{
    /**
     * Cache group for the evaluated status (cleaned whole on invalidation).
     *
     * @var string
     * @since  2.0.0
     */
    public const CACHE_GROUP = 'com_alfa.setup';

    /**
     * The checklist notification's dedup key.
     *
     * @var string
     * @since  2.0.0
     */
    public const NOTIFICATION_KEY = 'alfa.setup';

    /**
     * Admin entities whose save/delete/publish can flip a detector's answer. The
     * admin models call {@see self::invalidateFor()} with their entity name; anything
     * outside this set costs nothing.
     *
     * @var string[]
     * @since  2.0.0
     */
    public const WATCHED_ENTITIES = [
        'payment',
        'shipment',
        'tax',
        'category',
        'item',
        'formfield',
        'formfieldgroup',
        'series',
        'currency',
    ];

    /**
     * Checklist cache lifetime, in minutes. Hardcoded on purpose; admins never tune
     * this: every save that can flip a detector invalidates explicitly, so the TTL
     * only bounds staleness from changes nothing observes (filesystem permissions,
     * manual DB edits).
     *
     * @var int
     * @since  2.0.0
     */
    private const TTL_MINUTES = 360;

    /**
     * The shape of the evaluated status ({@see self::evaluate()}). A cached status of another version is computed
     * again ({@see self::status()}): its rows mean something else.
     *
     * @var int
     * @since  2.2.2
     */
    public const STATUS_VERSION = 3;

    /**
     * The `#__alfa_meta` row that holds the steps the merchant ignored: a JSON list of their identities
     * (`group/element:check id`, the key of {@see self::collect()}). Per site, beside the shop's data and outside its
     * Options, so a reset of the Options keeps it.
     *
     * @var string
     * @since  2.2.2
     */
    public const IGNORED_META = 'setup.ignored';

    /**
     * The evaluated setup status, served from cache within the TTL.
     *
     * @param bool $fresh True to force a recompute (the checklist UI wants truth,
     *                    not politeness).
     *
     * @return array The status shape documented on {@see self::evaluate()}.
     *
     * @since  2.0.0
     */
    public static function status(bool $fresh = false): array
    {
        if ($fresh) {
            self::invalidate();
        }

        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => self::CACHE_GROUP,
                    'lifetime' => self::TTL_MINUTES,
                    'caching' => true,
                ]);

            $status = (array) $cache->get([self::class, 'evaluate'], [], 'status');

            // Computed again under another page cache reading, or when the cached status has another shape.
            if (($status['basis'] ?? null) !== CoreSetupProvider::pageCacheReading() || ($status['version'] ?? null) !== self::STATUS_VERSION) {
                self::invalidate();
                $status = (array) $cache->get([self::class, 'evaluate'], [], 'status');
            }

            return $status;
        } catch (Throwable) {
            // Cache unavailable -> compute directly (correct, just uncached).
            return self::evaluate();
        }
    }

    /**
     * Collect and run every applicable check, then reconcile the notification.
     * Public only because it is the cache callback for {@see self::status()};
     * callers want status().
     *
     * Shape:
     *   version   int     - cache-shape version (bump when rows change meaning)
     *   generated string  - SQL datetime of this evaluation
     *   basis     array   - the page cache check's reading this evaluation used
     *   checks    array[] - {id, tier, label, reason, cta, tour, priority, state,
     *                        provisionable, owner_group, owner_name, ignorable, ignored},
     *                        priority DESC
     *   counts    array   - total/done/required/required_missing/recommended_missing/
     *                        optional_missing/ignored; an ignored step is counted in
     *                        `ignored` only
     *   signature string  - md5 over id:state:ignored triples (transition detection)
     *
     * Rows carry language KEYS, not translated strings; the cache is shared across
     * admin languages; renderers translate.
     *
     *
     * @since  2.0.0
     */
    public static function evaluate(): array
    {
        $rows = [];
        $counts = [
            'total' => 0,
            'done' => 0,
            'required' => 0,
            'required_missing' => 0,
            'recommended_missing' => 0,
            'optional_missing' => 0,
            'ignored' => 0,
        ];

        // The steps the merchant ignored. The tier decides whether an ignore applies, each time: a step whose tier
        // becomes required is counted as required while it is, its ignore kept for when it is not.
        $ignoredSet = array_flip(self::ignored());

        foreach (self::collect() as $identity => $check) {
            $state = $check->run();
            $ignored = isset($ignoredSet[$identity]) && $check->ignorable();

            if ($ignored) {
                $counts['ignored']++;
            } else {
                $counts['total']++;
                $counts['required'] += $check->tier === SetupCheck::TIER_REQUIRED ? 1 : 0;

                if ($state === SetupCheck::STATE_PASS) {
                    $counts['done']++;
                } else {
                    $counts[$check->tier . '_missing']++;
                }
            }

            $reasonArgs = $state === SetupCheck::STATE_PASS ? [] : $check->reasonArgumentsNow();

            $rows[] = [
                'id' => $check->id,
                'tier' => $check->tier,
                'label' => $check->label,
                'reason' => $state === SetupCheck::STATE_PASS ? $check->reason : $check->reasonFor(arguments: $reasonArgs),
                'reason_args' => $reasonArgs,
                'cta' => $check->cta,
                'tour' => $check->tour,
                'priority' => $check->priority,
                'state' => $state,
                'provisionable' => $check->canProvision(),
                // What the auto-fix must be told before it runs (e.g. which menu).
                'choices' => $check->canProvision() ? $check->choices() : [],
                'owner_group' => (string) ($check->metadata['owner_group'] ?? ''),
                'owner_name' => (string) ($check->metadata['owner_name'] ?? ''),
                'ignorable' => $check->ignorable(),
                'ignored' => $ignored,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $pairs = array_map(static fn (array $row): string => $row['id'] . ':' . $row['state'] . ':' . (int) $row['ignored'], $rows);

        $status = [
            'version' => self::STATUS_VERSION,
            'generated' => Factory::getDate()->toSql(),
            // What the page cache check read: a later read under another one computes again (status()).
            'basis' => CoreSetupProvider::pageCacheReading(),
            'checks' => $rows,
            'counts' => $counts,
            'signature' => md5(implode('|', $pairs)),
        ];

        self::applyNotification($status);

        return $status;
    }

    /**
     * Drop the cached status so the next {@see self::status()} recomputes. Cheap and
     * safe to call optimistically: the recompute happens lazily, on the next read.
     *
     *
     * @since  2.0.0
     */
    public static function invalidate(): void
    {
        try {
            // Via the controller factory, NOT Factory::getCache($group, ''): an empty
            // handler there throws (no such controller class) and the catch would eat
            // it, turning every invalidation into a silent no-op.
            Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => self::CACHE_GROUP])
                ->cache->clean();
        } catch (Throwable) {
            // Best-effort: a stale checklist for up to the TTL is harmless.
        }
    }

    /**
     * Invalidate IF this admin entity can flip a detector. The admin models call this
     * on every successful save/delete/publish with their `$alfaEntity`; the set
     * membership keeps order saves and other hot paths at zero cost.
     *
     * @param string $entity The model's entity name, e.g. `payment`.
     *
     *
     * @since  2.0.0
     */
    public static function invalidateFor(string $entity): void
    {
        if (\in_array($entity, self::WATCHED_ENTITIES, true)) {
            self::invalidate();
        }
    }

    /**
     * Ambient tick for the component dispatcher: serve the cached status (recomputing
     * at most once per TTL) and never let checklist housekeeping touch the page.
     *
     *
     * @since  2.0.0
     */
    public static function sync(): void
    {
        try {
            self::status();
        } catch (Throwable $e) {
            Log::add('SetupHelper: ambient sync failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.setup');
        }
    }

    /**
     * Merge key/value pairs into com_alfa's extension params: fresh read, merge,
     * write, so concurrent editors lose at most their own keys. The in-request
     * ComponentHelper registry is refreshed best-effort so later reads in the same
     * request see the new values.
     *
     * @param array<string, scalar> $pairs The params to set.
     *
     *
     * @since  2.0.0
     */
    public static function mergeParams(array $pairs): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $element = 'com_alfa';
        $type = 'component';

        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':element', $element, ParameterType::STRING);

        $current = json_decode((string) $db->setQuery($query, 0, 1)->loadResult(), true);
        $params = json_encode(array_merge(\is_array($current) ? $current : [], $pairs));

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':params', $params, ParameterType::STRING)
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':element', $element, ParameterType::STRING);

        $db->setQuery($query)->execute();

        try {
            $registry = ComponentHelper::getParams('com_alfa');

            foreach ($pairs as $key => $value) {
                $registry->set($key, $value);
            }
        } catch (Throwable) {
            // Per-request convenience only; the DB row is the truth.
        }
    }

    /**
     * The site's published CONTENT languages as a lang_code => sef map, or [] on a
     * monolingual site (one published language means per-language routing is
     * meaningless). Detection and provisioning share this so "reachable" always
     * means the same thing.
     *
     * @return array<string, string> e.g. ['el-GR' => 'el', 'en-GB' => 'en'].
     *
     * @since  2.0.0
     */
    public static function contentLanguages(): array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName(['lang_code', 'sef']))
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('published') . ' = 1');

            $rows = (array) $db->setQuery($query)->loadObjectList();

            if (\count($rows) < 2) {
                return [];
            }

            $map = [];

            foreach ($rows as $row) {
                $map[(string) $row->lang_code] = (string) $row->sef;
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The menu item ids the setup checklist itself created: the ONLY menu rows any
     * future cleanup may touch. Read fresh from the params row, not the per-request
     * registry, because provisioning tracks ids in the same request that reads them.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public static function createdMenuIds(): array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $element = 'com_alfa';
            $type = 'component';

            $query = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':type', $type, ParameterType::STRING)
                ->bind(':element', $element, ParameterType::STRING);

            $params = json_decode((string) $db->setQuery($query, 0, 1)->loadResult(), true);
            $ids = json_decode((string) ($params['setup_created_menu_ids'] ?? '[]'), true);

            return \is_array($ids) ? array_values(array_map('intval', $ids)) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Run one check's auto-fix. The check must exist and declare a provisioner;
     * whatever it creates, the verdict is re-derived immediately after: success is
     * the DETECTOR flipping, never the provisioner's word.
     *
     * @param string $checkId The check id, e.g. `storefront.menu`.
     * @param array<string, string> $answers The merchant's answers to the check's own questions.
     *
     * @return array{ok: bool, error: string}
     *
     * @since  2.0.0
     */
    public static function provision(string $checkId, array $answers = []): array
    {
        foreach (self::collect() as $check) {
            if ($check->id !== $checkId || !$check->canProvision()) {
                continue;
            }

            try {
                $check->runProvision($answers);
                self::invalidate();

                return ['ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                // Whatever half-happened, the next evaluation must look again.
                self::invalidate();
                Log::add('SetupHelper: provision ' . $checkId . ' failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.setup');

                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return ['ok' => false, 'error' => 'No provisionable check: ' . $checkId];
    }

    /**
     * The identities of the steps the merchant ignored (`group/element:check id`), as stored.
     *
     * @return list<string>
     *
     * @since  2.2.2
     */
    public static function ignored(): array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $name = self::IGNORED_META;
            $query = $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = :name')
                ->bind(':name', $name, ParameterType::STRING);
            $stored = json_decode((string) $db->setQuery($query)->loadResult(), true);
        } catch (Throwable) {
            return [];
        }

        return \is_array($stored) ? array_values(array_unique(array_filter($stored, static fn ($value): bool => \is_string($value) && $value !== ''))) : [];
    }

    /**
     * Ignore one step of the checklist: a recommended or optional step that is not done. A required step is never
     * ignored, and a done step has nothing to ignore.
     *
     * @param string $owner The step's owner, `group.element` (`core.com_alfa` for core's steps).
     * @param string $checkId The step's id, e.g. `mail.configured`.
     *
     * @return array{ok: bool, error: string} The error is a language key.
     *
     * @since  2.2.2
     */
    public static function ignore(string $owner, string $checkId): array
    {
        $identity = self::identity(owner: $owner, checkId: $checkId);
        $check = $identity === '' ? null : (self::collect()[$identity] ?? null);

        if ($check === null) {
            return ['ok' => false, 'error' => 'COM_ALFA_SETUP_IGNORE_UNKNOWN'];
        }

        if (!$check->ignorable()) {
            return ['ok' => false, 'error' => 'COM_ALFA_SETUP_IGNORE_REQUIRED'];
        }

        if ($check->run() === SetupCheck::STATE_PASS) {
            return ['ok' => false, 'error' => 'COM_ALFA_SETUP_IGNORE_DONE'];
        }

        return self::changeIgnored(static fn (array $identities): array => array_merge($identities, [$identity]));
    }

    /**
     * Show an ignored step again. Any stored identity is removed, whether its step is on the checklist or not: a step
     * whose plugin has gone can still be taken off the list.
     *
     * @param string $owner The step's owner, `group.element`.
     * @param string $checkId The step's id.
     *
     * @return array{ok: bool, error: string} The error is a language key.
     *
     * @since  2.2.2
     */
    public static function showAgain(string $owner, string $checkId): array
    {
        $identity = self::identity(owner: $owner, checkId: $checkId);

        return self::changeIgnored(static fn (array $identities): array => array_values(array_diff($identities, [$identity])));
    }

    /**
     * A step's identity from its owner as the card names it (`group.element`) and its id: the key of
     * {@see self::collect()}. '' when the owner names no group and element.
     *
     * @param string $owner `group.element`.
     * @param string $checkId The step's id.
     *
     *
     * @since  2.2.2
     */
    private static function identity(string $owner, string $checkId): string
    {
        $parts = explode('.', $owner, 2);

        return \count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' && $checkId !== ''
            ? $parts[0] . '/' . $parts[1] . ':' . $checkId
            : '';
    }

    /**
     * Change the ignored list in one transaction: the row is read locked (SELECT ... FOR UPDATE), so two
     * administrators who press at once change it one after the other and neither loses the other's choice, then
     * written again (removed when the list is empty). A read that fails refuses the change and writes nothing, so it
     * never replaces the list; a stored value that is not a list is read as an empty one and written as a list.
     *
     * @param callable $change fn(list<string> $identities): list<string>, the list as it is to be.
     *
     * @return array{ok: bool, error: string} The error is a language key.
     *
     * @since  2.2.2
     */
    private static function changeIgnored(callable $change): array
    {
        /** @var DatabaseDriver $db */
        $db = Factory::getContainer()->get(DatabaseDriver::class);
        $where = $db->quoteName('name') . ' = ' . $db->quote(self::IGNORED_META);

        $db->transactionStart(true);

        try {
            $read = $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($where);
            $stored = $db->setQuery((string) $read . ' FOR UPDATE')->loadResult();
            $identities = $stored === null ? [] : json_decode((string) $stored, true);

            // A stored value that is not a list is read as no step ignored, as the evaluation reads it, and the
            // change writes a list again: only a read that fails refuses.
            if (!\is_array($identities)) {
                Log::add('SetupHelper: the stored list of ignored setup steps was not a list; it is read as empty.', Log::WARNING, 'com_alfa.setup');
                $identities = [];
            }

            $identities = array_values(array_unique(array_filter(
                $change(array_values(array_filter($identities, static fn ($value): bool => \is_string($value) && $value !== ''))),
                static fn ($value): bool => \is_string($value) && $value !== '',
            )));

            $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__alfa_meta'))->where($where))->execute();

            if ($identities !== []) {
                // A variable, not an inline expression: insertObject() takes the row by reference.
                $row = (object) ['name' => self::IGNORED_META, 'value' => json_encode($identities, JSON_UNESCAPED_SLASHES)];
                $db->insertObject('#__alfa_meta', $row);
            }

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            Log::add('SetupHelper: the ignored setup steps were not changed (' . $e::class . ').', Log::WARNING, 'com_alfa.setup');

            return ['ok' => false, 'error' => 'COM_ALFA_SETUP_IGNORE_NOT_SAVED'];
        }

        self::invalidate();

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Collect every check: core's first (in the event's constructor), then every
     * listener's on the shared dispatcher, in one isolated dispatch after the `alfa`
     * platform group is imported; a listener that throws is skipped (logged in
     * com_alfa.plugins, and named in com_alfa.setup). Each plugin's check is owned by the contributor it names to
     * CollectSetupChecksEvent::add() (group.element, attribution only).
     *
     * @return array<string, SetupCheck> Keyed `owner_group/owner_name:check_id`.
     *
     * @since  2.0.0
     */
    private static function collect(): array
    {
        // Core's checks first, owned by core.com_alfa, in the event before any plugin is asked (its constructor).
        $event = new CollectSetupChecksEvent(CollectSetupChecksEvent::NAME, ['coreChecks' => (new CoreSetupProvider())->checks()]);
        $skipped = [];

        // Every listener on the shared dispatcher (2.2.0, design 2.15.3: mode A, a display phase, isolated), the
        // platform group imported first (a plugin that cannot import is logged and the others are imported); each
        // check names its plugin.
        $plugins = AlfaPluginHelper::get();
        $plugins->importGroup(PanelDispatcher::PLATFORM_GROUP);

        foreach ($plugins->dispatchIsolated($event) as $failure) {
            $skipped[] = $failure->listener . ' (' . $failure->error->getMessage() . ')';
        }

        if ($skipped !== []) {
            Log::add(
                entry:    'SetupHelper: skipped broken setup contributors: ' . implode('; ', $skipped),
                priority: Log::WARNING,
                category: 'com_alfa.setup',
            );
        }

        // Composite identity, first registration wins: ids are shared vocabulary and
        // two owners may legitimately declare the same one.
        $indexed = [];

        foreach ($event->getChecks() as $check) {
            $owner = ($check->metadata['owner_group'] ?? '') . '/' . ($check->metadata['owner_name'] ?? '');
            $key = $owner . ':' . $check->id;

            if ($check->id !== '' && !isset($indexed[$key])) {
                $indexed[$key] = $check;
            }
        }

        return $indexed;
    }

    /**
     * Reconcile the `alfa.setup` notification with a freshly evaluated status.
     *
     * Transitions only: all required steps done -> hard-clear (a fluctuating live
     * state must not fill history); missing count UNCHANGED -> content refresh that
     * preserves read state (no nag); count CHANGED -> constant re-alert, because the
     * situation the merchant read about is no longer the situation.
     *
     * @param array $status The evaluation from {@see self::evaluate()}.
     *
     *
     * @since  2.0.0
     */
    private static function applyNotification(array $status): void
    {
        try {
            $missing = (int) ($status['counts']['required_missing'] ?? 0);

            if ($missing === 0) {
                if (NotificationHelper::exists(self::NOTIFICATION_KEY)) {
                    NotificationHelper::clear(dedupKey: self::NOTIFICATION_KEY, hard: true);
                }

                return;
            }

            $title = NotificationText::of('COM_ALFA_NOTIFY_SETUP_TITLE', $missing);

            try {
                $url = Route::_('index.php?option=com_alfa&view=dashboard', false);
            } catch (Throwable) {
                $url = 'index.php?option=com_alfa&view=dashboard';
            }

            NotificationHelper::push(
                dedupKey: self::NOTIFICATION_KEY,
                title: $title,
                options: [
                    'group' => NotificationHelper::GROUP_SETUP,
                    'severity' => 'warning',
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_SETUP_MESSAGE'),
                    'url' => $url,
                    'dismissible' => false,
                    // Re-alert only on a real transition; an unchanged verdict keeps
                    // its read state and stays quiet.
                    'constant' => self::storedTitle() !== [$title->key, $title->args],
                    'link' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable $e) {
            Log::add('SetupHelper: notification reconcile failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.setup');
        }
    }

    /**
     * Render the setup card: the SINGLE render path, used by the Dashboard view on
     * page load and by the refresh endpoint, so the two can never drift (the
     * notifications badge convention).
     *
     * @param bool $fresh True to recompute instead of reading the cached verdict:
     *                    the refresh button wants truth, the ambient page load wants
     *                    the cache.
     *
     * @return string The rendered `setup.card` layout.
     *
     * @since  2.0.0
     */
    public static function renderCard(bool $fresh = false): string
    {
        $status = self::status($fresh);
        $uids = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['tour'] ?? ''),
            $status['checks'] ?? [],
        )));

        return LayoutHelper::render(
            'setup.card',
            [
                'status' => $status,
                'tours' => self::tourAvailability($uids),
                'state' => self::cardState(),
                // Decided per viewer at render time; status() is cached for everyone. A
                // one-click fix creates site menu items, so it is a Super User's
                // (SetupController::provision refuses anyone else).
                'canProvision' => (bool) Factory::getApplication()->getIdentity()?->authorise('core.admin', 'com_alfa'),
            ],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    /**
     * Which of the given guided-tour uids actually exist (published) and whether the
     * CURRENT user has already completed or skipped each: drives the tour-first
     * button flip on checklist rows. Tours ship in a later slice, so an empty answer
     * simply renders plain CTAs: the card degrades, never breaks.
     *
     * @param string[] $uids Tour uids (`com_alfa.*`).
     *
     * @return array<string, array{id: int, seen: bool}> Keyed by uid; absent = no such tour.
     *
     * @since  2.0.0
     */
    public static function tourAvailability(array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids)));

        if ($uids === []) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'uid']))
                ->from($db->quoteName('#__guidedtours'))
                ->whereIn($db->quoteName('uid'), $uids, ParameterType::STRING)
                ->where($db->quoteName('published') . ' = 1');

            $tours = (array) $db->setQuery($query)->loadObjectList();

            if ($tours === []) {
                return [];
            }

            // Per-user tour outcomes, the 5.2 autostart state: profile keys
            // `guidedtour.id.<id>` holding {"state":"completed|skipped|delayed",...}.
            $userId = (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
            $seen = [];

            if ($userId > 0) {
                $like = 'guidedtour.id.%';
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['profile_key', 'profile_value']))
                    ->from($db->quoteName('#__user_profiles'))
                    ->where($db->quoteName('user_id') . ' = :user')
                    ->where($db->quoteName('profile_key') . ' LIKE :like')
                    ->bind(':user', $userId, ParameterType::INTEGER)
                    ->bind(':like', $like, ParameterType::STRING);

                foreach ((array) $db->setQuery($query)->loadObjectList() as $row) {
                    $tourId = (int) substr((string) $row->profile_key, \strlen('guidedtour.id.'));
                    $state = (string) (json_decode((string) $row->profile_value, true)['state'] ?? '');

                    if ($tourId > 0 && \in_array($state, ['completed', 'skipped'], true)) {
                        $seen[$tourId] = true;
                    }
                }
            }

            $availability = [];

            foreach ($tours as $tour) {
                $availability[(string) $tour->uid] = [
                    'id' => (int) $tour->id,
                    'seen' => !empty($seen[(int) $tour->id]),
                ];
            }

            return $availability;
        } catch (Throwable) {
            // No tours table / no user: plain CTAs.
            return [];
        }
    }

    /**
     * The current user's card presentation state: 'open' or 'collapsed'. Stored in
     * `#__user_profiles` (NOT localStorage: it must survive browsers and machines,
     * and a colleague's dismissal must not hide the card from everyone).
     *
     * @return string 'open' | 'collapsed'.
     *
     * @since  2.0.0
     */
    public static function cardState(): string
    {
        try {
            $userId = (int) (Factory::getApplication()->getIdentity()?->id ?? 0);

            if ($userId <= 0) {
                return 'open';
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $key = 'alfa.setup.card';
            $query = $db->getQuery(true)
                ->select($db->quoteName('profile_value'))
                ->from($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :user')
                ->where($db->quoteName('profile_key') . ' = :key')
                ->bind(':user', $userId, ParameterType::INTEGER)
                ->bind(':key', $key, ParameterType::STRING);

            return $db->setQuery($query)->loadResult() === 'collapsed' ? 'collapsed' : 'open';
        } catch (Throwable) {
            return 'open';
        }
    }

    /**
     * Persist the current user's card state.
     *
     * @param string $state 'open' | 'collapsed' (anything else is coerced to 'open').
     *
     *
     * @since  2.0.0
     */
    public static function setCardState(string $state): void
    {
        try {
            $userId = (int) (Factory::getApplication()->getIdentity()?->id ?? 0);

            if ($userId <= 0) {
                return;
            }

            $state = $state === 'collapsed' ? 'collapsed' : 'open';
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $key = 'alfa.setup.card';

            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :user')
                ->where($db->quoteName('profile_key') . ' = :key')
                ->bind(':user', $userId, ParameterType::INTEGER)
                ->bind(':key', $key, ParameterType::STRING);
            $db->setQuery($query)->execute();

            // A variable, not an inline expression: insertObject() takes the row by
            // reference and PHP 8 fatals on a temporary.
            $row = (object) [
                'user_id' => $userId,
                'profile_key' => $key,
                'profile_value' => $state,
                'ordering' => 1,
            ];

            $db->insertObject('#__user_profiles', $row);
        } catch (Throwable $e) {
            Log::add('SetupHelper: card state save failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.setup');
        }
    }

    /**
     * The currently stored checklist notification title, for transition detection: its key and parameters, compared
     * as such (since 2.1.4: the stored text is a snapshot in the shop's default administrator language).
     *
     * @return array{0: string, 1: list<mixed>} ['', []] when no row carries the key.
     *
     * @since  2.0.0
     */
    private static function storedTitle(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $key = self::NOTIFICATION_KEY;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['title_key', 'text_args']))
            ->from($db->quoteName('#__alfa_notifications'))
            ->where($db->quoteName('dedup_key') . ' = :key')
            ->bind(':key', $key, ParameterType::STRING);

        $row = $db->setQuery($query, 0, 1)->loadObject();

        return $row === null ? ['', []] : [(string) $row->title_key, NotificationText::arguments(row: $row)['title'] ?? []];
    }
}
