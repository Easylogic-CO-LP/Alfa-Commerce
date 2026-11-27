<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Tools;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\IntegrityHelper;
use Alfa\Component\Alfa\Administrator\Helper\PackageHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiAddressListing;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistrar;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerCronKey;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
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
     * The REST API's counts for the Security tab's card ({@see ApiAddressListing::summary()}): the endpoints, those
     * extensions add, the accounts with an enabled token and the Super Users among them; unreadable when the endpoints
     * could not be read (logged). The report itself, with its filters, is the API routes page.
     *
     * @var array{addresses: int, plugins: int, accounts: int, superUsers: int, unreadable: bool}
     * @since  2.3.0
     */
    public array $apiSummary = ['addresses' => 0, 'plugins' => 0, 'accounts' => 0, 'superUsers' => 0, 'unreadable' => false];

    /**
     * The Tasks tab's card: how much background work is in each state (running, waiting, needs attention, finished)
     * and how many outgoing notifications wait or were given up, as the Background screen lists them; unreadable when
     * the tables could not be read (logged).
     *
     * @var array{work: array<string, int>, deliveries: array<string, int>, unreadable: bool}
     * @since  2.3.0
     */
    public array $background = ['work' => [], 'deliveries' => [], 'unreadable' => false];

    /**
     * The Tasks tab's runner card: the last tick (runner.last_tick), the cron command, the web-cron address without
     * its key, and whether a key is kept.
     *
     * @var array{lastTick: array<string, mixed>|null, cli: string, cron: string, keyKept: bool}
     * @since  2.2.0
     */
    public array $runner = ['lastTick' => null, 'cli' => '', 'cron' => '', 'keyKept' => false];

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

        $this->apiSummary = $this->apiSummary();
        $this->background = $this->background();
        $this->runner = $this->runner();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * The counts of the Tasks tab's card: the background work in each state and the outgoing notifications waiting
     * or given up. Tables that cannot be read (an update whose SQL did not run, a restore) cost this card only: the rest
     * of Tools, its repair tools included, stays usable.
     *
     * @return array{work: array<string, int>, deliveries: array<string, int>, unreadable: bool}
     *
     * @since  2.3.0
     */
    private function background(): array
    {
        try {
            return ['work' => TaskStaff::workCounts(), 'deliveries' => OutboundConsumers::deliveryCounts(), 'unreadable' => false];
        } catch (Throwable $e) {
            Tasks::log(entry: 'Tools: the background work could not be counted: ' . $e::class . ': ' . ErrorText::of($e), priority: Log::ERROR);

            return ['work' => [], 'deliveries' => [], 'unreadable' => true];
        }
    }

    /**
     * The REST API's counts for the Security tab's card. A reading that fails costs this card only: it says so, and
     * the API routes page says why.
     *
     * @return array{addresses: int, plugins: int, accounts: int, superUsers: int, unreadable: bool}
     *
     * @since  2.3.0
     */
    private function apiSummary(): array
    {
        try {
            return ApiAddressListing::summary(addresses: ApiAddressListing::read()['addresses']) + ['unreadable' => false];
        } catch (Throwable $e) {
            Log::add('Tools: the REST API addresses could not be read: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, ApiRouteRegistrar::LOG);

            return ['addresses' => 0, 'plugins' => 0, 'accounts' => 0, 'superUsers' => 0, 'unreadable' => true];
        }
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
            Tasks::log(entry: 'Tools: the runner state could not be read: ' . $e::class . ': ' . ErrorText::of($e), priority: Log::ERROR);
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
