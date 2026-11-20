<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Tools;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\IntegrityHelper;
use Alfa\Component\Alfa\Administrator\Helper\PackageHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiAddress;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiAddressListing;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistrar;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerCronKey;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
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
     * The table of every REST API address and who may call it ({@see ApiAddressListing::read()}), for Tools, Security:
     * every address, the ones the filters keep, the summary's counts, the declarations core refused, the plugins that
     * cannot be loaded, the permissions' titles, the sources, and whether the token's sign-in and the webservices
     * plugin are enabled. Drawn from a fixed-path partial, as the integrity verdict is.
     *
     * @var array{addresses: list<ApiAddress>, shown: list<ApiAddress>, filtered: bool, summary: array{addresses: int, plugins: int, accounts: int, superUsers: int}, refused: list<array{name: string, refusal: \Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRefusal}>, unavailable: list<array{name: string, reason: string}>, titles: array<string, string>, sources: array<string, string>, tokenPlugin: bool, webservices: bool, unreadable: bool}
     * @since  2.2.2
     */
    public array $apiRoutes = [
        'addresses' => [], 'shown' => [], 'filtered' => false, 'summary' => ['addresses' => 0, 'plugins' => 0, 'accounts' => 0, 'superUsers' => 0],
        'refused' => [], 'unavailable' => [], 'titles' => [], 'sources' => [], 'tokenPlugin' => false, 'webservices' => false, 'unreadable' => false,
    ];

    /**
     * The filters of the REST API addresses' table (forms/filter_apiroutes.xml), drawn by Joomla's search tools.
     *
     * @since  2.2.2
     */
    public ?Form $filterForm = null;

    /**
     * The filters of that table a request sets: name => value.
     *
     * @var array<string, string>
     * @since  2.2.2
     */
    public array $activeFilters = [];

    /**
     * The card of the REST API addresses, pre-rendered from a fixed-path partial.
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

        // Every address of the REST API and who may call it, from a fixed-path partial too: what an override draws
        // cannot hide an address or a token holder.
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
     * Every address of the REST API and who may call it, for Tools, Security, and the ones the request's
     * filters keep (filter[source], filter[permission], filter[search]); the filter form with the sources and the
     * permissions the table has. A reading that fails costs this card only.
     *
     * @return array{addresses: list<ApiAddress>, shown: list<ApiAddress>, filtered: bool, summary: array{addresses: int, plugins: int, accounts: int, superUsers: int}, refused: list<array{name: string, refusal: \Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRefusal}>, unavailable: list<array{name: string, reason: string}>, titles: array<string, string>, sources: array<string, string>, tokenPlugin: bool, webservices: bool, unreadable: bool}
     *
     * @since  2.2.2
     */
    private function apiRoutes(): array
    {
        $empty = $this->apiRoutes;

        try {
            $listing = ApiAddressListing::read();
        } catch (Throwable $e) {
            Log::add('Tools: the REST API addresses could not be read: ' . $e::class . ': ' . $e->getMessage(), Log::ERROR, ApiRouteRegistrar::LOG);

            return ['unreadable' => true] + $empty;
        }

        $filters = (array) Factory::getApplication()->getInput()->get('filter', [], 'array');
        $chosen = [
            'search' => trim((string) RequestScalar::member($filters, 'search', 'STRING', 'filter')),
            'source' => (string) RequestScalar::member($filters, 'source', 'CMD', 'filter'),
            'permission' => (string) RequestScalar::member($filters, 'permission', 'CMD', 'filter'),
        ];
        $sources = ApiAddressListing::sources(addresses: $listing['addresses']);
        $this->activeFilters = array_filter($chosen, static fn (string $value): bool => $value !== '');
        $this->filterForm = $this->apiRoutesFilterForm(sources: $sources, titles: $listing['titles'], chosen: $chosen);

        return [
            'addresses' => $listing['addresses'],
            'shown' => ApiAddressListing::filter(addresses: $listing['addresses'], source: $chosen['source'], permission: $chosen['permission'], search: $chosen['search']),
            'filtered' => $this->activeFilters !== [],
            'summary' => ApiAddressListing::summary(addresses: $listing['addresses']),
            'refused' => $listing['refused'],
            'unavailable' => $listing['unavailable'],
            'titles' => $listing['titles'],
            'sources' => $sources,
            'tokenPlugin' => $listing['tokenPlugin'],
            'webservices' => $listing['webservices'],
            'unreadable' => false,
        ];
    }

    /**
     * The filter form of the addresses' table: forms/filter_apiroutes.xml with an option for each source and each
     * permission the table has (a permission named with its title), bound to the request's filters.
     *
     * @param array<string, string> $sources Source => name.
     * @param array<string, string> $titles Permission => title.
     * @param array{search: string, source: string, permission: string} $chosen The request's filters.
     *
     * @since  2.2.2
     */
    private function apiRoutesFilterForm(array $sources, array $titles, array $chosen): ?Form
    {
        try {
            $form = Factory::getContainer()->get(FormFactoryInterface::class)->createForm('com_alfa.tools.apiroutes.filter', ['control' => '']);
            $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_alfa/forms/filter_apiroutes.xml');
        } catch (Throwable $e) {
            Log::add('Tools: the filters of the REST API addresses could not be built: ' . $e::class . ': ' . $e->getMessage(), Log::ERROR, ApiRouteRegistrar::LOG);

            return null;
        }

        $option = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
        $sourceField = $form->getField('source', 'filter');
        $permissionField = $form->getField('permission', 'filter');

        if ($sourceField instanceof ListField) {
            foreach ($sources as $value => $name) {
                $sourceField->addOption($option($name), ['value' => $value]);
            }
        }

        if ($permissionField instanceof ListField) {
            $labels = [];

            foreach ($titles as $name => $title) {
                $labels[$name] = $title !== '' ? $title . ' (' . $name . ')' : $name;
            }

            natcasesort($labels);

            foreach ($labels as $name => $label) {
                $permissionField->addOption($option($label), ['value' => (string) $name]);
            }
        }

        $form->bind(['filter' => $chosen]);

        return $form;
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
