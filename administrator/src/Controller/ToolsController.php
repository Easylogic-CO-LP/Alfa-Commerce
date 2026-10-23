<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\IntegrityHelper;
use Alfa\Component\Alfa\Administrator\Helper\PackageHelper;
use Alfa\Component\Alfa\Administrator\Service\Download\StoreProtection;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Throwable;

/**
 * Developer tools controller: actions for the Tools view that don't belong to a
 * more specific tool controller (toolaction, media). Currently exposes the package
 * export; further developer utilities can be added here as new tasks.
 *
 * The `export` task re-assembles the live com_alfa install back into a repo-layout
 * archive (via {@see PackageHelper}), streams it to the browser as an attachment,
 * then deletes the temporary file, so nothing is left on disk. Gated by the
 * `alfa.tools` permission and a CSRF token, matching the other Tools actions.
 *
 * @since  1.0.0
 */
class ToolsController extends BaseController
{
    /**
     * Force a fresh integrity scan: drop the cached verdict so the next Tools render
     * recomputes it against the signed CDN checksums. Token-guarded and cheap, so the
     * Security tab can no longer be spammed into re-scanning on every page load.
     *
     *
     * @since  1.0.0
     */
    public function recheckIntegrity(): void
    {
        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if (Session::checkToken('request')) {
            IntegrityHelper::clearVerdictCache();
            $this->setMessage(Text::_('COM_ALFA_TOOLS_INTEGRITY_RECHECKED'));
        } else {
            $this->setMessage(Text::_('JINVALID_TOKEN'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=tools', false));
    }

    /**
     * "Check again" for the download store's direct-download protection: probe it now
     * ({@see StoreProtection::check()}) and go back to the page that asked, which shows the
     * result (the dashboard, a product's Downloads panel, the Options).
     *
     * @since  2.0.0
     */
    public function checkDownloadStore(): void
    {
        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $return = base64_decode($this->input->getBase64('return', ''), true);
        $redirect = \is_string($return) && $return !== '' && Uri::isInternal($return)
            ? $return
            : Route::_('index.php?option=com_alfa&view=dashboard', false);

        if (!Session::checkToken('request')) {
            $this->setRedirect($redirect, Text::_('JINVALID_TOKEN'), 'error');

            return;
        }

        try {
            (new StoreProtection())->check();
            $this->setRedirect($redirect, Text::_('COM_ALFA_DOWNLOADS_PROTECTION_CHECKED'));
        } catch (Throwable $e) {
            // The reason's own words go to the log, never to the page.
            Log::add(entry: 'Download store protection: Check again failed: ' . $e->getMessage(), priority: Log::ERROR, category: 'com_alfa.downloads');
            $this->setRedirect($redirect, Text::_('COM_ALFA_DOWNLOADS_PROTECTION_FAILED'), 'error');
        }
    }

    /**
     * Build and download the extension package archive (repo layout, libraries
     * excluded; a source/PR package rather than a guaranteed-installable one).
     *
     * With `&changed=1` the archive is restricted to the files the integrity scan
     * reports as changed against the install/update baseline, but only when that
     * scan is verified and actually reports changes; otherwise nothing is streamed
     * and the request redirects back to the Tools view with a notice, as it does when
     * none of the changed files is part of the package. A failure is logged and
     * answered with one fixed sentence ({@see self::exportFailed()}).
     *
     *
     * @since  1.0.0
     */
    public function exportPackage(): void
    {
        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Token accepted in GET or POST (the Tools card links via GET).
        if (!Session::checkToken('request')) {
            $this->setMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=tools', false));

            return;
        }

        $changed = (int) $this->input->getInt('changed', 0);
        $onlyRelPaths = null;

        if ($changed === 1) {
            // The changed set comes from the signed official CDN checksums
            // (single source of truth): files that differ from the released
            // version = modified + injected. ('missing' can't be zipped: a
            // file the release has and this install does not is a deletion to
            // make in the repo, not an archive entry.)
            try {
                $report = IntegrityHelper::verifyAgainstOfficial();
            } catch (Throwable $e) {
                $this->exportFailed(error: $e);

                return;
            }

            $status = $report['status'] ?? '';
            $diff = array_merge($report['modified'] ?? [], $report['injected'] ?? []);

            // 'modified' = exact-version deviation, 'ahead' = customised/off-catalog
            // - both have real diffs to export. Anything else (official/unreachable/
            // bad_signature) has nothing trustworthy to export.
            if (($status !== 'modified' && $status !== 'ahead') || empty($diff)) {
                if ($status === 'official') {
                    // Verified: this install matches the official release exactly.
                    $this->setMessage(Text::_('COM_ALFA_TOOLS_CHANGED_NONE'), 'notice');
                } else {
                    // unreachable / bad_signature: can't trust a diff; go online.
                    $this->setMessage(Text::_('COM_ALFA_TOOLS_CHANGED_UNVERIFIED'), 'warning');
                }

                $this->setRedirect(Route::_('index.php?option=com_alfa&view=tools', false));

                return;
            }

            $onlyRelPaths = $diff;
        }

        $this->deliverPackage(installRoot: JPATH_ROOT, onlyRelPaths: $onlyRelPaths);
    }

    /**
     * Build the archive and stream it, or say why there is none. A changed-only export
     * whose files all lie outside the package (an added file outside the declared
     * folders, say) has nothing to carry; the Security tab lists those files.
     *
     * @param string $installRoot Absolute path to the Joomla installation root to export.
     * @param string[]|null $onlyRelPaths The changed files to carry, or null for the whole package.
     *
     * @since  2.1.1
     */
    private function deliverPackage(string $installRoot, ?array $onlyRelPaths): void
    {
        try {
            $package = PackageHelper::buildPackageZip(installRoot: $installRoot, onlyRelPaths: $onlyRelPaths);
        } catch (Throwable $e) {
            $this->exportFailed(error: $e);

            return;
        }

        if ($package === null) {
            $this->setMessage(Text::_('COM_ALFA_TOOLS_CHANGED_NOT_IN_PACKAGE'), 'notice');
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=tools', false));

            return;
        }

        $this->streamArchive(path: $package['zip'], filename: $package['filename']);
    }

    /**
     * Answer a failed export: the exception goes to the tools log, never to the page,
     * which shows one fixed sentence saying where the reason is.
     *
     * @param Throwable $error What went wrong.
     *
     * @since  2.1.1
     */
    private function exportFailed(Throwable $error): void
    {
        Log::add(entry: 'Package export failed: ' . $error::class . ': ' . $error->getMessage(), priority: Log::ERROR, category: 'com_alfa.tools');
        $this->setMessage(Text::_('COM_ALFA_TOOLS_PACKAGE_ERROR'), 'error');
        $this->setRedirect(Route::_('index.php?option=com_alfa&view=tools', false));
    }

    /**
     * Stream a finished archive to the browser as a download, then delete it and
     * end the request. Output buffers are flushed first so the binary payload is
     * not corrupted by stray output.
     *
     * @param string $path Absolute path to the archive on disk.
     * @param string $filename Download filename presented to the browser.
     *
     *
     * @since  1.0.0
     */
    private function streamArchive(string $path, string $filename): void
    {
        $this->app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $this->app->setHeader('Pragma', 'no-cache', true);
        $this->app->setHeader('Content-Type', 'application/zip', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true);
        $this->app->setHeader('Content-Length', (string) filesize($path), true);
        $this->app->sendHeaders();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($path);

        @unlink($path);

        $this->app->close();
    }
}
