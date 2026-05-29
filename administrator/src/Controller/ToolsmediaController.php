<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * Controller for the Tools → Media maintenance view.
 *
 * @since  1.0.1
 */
class ToolsmediaController extends BaseController
{
    /**
     * Delete the selected media rows (and their files) — orphan / missing-file
     * cleanup. Gated by the alfa.tools permission.
     *
     *
     * @since   1.0.1
     */
    public function delete(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // 'files' mode selects by relative path; 'rows' mode by media row id.
        $source = (string) ($this->input->get('filter', [], 'array')['source'] ?? 'rows');
        $selection = (array) $this->input->get('cid', [], 'raw');

        try {
            if (empty($selection)) {
                $this->setMessage(Text::_('JERROR_NO_ITEMS_SELECTED'), 'warning');
            } elseif ($source === 'files') {
                $deleted = MediaHelper::deleteUntrackedFiles(array_map('strval', $selection));
                $this->setMessage(Text::sprintf('COM_ALFA_TOOLSMEDIA_N_FILES_DELETED', $deleted));
            } else {
                $ids = array_values(array_filter(
                    array_map('intval', $selection),
                    static fn (int $id): bool => $id > 0,
                ));

                $deleted = MediaHelper::deleteMediaByIds($ids);
                $this->setMessage(Text::sprintf('COM_ALFA_TOOLSMEDIA_N_DELETED', $deleted));
            }
        } catch (Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=toolsmedia', false));
    }

    /**
     * Delete every orphan media row (and its files) across all pages. Gated by
     * the alfa.tools permission.
     *
     *
     * @since   1.0.1
     */
    public function deleteAllOrphans(): void
    {
        $this->deleteAll([MediaHelper::class, 'findOrphanMediaIds']);
    }

    /**
     * Delete every media row whose file is missing on disk, across all pages.
     * Gated by the alfa.tools permission.
     *
     *
     * @since   1.0.1
     */
    public function deleteAllMissing(): void
    {
        $this->deleteAll([MediaHelper::class, 'findMissingFileMediaIds']);
    }

    /**
     * Shared driver for the "delete all" maintenance actions: resolve the full
     * id set via the given finder, then delete it (the helper slices internally).
     *
     * @param callable $finder Returns the int[] of media ids to delete.
     *
     *
     * @since   1.0.1
     */
    private function deleteAll(callable $finder): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            $ids = (array) $finder();
            $deleted = MediaHelper::deleteMediaByIds($ids);
            $this->setMessage(Text::sprintf('COM_ALFA_TOOLSMEDIA_N_DELETED', $deleted));
        } catch (Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=toolsmedia', false));
    }
}
