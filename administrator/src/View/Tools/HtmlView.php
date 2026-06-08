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
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tools / maintenance view — developer & sync utilities (language-table resync,
 * user/group resync, chunked translation backfill, package export).
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Libraries declared in the manifest, for the package-export card's "add these
     * manually for an installable build" note. Each entry: folder, libraryname,
     * installCode, installManifest.
     *
     * @var array<int, array{folder: string, libraryname: string, installCode: string, installManifest: string}>
     * @since  1.0.3
     */
    public array $libraries = [];

    /**
     * Manifest⇄disk drift detected for the live install, surfaced as a pre-export
     * warning. Shape: ['missing' => string[], 'undeclared' => string[]]. "missing"
     * = declared but absent (breaks install); "undeclared" = present on disk but
     * not declared (silently left out of the package/install).
     *
     * @var array{missing: string[], undeclared: string[]}
     * @since  1.0.3
     */
    public array $drift = ['missing' => [], 'undeclared' => []];

    /**
     * Suggested next version (current manifest version + 1 patch, e.g. 1.0.4 →
     * 1.0.5), so the contribution guide's SQL-update / version-bump examples match
     * this install instead of a hard-coded number.
     *
     * @since  1.0.4
     */
    public string $nextVersion = '';

    /**
     * Release-artifact readiness for the current manifest version — whether the
     * schema update and the removed-files list exist — so the guide can remind the
     * developer to add whichever is missing. Shape: ['version' => string,
     * 'sqlFile' => string, 'removedFile' => string, 'hasSqlUpdate' => bool,
     * 'hasRemovedJson' => bool].
     *
     * @var array{version: string, sqlFile: string, removedFile: string, hasSqlUpdate: bool, hasRemovedJson: bool}
     * @since  1.0.4
     */
    public array $release = ['version' => '', 'sqlFile' => '', 'removedFile' => '', 'hasSqlUpdate' => true, 'hasRemovedJson' => true];

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
     * @since  1.0.5
     */
    public array $official = ['status' => 'unreachable'];

    /**
     * The integrity verdict card, pre-rendered in {@see self::display()} from a
     * fixed-path partial (NOT the overridable layout system) so a template override
     * cannot silently fake the result — the Tools template only echoes this string.
     *
     * @since   1.0.9
     */
    public string $integrityHtml = '';

    /**
     * Render the Tools page.
     *
     * @param string|null $tpl The template name.
     *
     *
     * @since   1.0.1
     */
    public function display($tpl = null): void
    {
        // Gate the whole view — menu hiding is cosmetic, this is the real boundary.
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

        // Render the verdict card from a fixed-path partial — deliberately NOT the
        // overridable layout system — so a template override can't silently fake the
        // result. The Tools template only echoes the produced string.
        ob_start();
        include __DIR__ . '/_integrity.php';
        $this->integrityHtml = ob_get_clean();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Build the toolbar.
     *
     *
     * @since   1.0.1
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_TOOLS'), 'wrench');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->preferences('com_alfa');
    }
}
