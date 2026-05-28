<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\PackageHelper;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/**
 * Developer tools controller — actions for the Tools view that don't belong to a
 * more specific tool controller (sync, media). Currently exposes the package
 * export; further developer utilities can be added here as new tasks.
 *
 * The `export` task re-assembles the live com_alfa install back into a repo-layout
 * archive (via {@see PackageHelper}), streams it to the browser as an attachment,
 * then deletes the temporary file — so nothing is left on disk. Gated by the
 * `alfa.tools` permission and a CSRF token, matching the other Tools actions.
 *
 * @since  1.0.3
 */
class ToolsController extends BaseController
{
    /**
     * Build and download the extension package archive (repo layout, libraries
     * excluded — a source/PR package rather than a guaranteed-installable one).
     *
     * @return  void
     *
     * @since   1.0.3
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

        try {
            $package = PackageHelper::buildPackageZip(installRoot: JPATH_ROOT);
        } catch (\Throwable $e) {
            $this->setMessage(Text::sprintf('COM_ALFA_TOOLS_PACKAGE_ERROR', $e->getMessage()), 'error');
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=tools', false));

            return;
        }

        $this->streamArchive(path: $package['zip'], filename: $package['filename']);
    }

    /**
     * Stream a finished archive to the browser as a download, then delete it and
     * end the request. Output buffers are flushed first so the binary payload is
     * not corrupted by stray output.
     *
     * @param   string  $path      Absolute path to the archive on disk.
     * @param   string  $filename  Download filename presented to the browser.
     *
     * @return  void
     *
     * @since   1.0.3
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
