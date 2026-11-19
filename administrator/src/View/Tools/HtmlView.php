<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Tools;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Access\GetAccessActionsEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\IntegrityHelper;
use Alfa\Component\Alfa\Administrator\Helper\PackageHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistrar;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerCronKey;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Throwable;

/**
 * Tools / maintenance view: developer & sync utilities (language-table resync,
 * user/group resync, chunked translation backfill, package export).
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Libraries declared in the manifest, for the package-export card's "add these
     * manually for an installable build" note. Each entry: folder, libraryname,
     * installCode, installManifest.
     *
     * @var array<int, array{folder: string, libraryname: string, installCode: string, installManifest: string}>
     * @since  1.0.0
     */
    public array $libraries = [];

    /**
     * Manifest<->disk drift detected for the live install, surfaced as a pre-export
     * warning. Shape: ['missing' => string[], 'undeclared' => string[]]. "missing"
     * = declared but absent (breaks install); "undeclared" = present on disk but
     * not declared (silently left out of the package/install).
     *
     * @var array{missing: string[], undeclared: string[]}
     * @since  1.0.0
     */
    public array $drift = ['missing' => [], 'undeclared' => []];

    /**
     * Suggested next version (current manifest version + 1 patch, e.g. 1.0.4 ->
     * 1.0.5), so the contribution guide's SQL-update / version-bump examples match
     * this install instead of a hard-coded number.
     *
     * @since  1.0.0
     */
    public string $nextVersion = '';

    /**
     * Release readiness for the current manifest version: whether the web-asset
     * registry declares the same version as the manifest, so the guide can remind
     * the developer to bump it before cutting a package. The census half stays
     * unrun here: it needs a built archive, which this page does not hold.
     *
     * @var array{version: string, assetFile: string, assetVersion: string, assetMatches: bool, censusRun: bool, packageOnly: string[]}
     * @since  1.0.0
     */
    public array $release = ['version' => '', 'assetFile' => '', 'assetVersion' => '', 'assetMatches' => true, 'censusRun' => false, 'packageOnly' => []];

    /**
     * Verification against the SIGNED canonical checksums on the CDN (see
     * {@see IntegrityHelper::verifyAgainstOfficial()}). `status` is the single source
     * of truth: `official` = verified pristine release (green); `modified` = real
     * deviation from the matched official version (alarm); `ahead` = install is ahead
     * of / customised from the published catalog (calm, expected when developing);
     * `unreachable` = CDN not reachable (network state, not tamper); `bad_signature`
     * = fetched a reference that isn't signed by us (do not trust).
     *
     * @var array{status: string}
     * @since  1.0.0
     */
    public array $official = ['status' => 'unreachable'];

    /**
     * The integrity verdict card, pre-rendered in {@see self::display()} from a
     * fixed-path partial (NOT the overridable layout system) so a template override
     * cannot silently fake the result; the Tools template only echoes this string.
     *
     * @since  1.0.0
     */
    public string $integrityHtml = '';

    /**
     * The routes extensions add to the REST API ({@see ApiRouteRegistrar::collect()}) and who may call them: for each
     * plugin its accepted and refused routes or why it cannot be loaded; for each permission its title, the groups
     * granted it, whether each has API Login and the token plugin's leave, and the accounts in it with an enabled
     * token. Drawn from a fixed-path partial, as the integrity verdict is.
     *
     * @var array{plugins: list<array<string, mixed>>, permissions: array<string, array<string, mixed>>, tokenPlugin: bool, unreadable: bool}
     * @since  2.2.2
     */
    public array $apiRoutes = ['plugins' => [], 'permissions' => [], 'tokenPlugin' => false, 'unreadable' => false];

    /**
     * The card of the routes extensions add, pre-rendered from a fixed-path partial (2.2.2).
     *
     * @since  2.2.2
     */
    public string $apiRoutesHtml = '';

    /**
     * The Tasks tab: the failed and held tasks ({@see TaskStaff::attentionRows()}), their number, and the names of
     * the users their histories name.
     *
     * @var array{rows: list<array<string, mixed>>, total: int, users: array<int, string>, unreadable: bool}
     * @since  2.1.4
     */
    public array $tasks = ['rows' => [], 'total' => 0, 'users' => [], 'unreadable' => false];

    /**
     * The Tasks tab's jobs ({@see TaskStaff::jobRows()}) and the names of the customer groups their price sets cover.
     *
     * @var array{rows: list<array<string, mixed>>, groups: array<int, string>, unreadable: bool}
     * @since  2.2.0
     */
    public array $jobs = ['rows' => [], 'groups' => [], 'engines' => [], 'thumbnailsMode' => null, 'followedJob' => 0, 'failedJobs' => 0, 'unreadable' => false];

    /**
     * The Tasks tab's runner card: the last tick (runner.last_tick), the cron command, the web-cron address without
     * its key, and whether a key is kept.
     *
     * @var array{lastTick: array<string, mixed>|null, cli: string, cron: string, keyKept: bool}
     * @since  2.2.0
     */
    public array $runner = ['lastTick' => null, 'cli' => '', 'cron' => '', 'keyKept' => false];

    /**
     * The job the progress page follows (&job=N), or null; jobMissing when &job names no job.
     *
     * @var array<string, mixed>|null
     * @since  2.2.0
     */
    public ?array $job = null;

    /**
     * Whether &job named a job there is not.
     *
     * @since  2.2.0
     */
    public bool $jobMissing = false;

    /**
     * A web-cron key made in this request (TasksController::generateKey()): shown in this answer only, never kept,
     * logged or put in a message.
     *
     * @since  2.2.0
     */
    public string $newCronKey = '';

    /**
     * Render the Tools page.
     *
     * @param string|null $tpl The template name.
     *
     *
     * @since  1.0.0
     */
    public function display($tpl = null): void
    {
        // Gate the whole view; menu hiding is cosmetic, this is the real boundary.
        if (!Factory::getApplication()->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->libraries = PackageHelper::describeLibraries(installRoot: JPATH_ROOT);
        $this->drift = PackageHelper::detectDrift(installRoot: JPATH_ROOT);
        $this->nextVersion = PackageHelper::nextVersion(installRoot: JPATH_ROOT);
        $this->release = PackageHelper::releaseReadiness(installRoot: JPATH_ROOT);
        // Use the cached verdict on open so repeatedly loading the page can't spam the
        // CDN check; the "Recheck now" button clears the cache to force a fresh scan.
        $this->official = IntegrityHelper::cachedVerdict();
        SyncHelper::applyIntegrityVerdict($this->official);

        // Render the verdict card from a fixed-path partial (deliberately NOT the
        // overridable layout system), so a template override can't silently fake the
        // result. The Tools template only echoes the produced string.
        ob_start();
        include __DIR__ . '/_integrity.php';
        $this->integrityHtml = ob_get_clean();

        // The routes extensions add to the REST API, from a fixed-path partial too: what an override draws cannot
        // hide a route or a token holder.
        $this->apiRoutes = $this->apiRoutes();
        ob_start();
        include __DIR__ . '/_apiroutes.php';
        $this->apiRoutesHtml = ob_get_clean();

        $this->tasks = $this->tasks();
        $this->jobs = $this->jobs();
        $this->runner = $this->runner();
        $jobId = RequestScalar::int(Factory::getApplication()->getInput(), 'job');

        if ($jobId > 0) {
            try {
                $this->job = TaskStaff::job(id: $jobId);
            } catch (Throwable $e) {
                Tasks::log(entry: 'Tools: job #' . $jobId . ' could not be read: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);
            }

            $this->jobMissing = $this->job === null;
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * The failed and held tasks for the Tasks tab, with the names of the users their histories name.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, users: array<int, string>, unreadable: bool}
     *
     * @since  2.1.4
     */
    private function tasks(): array
    {
        // A tasks table that cannot be read (an update whose SQL did not run, a restore) costs this card only: the
        // rest of Tools, its repair tools included, stays usable.
        try {
            $rows = TaskStaff::attentionRows();
            $total = TaskStaff::attentionTotal();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Tools: the tasks could not be read: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);

            return ['rows' => [], 'total' => 0, 'users' => [], 'unreadable' => true];
        }

        $ids = [];

        foreach ($rows as $row) {
            foreach ($row['history'] as $entry) {
                if ((int) ($entry['user'] ?? 0) > 0) {
                    $ids[(int) $entry['user']] = true;
                }
            }
        }

        $users = [];

        if ($ids !== []) {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $users = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'name']))
                    ->from($db->quoteName('#__users'))
                    ->whereIn($db->quoteName('id'), array_keys($ids)),
            )->loadAssocList('id', 'name') ?: [];
        }

        return ['rows' => $rows, 'total' => $total, 'users' => $users, 'unreadable' => false];
    }

    /**
     * The routes extensions add to the REST API and who may call them (2.2.2, P3-445), for Tools, Security. A reading
     * that fails costs this card only.
     *
     * @return array{plugins: list<array<string, mixed>>, permissions: array<string, array<string, mixed>>, tokenPlugin: bool, unreadable: bool}
     *
     * @since  2.2.2
     */
    private function apiRoutes(): array
    {
        try {
            $plugins = ApiRouteRegistrar::collect();
            $permissions = [];

            foreach ($plugins as $plugin) {
                AlfaHelper::loadPluginLanguage(group: PanelDispatcher::PLATFORM_GROUP, element: $plugin['element']);

                foreach ($plugin['accepted'] as $declaration) {
                    $permissions[$declaration->permission] ??= ['title' => '', 'groups' => []];
                }
            }

            if ($permissions === []) {
                return ['plugins' => $plugins, 'permissions' => [], 'tokenPlugin' => PluginHelper::isEnabled('api-authentication', 'token'), 'unreadable' => false];
            }

            // The permissions' titles, as the plugins declare them for Options, Permissions.
            $event = PanelDispatcher::broadcast(new GetAccessActionsEvent('onAlfaGetAccessActions', []));

            if ($event instanceof GetAccessActionsEvent) {
                foreach ($event->all() as $action) {
                    if (isset($permissions[$action->name])) {
                        $permissions[$action->name]['title'] = Text::_((string) $action->title);
                    }
                }
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $groups = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'title']))
                    ->from($db->quoteName('#__usergroups'))
                    ->order($db->quoteName('lft')),
            )->loadAssocList('id', 'title') ?: [];
            // The token's sign-in (API Authentication - Web Services Joomla Token) reads the allowed groups from the
            // plugin User - Joomla API Token, Super Users by default.
            $token = PluginHelper::getPlugin('api-authentication', 'token');
            $userToken = PluginHelper::getPlugin('user', 'token');
            $allowed = array_map('intval', (array) (new Registry(\is_object($userToken) ? (string) ($userToken->params ?? '') : ''))->get('allowedUserGroups', [8]));

            foreach (array_keys($permissions) as $permission) {
                foreach ($groups as $groupId => $title) {
                    $superUsers = Access::checkGroup((int) $groupId, 'core.admin') === true;

                    if (!$superUsers && Access::checkGroup((int) $groupId, $permission, 'com_alfa') !== true) {
                        continue;
                    }

                    $permissions[$permission]['groups'][] = [
                        'title' => (string) $title,
                        'superUsers' => $superUsers,
                        'apiLogin' => $superUsers || Access::checkGroup((int) $groupId, 'core.login.api') === true,
                        'tokenAllowed' => \in_array((int) $groupId, $allowed, true),
                        'accounts' => $this->tokenHolders(db: $db, groupId: (int) $groupId),
                    ];
                }
            }

            return ['plugins' => $plugins, 'permissions' => $permissions, 'tokenPlugin' => \is_object($token), 'unreadable' => false];
        } catch (Throwable $e) {
            Log::add('Tools: the routes extensions add could not be read: ' . $e::class . ': ' . $e->getMessage(), Log::ERROR, ApiRouteRegistrar::LOG);

            return ['plugins' => [], 'permissions' => [], 'tokenPlugin' => false, 'unreadable' => true];
        }
    }

    /**
     * The accounts of a group (directly in it) with an enabled Joomla API token, each marked when it is a Super User.
     *
     * @param DatabaseInterface $db The database.
     * @param int $groupId The group.
     *
     * @return list<array{name: string, username: string, superUser: bool}>
     *
     * @since  2.2.2
     */
    private function tokenHolders(DatabaseInterface $db, int $groupId): array
    {
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['u.id', 'u.name', 'u.username']))
                ->from($db->quoteName('#__users', 'u'))
                ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm') . ' ON ' . $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
                ->join('INNER', $db->quoteName('#__user_profiles', 'e') . ' ON ' . $db->quoteName('e.user_id') . ' = ' . $db->quoteName('u.id')
                    . ' AND ' . $db->quoteName('e.profile_key') . ' = ' . $db->quote('joomlatoken.enabled')
                    . ' AND ' . $db->quoteName('e.profile_value') . ' = ' . $db->quote('1'))
                ->join('INNER', $db->quoteName('#__user_profiles', 't') . ' ON ' . $db->quoteName('t.user_id') . ' = ' . $db->quoteName('u.id')
                    . ' AND ' . $db->quoteName('t.profile_key') . ' = ' . $db->quote('joomlatoken.token'))
                ->where($db->quoteName('m.group_id') . ' = :group')
                ->where($db->quoteName('u.block') . ' = 0')
                ->bind(':group', $groupId, ParameterType::INTEGER)
                ->order($db->quoteName('u.username')),
            0,
            50,
        )->loadObjectList() ?: [];

        return array_map(static fn (object $row): array => [
            'name' => (string) $row->name,
            'username' => (string) $row->username,
            'superUser' => Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $row->id)->authorise('core.admin'),
        ], $rows);
    }

    /**
     * The jobs for the Tasks tab, as the jobs card draws them ({@see TaskStaff::jobsCard()}): the same data the card's
     * own refresh on the progress page draws (UX-84). This page follows the job of &job=N when it is a progress page.
     *
     * @return array{rows: list<array<string, mixed>>, groups: array<int, string>, engines: array<string, string>, thumbnailsMode: string|null, followedJob: int, failedJobs: int, unreadable: bool}
     *
     * @since  2.2.0
     */
    private function jobs(): array
    {
        return TaskStaff::jobsCard(followedJob: RequestScalar::int(Factory::getApplication()->getInput(), 'job'));
    }

    /**
     * The runner card: the last tick, the cron command, and the web-cron address without its key.
     *
     * @return array{lastTick: array<string, mixed>|null, cli: string, cron: string, keyKept: bool}
     *
     * @since  2.2.0
     */
    private function runner(): array
    {
        $lastTick = null;
        $keyKept = false;

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $value = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('value'))
                    ->from($db->quoteName('#__alfa_meta'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote(Runner::META_LAST_TICK)),
            )->loadResult();
            $decoded = \is_string($value) ? json_decode($value, true) : null;
            $lastTick = \is_array($decoded) ? $decoded : null;
            $keyKept = RunnerCronKey::kept();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Tools: the runner state could not be read: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);
        }

        return [
            'lastTick' => $lastTick,
            'cli' => 'php ' . JPATH_ROOT . '/cli/joomla.php alfa:tasks:run',
            'cron' => rtrim(Uri::root(), '/') . '/index.php?option=com_alfa&task=runner.cron&key=',
            'keyKept' => $keyKept,
        ];
    }

    /**
     * Build the toolbar.
     *
     *
     * @since  1.0.0
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_TOOLS'), 'generic');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->preferences('com_alfa');

        TourHelper::toolbarHelp($toolbar, $this->getName());
    }
}
