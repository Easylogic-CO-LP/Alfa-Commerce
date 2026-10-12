<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Download\Storage;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;

/**
 * AJAX CRUD for an item's downloadable files (`#__alfa_item_downloads`),
 * driven by the Downloads tab of the item editor.
 *
 * Tasks (all POST, all JSON) - file BYTES only; row metadata saves through
 * ItemModel::save() with the rest of the item form:
 *   itemdownloads.upload  - new file row (multipart: file + metadata)
 *   itemdownloads.replace - swap a row's object with a new upload; the old
 *                           object is handed to deleteIfOrphaned(), so it
 *                           survives while any entitlement snapshot needs it
 *   itemdownloads.delete  - remove the row, then GC the orphaned object
 *
 * Storage objects are NEVER touched directly; every byte goes through
 * Storage (GC invariant lives there).
 *
 * @since  1.3.0
 */
class ItemdownloadsController extends BaseController
{
    /**
     * Upload a new downloadable file for an item.
     *
     * @return void Emits a JsonResponse with the created row.
     *
     * @since  1.3.0
     */
    public function upload(): void
    {
        if (!$this->authorised()) {
            return;
        }

        $input = $this->app->getInput();
        $itemId = $input->post->getInt('item_id', 0);
        $file = $input->files->get('file', null, 'raw');

        if ($itemId <= 0 || !\is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo new JsonResponse(null, Text::_('COM_ALFA_DOWNLOADS_ERROR_UPLOAD'), true);
            $this->app->close();
        }

        try {
            $stored = Storage::put(sourcePath: $file['tmp_name']);

            $originalName = basename((string) $file['name']);
            $title = trim($input->post->getString('title', ''));

            $db = Factory::getContainer()->get('DatabaseDriver');
            $combinationId = $input->post->getInt('combination_id', 0);

            $row = (object) [
                'item_id' => $itemId,
                'combination_id' => $combinationId > 0 ? $combinationId : null,
                'title' => $title !== '' ? $title : $originalName,
                'storage_disk' => $stored->storage_disk,
                'storage_key' => $stored->storage_key,
                'mime_type' => $stored->mime_type,
                'file_size' => $stored->file_size,
                'sha256' => $stored->sha256,
                'original_filename' => $originalName,
                'display_filename' => $originalName,
                'max_downloads' => max(0, $input->post->getInt('max_downloads', 0)),
                'days_valid' => max(0, $input->post->getInt('days_valid', 0)),
                'state' => 1,
                'ordering' => $this->nextOrdering(itemId: $itemId),
                'created_by' => (int) $this->app->getIdentity()->id,
                'modified_by' => (int) $this->app->getIdentity()->id,
            ];

            $db->insertObject('#__alfa_item_downloads', $row, 'id');

            echo new JsonResponse($row);
        } catch (Exception $e) {
            echo new JsonResponse(null, $e->getMessage(), true);
        }

        $this->app->close();
    }

    /**
     * Replace a row's stored object with a freshly uploaded file.
     *
     * Existing entitlements keep working and (per the live-file policy)
     * start serving the NEW file. The old object is GC'd only when no
     * entitlement snapshot references it any more.
     *
     * @return void Emits a JsonResponse with the updated row.
     *
     * @since  1.3.0
     */
    public function replace(): void
    {
        if (!$this->authorised()) {
            return;
        }

        $input = $this->app->getInput();
        $id = $input->post->getInt('id', 0);
        $file = $input->files->get('file', null, 'raw');

        $row = $this->loadRow(id: $id);

        if ($row === null || !\is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo new JsonResponse(null, Text::_('COM_ALFA_DOWNLOADS_ERROR_UPLOAD'), true);
            $this->app->close();
        }

        try {
            $stored = Storage::put(sourcePath: $file['tmp_name']);
            $oldDisk = (string) $row->storage_disk;
            $oldKey = (string) $row->storage_key;

            $originalName = basename((string) $file['name']);

            $update = (object) [
                'id' => (int) $row->id,
                'storage_disk' => $stored->storage_disk,
                'storage_key' => $stored->storage_key,
                'mime_type' => $stored->mime_type,
                'file_size' => $stored->file_size,
                'sha256' => $stored->sha256,
                'original_filename' => $originalName,
                'display_filename' => $originalName,
                'modified_by' => (int) $this->app->getIdentity()->id,
            ];

            Factory::getContainer()->get('DatabaseDriver')
                ->updateObject('#__alfa_item_downloads', $update, 'id');

            // GC invariant: kept automatically while any snapshot needs it.
            Storage::deleteIfOrphaned(disk: $oldDisk, key: $oldKey);

            echo new JsonResponse($update);
        } catch (Exception $e) {
            echo new JsonResponse(null, $e->getMessage(), true);
        }

        $this->app->close();
    }

    /**
     * Delete a file row, then GC its object if nothing references it.
     *
     * Sold entitlements are NOT touched: their snapshot keeps the old
     * storage_key referenced, so the object survives the row.
     *
     * @return void Emits a JsonResponse.
     *
     * @since  1.3.0
     */
    public function delete(): void
    {
        if (!$this->authorised()) {
            return;
        }

        $input = $this->app->getInput();
        $row = $this->loadRow(id: $input->post->getInt('id', 0));

        if ($row === null) {
            echo new JsonResponse(null, Text::_('COM_ALFA_DOWNLOADS_ERROR_NOT_FOUND'), true);
            $this->app->close();
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $rowId = (int) $row->id;

            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_item_downloads'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $rowId);

            $db->setQuery(query: $query);
            $db->execute();

            Storage::deleteIfOrphaned(disk: (string) $row->storage_disk, key: (string) $row->storage_key);

            echo new JsonResponse(['id' => $rowId]);
        } catch (Exception $e) {
            echo new JsonResponse(null, $e->getMessage(), true);
        }

        $this->app->close();
    }

    /**
     * CSRF + permission gate shared by every task. Emits the error response itself.
     *
     * The files belong to the item being edited, so uploading, replacing and removing one
     * are all edits of that item (alfa.items.edit), never a delete of the item itself.
     *
     * @return bool True when the request may proceed.
     *
     * @since  1.3.0
     */
    protected function authorised(): bool
    {
        if (!Session::checkToken()) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN_NOTICE'), true);
            $this->app->close();
        }

        if (!AlfaHelper::can(section: 'items', action: 'edit', user: $this->app->getIdentity())) {
            echo new JsonResponse(null, Text::_('JERROR_ALERTNOAUTHOR'), true);
            $this->app->close();
        }

        return true;
    }

    /**
     * Load a `#__alfa_item_downloads` row by PK.
     *
     * @param int $id Row PK.
     *
     * @since  1.3.0
     */
    protected function loadRow(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_item_downloads'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id);

        $db->setQuery(query: $query);

        return $db->loadObject() ?: null;
    }

    /**
     * Next ordering value for an item's file list.
     *
     * @param int $itemId Item PK.
     *
     * @since  1.3.0
     */
    protected function nextOrdering(int $itemId): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('COALESCE(MAX(' . $db->quoteName('ordering') . '), 0) + 1')
            ->from($db->quoteName('#__alfa_item_downloads'))
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->bind(':itemId', $itemId);

        $db->setQuery(query: $query);

        return (int) $db->loadResult();
    }
}
