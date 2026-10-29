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
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
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
     * The Tasks tab: the failed and held tasks ({@see TaskStaff::attentionRows()}), their number, and the names of
     * the users their histories name.
     *
     * @var array{rows: list<array<string, mixed>>, total: int, users: array<int, string>, unreadable: bool}
     * @since  2.1.4
     */
    public array $tasks = ['rows' => [], 'total' => 0, 'users' => [], 'unreadable' => false];

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

        $this->tasks = $this->tasks();

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
