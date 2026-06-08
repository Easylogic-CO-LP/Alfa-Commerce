<?php

/**
 * @version    CVS: 1.0.0
 * @package    Com_Test
 * @author     Agamemnon <info@easylogic.gr>
 * @copyright  2024 Agamemnon
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Media\AfterProcessEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\BeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\BeforeProcessEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\ThumbnailEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\ValidateEvent;
use Exception;
use FilesystemIterator;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\String\StringHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class MediaHelper
{
    /**
     * Standard image MIME types accepted out of the box.
     *
     * Single source of truth for the fallback used when the component's
     * "Allowed Image Types" (media_mime) setting is left empty — an empty
     * setting means "allow all standard image types", not "allow none". Shared
     * by the upload gate (MediaZoneField / grid layout → client validation) and
     * server-side handling so both ends agree.
     *
     * @var    string[]
     * @since  1.0.8
     */
    public const DEFAULT_IMAGE_MIMES = [
        'image/jpeg',
        'image/gif',
        'image/png',
        'image/bmp',
        'image/webp',
        'image/avif',
    ];

    /**
     * Resolve the effective allowed image MIME types.
     *
     * Returns the configured types, or — when none are configured — the full
     * {@see self::DEFAULT_IMAGE_MIMES} set, so uploads work without requiring the
     * admin to pre-select every type.
     *
     * @param   mixed  $configured  The raw media_mime config value (array|string|null).
     *
     * @return  string[]  The effective allowed MIME types.
     *
     * @since   1.0.8
     */
    public static function resolveAllowedMimes($configured): array
    {
        $mimes = array_values(array_filter((array) $configured));

        return $mimes ?: self::DEFAULT_IMAGE_MIMES;
    }

    /**
     * Maximum upload size the server will actually accept, in bytes.
     *
     * The effective ceiling is the smaller of PHP's upload_max_filesize and
     * post_max_size (a single uploaded file must fit inside the whole POST body).
     * A directive of 0 means "unlimited" and is ignored; if both are unlimited
     * this returns 0. Used to keep the client-side drop gate aligned with what the
     * server can really accept, instead of an arbitrary hard-coded cap.
     *
     * @return  int  Effective max upload size in bytes (0 = unlimited).
     *
     * @since   1.0.8
     */
    public static function maxUploadBytes(): int
    {
        $toBytes = static function ($value): int {
            $value = trim((string) $value);

            if ($value === '') {
                return 0;
            }

            $number = (int) $value;
            $unit   = strtolower($value[\strlen($value) - 1]);

            return match ($unit) {
                'g'     => $number * 1024 * 1024 * 1024,
                'm'     => $number * 1024 * 1024,
                'k'     => $number * 1024,
                default => (int) $value,
            };
        };

        $limits = array_filter([
            $toBytes(\ini_get('upload_max_filesize')),
            $toBytes(\ini_get('post_max_size')),
        ]);

        return $limits ? min($limits) : 0;
    }

    /**
     * Resolve a stored (relative) media path to a browser URL for display.
     *
     * Storage stays relative + portable (the `path` column, which the admin save
     * round-trips and the server uses as JPATH_ROOT . '/' . path for file ops);
     * this builds the absolute URL by prefixing the live site base (Uri::root(),
     * which honours root / subfolder / subdomain installs). Values that are
     * already absolute — external `type=url` media (http/https), protocol-relative
     * URLs, or data URIs — are returned untouched.
     *
     * @param string|null $path Relative media path (e.g. "images/commerce/x.webp").
     *
     * @return string Absolute URL, or '' for an empty path.
     *
     * @since   1.0.1
     */
    public static function toUrl(?string $path): string
    {
        $path = trim((string) $path);

        // Empty, or already an absolute / protocol-relative / data URL → leave as-is.
        if ($path === ''
            || str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_starts_with($path, '//')
            || str_starts_with($path, 'data:')) {
            return $path;
        }

        return rtrim(Uri::root(), '/') . '/' . ltrim($path, '/');
    }

    /**
     * Delete all media belonging to the given entities (rows always; files only
     * when the `media_full_deletion` setting is on, mirroring the per-image
     * delete in saveMedia). Call from an entity's delete() so removing a
     * manufacturer / item / category doesn't leave orphaned media rows + files.
     *
     * @param int[] $itemIds Entity ids whose media should be removed.
     * @param string $origin Media origin ('item' | 'category' | 'manufacturer').
     *
     *
     * @since   1.0.1
     */
    public static function deleteMediaForItems(array $itemIds, string $origin): void
    {
        $itemIds = array_values(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        ));

        if (empty($itemIds) || $origin === '') {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // The single "Complete Media Deletion" setting (media_full_deletion) governs
        // whether removing media also deletes the file from disk — both here (whole
        // entity deleted) and in saveMedia (a single image removed). On by default.
        if ((bool) ComponentHelper::getParams('com_alfa')->get('media_full_deletion', 1)) {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['path', 'thumbnail']))
                    ->from($db->quoteName('#__alfa_media'))
                    ->where($db->quoteName('origin') . ' = ' . $db->quote($origin))
                    ->whereIn($db->quoteName('item_id'), $itemIds),
            )->loadObjectList();

            foreach ($rows as $row) {
                foreach ([$row->path, $row->thumbnail] as $file) {
                    $file = ltrim((string) $file, '/');

                    // Skip empties and external URLs (type=url media stores an absolute URL).
                    if ($file === ''
                        || str_starts_with($file, 'http://')
                        || str_starts_with($file, 'https://')
                        || str_starts_with($file, '//')) {
                        continue;
                    }

                    $absolute = JPATH_ROOT . '/' . $file;

                    if (is_file($absolute)) {
                        try {
                            File::delete($absolute);
                        } catch (Throwable $e) {
                            // Best-effort: a missing/locked file must not block the delete.
                        }
                    }
                }
            }
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_media'))
                ->where($db->quoteName('origin') . ' = ' . $db->quote($origin))
                ->whereIn($db->quoteName('item_id'), $itemIds),
        )->execute();
    }

    /**
     * Rows processed per slice by {@see self::deleteMediaByIds()}. Keeps the
     * IN(...) clause and per-batch file work bounded so a single request can
     * safely clear a very large selection ("show all") or the whole table.
     *
     * @since  1.0.1
     */
    private const DELETE_BATCH_SIZE = 500;

    /**
     * Delete media by their own row ids — rows plus any existing local files.
     * Used by the Tools → Media maintenance view to clean up selected orphan /
     * missing-file rows. Always removes the file when present (explicit cleanup).
     *
     * The work is processed in slices of {@see self::DELETE_BATCH_SIZE} so an
     * arbitrarily large id set (e.g. "show all" + select-all, or delete-all)
     * never produces an oversized IN(...) clause.
     *
     * @param int[] $mediaIds #__alfa_media row ids to delete.
     *
     * @return int Number of rows removed.
     *
     * @since   1.0.1
     */
    public static function deleteMediaByIds(array $mediaIds): int
    {
        $mediaIds = array_values(array_unique(array_filter(
            array_map('intval', $mediaIds),
            static fn (int $id): bool => $id > 0,
        )));

        if (empty($mediaIds)) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $deleted = 0;

        foreach (array_chunk($mediaIds, self::DELETE_BATCH_SIZE) as $batch) {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['path', 'thumbnail']))
                    ->from($db->quoteName('#__alfa_media'))
                    ->whereIn($db->quoteName('id'), $batch),
            )->loadObjectList();

            // Let a plugin clean up derivatives before the rows/files go away.
            self::dispatchBeforeDelete($rows);

            foreach ($rows as $row) {
                foreach ([$row->path, $row->thumbnail] as $file) {
                    $file = ltrim((string) $file, '/');

                    if ($file === ''
                        || str_starts_with($file, 'http://')
                        || str_starts_with($file, 'https://')
                        || str_starts_with($file, '//')) {
                        continue;
                    }

                    $absolute = JPATH_ROOT . '/' . $file;

                    if (is_file($absolute)) {
                        try {
                            File::delete($absolute);
                        } catch (Throwable $e) {
                            // Best-effort — a missing/locked file must not block the delete.
                        }
                    }
                }
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_media'))
                    ->whereIn($db->quoteName('id'), $batch),
            )->execute();

            $deleted += count($batch);
        }

        return $deleted;
    }

    /**
     * Return the ids of every media row whose (origin, item_id) no longer points
     * at a live parent entity (item / category / manufacturer). Detected purely
     * in SQL via LEFT JOINs. Used by the Tools → Media "delete all orphans" action.
     *
     * @return int[]
     *
     * @since   1.0.1
     */
    public static function findOrphanMediaIds(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('a.id'))
            ->from($db->quoteName('#__alfa_media', 'a'))
            ->join('LEFT', $db->quoteName('#__alfa_items', 'i')
                . ' ON a.origin = ' . $db->quote('item') . ' AND i.id = a.item_id')
            ->join('LEFT', $db->quoteName('#__alfa_categories', 'c')
                . ' ON a.origin = ' . $db->quote('category') . ' AND c.id = a.item_id')
            ->join('LEFT', $db->quoteName('#__alfa_manufacturers', 'm')
                . ' ON a.origin = ' . $db->quote('manufacturer') . ' AND m.id = a.item_id')
            ->where('(CASE a.origin'
                . ' WHEN ' . $db->quote('item') . ' THEN (i.id IS NOT NULL)'
                . ' WHEN ' . $db->quote('category') . ' THEN (c.id IS NOT NULL)'
                . ' WHEN ' . $db->quote('manufacturer') . ' THEN (m.id IS NOT NULL)'
                . ' ELSE 1 END) = 0');

        return array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
    }

    /**
     * Return the ids of every local-file media row whose file is absent on disk.
     * Disk presence is not expressible in SQL, so each local row is stat()-ed
     * here. External (type=url) and path-less rows are skipped. Used by both the
     * Tools → Media "file missing" filter and the "delete all missing" action.
     *
     * @return int[]
     *
     * @since   1.0.1
     */
    public static function findMissingFileMediaIds(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'path']))
                ->from($db->quoteName('#__alfa_media'))
                ->where($db->quoteName('type') . ' != ' . $db->quote('url'))
                ->where($db->quoteName('path') . ' != ' . $db->quote('')),
        )->loadObjectList();

        $missing = [];

        foreach ($rows as $row) {
            if (!is_file(JPATH_ROOT . '/' . ltrim((string) $row->path, '/'))) {
                $missing[] = (int) $row->id;
            }
        }

        return $missing;
    }

    /**
     * Absolute path of the com_alfa upload root (media_save_location).
     *
     *
     * @since   1.0.1
     */
    public static function getUploadRoot(): string
    {
        $saveFolder = ltrim(
            (string) ComponentHelper::getParams('com_alfa')->get('media_save_location', 'images/commerce'),
            '/',
        );

        return rtrim(JPATH_ROOT . '/' . $saveFolder, '/');
    }

    /**
     * Every media path tracked in #__alfa_media — both `path` and `thumbnail`,
     * keyed by relative path for O(1) lookup. Including the thumbnail column
     * ensures generated thumbnails are never treated as untracked.
     *
     * @return array<string, true>
     *
     * @since   1.0.1
     */
    private static function getTrackedRelativePaths(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['path', 'thumbnail']))
                ->from($db->quoteName('#__alfa_media')),
        )->loadObjectList();

        $tracked = [];

        foreach ($rows as $row) {
            foreach ([$row->path, $row->thumbnail] as $value) {
                $value = str_replace('\\', '/', ltrim((string) $value, '/'));

                if ($value !== '') {
                    $tracked[$value] = true;
                }
            }
        }

        return $tracked;
    }

    /**
     * Walk the upload root and return every file that has NO row in
     * #__alfa_media (neither as a path nor a thumbnail) — leftovers from aborted
     * uploads, manual copies, or records deleted without their files.
     *
     * @return object[] Each: { path (relative), size (int bytes), mtime (int) }.
     *
     * @since   1.0.1
     */
    public static function findUntrackedFiles(): array
    {
        $root = self::getUploadRoot();

        if (!is_dir($root)) {
            return [];
        }

        $tracked = self::getTrackedRelativePaths();
        $rootLen = strlen(rtrim(JPATH_ROOT, '/') . '/');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), $rootLen));

            if ($relative === '' || isset($tracked[$relative])) {
                continue;
            }

            $files[] = (object) [
                'path' => $relative,
                'size' => (int) $fileInfo->getSize(),
                'mtime' => (int) $fileInfo->getMTime(),
            ];
        }

        return $files;
    }

    /**
     * Delete the given untracked files from disk. Each path is validated to be
     * inside the upload root (blocks traversal) and confirmed still untracked
     * before removal — a file referenced by any media row is never deleted.
     *
     * @param string[] $relativePaths Relative paths (as listed by findUntrackedFiles()).
     *
     * @return int Number of files deleted.
     *
     * @since   1.0.1
     */
    public static function deleteUntrackedFiles(array $relativePaths): int
    {
        $realRoot = realpath(self::getUploadRoot());

        if ($realRoot === false) {
            return 0;
        }

        $tracked = self::getTrackedRelativePaths();
        $deleted = 0;

        foreach ($relativePaths as $relative) {
            $relative = str_replace('\\', '/', ltrim((string) $relative, '/'));

            // Never touch a path that a media row points at.
            if ($relative === '' || isset($tracked[$relative])) {
                continue;
            }

            $absolute = realpath(JPATH_ROOT . '/' . $relative);

            // Must resolve to a real file inside the upload root.
            if ($absolute === false
                || !is_file($absolute)
                || !str_starts_with($absolute, $realRoot . DIRECTORY_SEPARATOR)) {
                continue;
            }

            try {
                File::delete($absolute);
                $deleted++;
            } catch (Throwable $e) {
                // Best-effort — a locked/removed file must not abort the batch.
            }
        }

        return $deleted;
    }

    /**
     * Saves and processes media items (uploads or existing selections).
     *
     * Iterates through the provided media array, handles file moving/copying,
     * resizing, and database insertion/updates.
     *
     * @param array $mediaData The array of media items from the request.
     * @param array $droppedMedia The array for drag-and-dropped files.
     * @param int $itemId The ID of the parent item these media belong to.
     * @param string $mediaOrigin Identifier for the media origin based on model name (e.g., 'item', 'category').
     * @param string $customFileName Reserved (alias-based naming is no longer a component setting).
     *
     * @throws Exception
     */
    public static function saveMedia(array $mediaData, array $droppedMedia, int $itemId, string $mediaOrigin, string $customFileName = ''): true
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // configuration
        $params = ComponentHelper::getParams('com_alfa');

        $saveFolder = ltrim($params->get('media_save_location', 'images/commerce'), '/');
        $absolutePath = JPATH_ROOT . '/' . $saveFolder;

        if (!Folder::exists($absolutePath)) {
            Folder::create($absolutePath);
        }

        $urlDefaultThumbnail = trim($params->get('media_url_thumbnail', ''));
        $allowedMimes = self::resolveAllowedMimes($params->get('media_mime'));

        // Main-image format/quality/dimensions are no longer a component concern —
        // those settings now live per-context in the optimizer plugin. The
        // destination filename keeps the source extension unless a plugin rewrites
        // it (via the event's finalPath).
        $outputFormat = '';

        $dropIndex = 0;
        $ordering = 0;

        foreach ($mediaData as $id => $media) {
            // Check if media is new
            $isNew = str_contains($id, 'new-');

            // Determine type of newly added media (drag & dropped OR selected by Joomla's media picker)
            $isDrop = str_starts_with($media['source'], 'blob:');
            $isPicker = str_starts_with($media['source'], 'picker:');
            $isUrl = $media['type'] == 'url';

            // Process thumbnail upfront for all non-blob sources.
            // Blob thumbnails are handled later, after the main image is saved to disk.
            if (!str_contains($media['thumbnail'], 'blob:')) {
                $finalThumbnail = self::processThumbnail(
                    rawThumbnail:       $media['thumbnail'],
                    defaultThumbPath:   $urlDefaultThumbnail,
                    absolutePath:       $absolutePath,
                    saveFolder:         $saveFolder,
                    params:             $params,
                );
            }

            // -------------------------------------------------------
            // NEW MEDIA
            // -------------------------------------------------------
            if ($isNew) {
                $src = '';
                $nameForFile = '';

                // FIND THE UNIQUE NAME FOR THE FILE
                if ($isDrop) { // Dropped Media Handler
                    //dropped media and mediaData has the same ordering sequence
                    if (isset($droppedMedia[$dropIndex])) {
                        $file = $droppedMedia[$dropIndex];

                        // If "Don't convert" is enabled from config, keep the original file extension
                        if (empty($outputFormat)) {
                            $outputFormat = explode('/', $droppedMedia[$dropIndex]['type'])[1];
                        }

                        $noError = $file['error'] === 0;

                        $dropIndex++; //keep track of the ordering sequence
                        if ($noError) {
                            $src = $file['tmp_name'];

                            // Prevent errors and overwrites by setting a unique file name if it already exists in the directory
                            $nameForFile = self::getUniqueFilename(
                                name:           $file['name'],
                                basePath:       $absolutePath,
                                outputFormat:   $outputFormat,
                            );
                        }
                    }
                } elseif ($isPicker) { // Joomla Media Picker Handler
                    $cleanPath = HTMLHelper::cleanImageURL($media['source']);
                    $cleanPath = str_replace('picker://', '', $cleanPath->url);

                    $src = JPATH_ROOT . '/' . $cleanPath;

                    // Keep the original file extension; a plugin may convert it.
                    if (empty($outputFormat)) {
                        $outputFormat = pathinfo($cleanPath)['extension'];
                    }

                    $nameForFile = self::getUniqueFilename(
                        name:           $cleanPath,
                        basePath:       $absolutePath,
                        outputFormat:   $outputFormat,
                    );
                }

                // PREPARE OBJECT FOR INSERT ON MEDIA TABLE
                $insertObject = null;
                if ($isUrl) { // URL HANDLER
                    $insertObject = (object) [
                        'item_id' => $itemId,
                        'path' => $media['source'],
                        'thumbnail' => $finalThumbnail,
                        'alt' => $media['alt'],
                        'ordering' => $ordering,
                        'type' => $media['type'],
                        'origin' => $mediaOrigin,
                    ];
                } else {
                    // Check media source existence
                    $srcExists = !empty($src) && file_exists($src);

                    // If source exists, process media for uploading to database
                    if ($srcExists) {
                        $dest = $absolutePath . '/' . $nameForFile;

                        // Validation gate: a plugin may veto a file before processing.
                        $valid = self::dispatchValidate(
                            source:       $src,
                            origin:       $mediaOrigin,
                            field:        'image',
                            allowedMimes: $allowedMimes,
                        );

                        if ($valid === false) {
                            // Skip this file; the error was already enqueued.
                            $ordering++;
                            continue;
                        }

                        // Hand processing to the plugin layer. The main-image event
                        // carries NO format/quality/dimensions — the plugin reads its
                        // own per-context settings. If no plugin handles the image it
                        // is stored AS-IS with an empty dominant colour.
                        $result = self::dispatchProcess(
                            eventName:    'onAlfaMediaBeforeProcess',
                            source:       $src,
                            dest:         $dest,
                            origin:       $mediaOrigin,
                            field:        'image',
                            allowedMimes: $allowedMimes,
                        );

                        // Use the final path the plugin wrote (falls back to the
                        // proposed dest when nothing was processed/rewritten).
                        $dest        = $result['dest'];
                        $relativeDest = ltrim(str_replace(JPATH_ROOT . '/', '', $dest), '/');
                        $storeOk      = true;

                        if (!$result['processed']) {
                            // No plugin processed the image → store the original unchanged.
                            try {
                                File::copy($src, $dest);
                            } catch (Throwable $e) {
                                $storeOk = false;
                            }
                        }

                        // CORE concern: the dominant colour is the image-load placeholder
                        // and must always be stored, plugin or not. Compute it from the
                        // FINAL file now that $dest is settled (optimised or copied as-is).
                        $color = $storeOk ? self::dominantColor($dest) : '';

                        // Blob URLs are browser-only and unreachable server-side, so on first save
                        // we generate the thumbnail from the main image. On subsequent saves the
                        // thumbnail is already a real path, so the processThumbnail() call at the
                        // top of the loop handles it instead and this block is skipped.
                        if ($storeOk && (str_contains($media['thumbnail'], 'blob:')) && $isDrop) {
                            $finalThumbnail = self::processThumbnail(
                                rawThumbnail:       $relativeDest,
                                defaultThumbPath:   $urlDefaultThumbnail,
                                absolutePath:       $absolutePath,
                                saveFolder:         $saveFolder,
                                params:             $params,
                            );
                        }

                        if ($storeOk) {
                            $insertObject = (object) [
                                'item_id' => $itemId,
                                'path' => $relativeDest,
                                'thumbnail' => $finalThumbnail,
                                'color' => $color,
                                'alt' => $media['alt'],
                                'ordering' => $ordering,
                                'type' => $media['type'],
                                'origin' => $mediaOrigin,
                            ];

                            // Notify the plugin layer after a successful processing/insert prep.
                            self::dispatchAfterProcess(
                                source:    $src,
                                dest:      $dest,
                                origin:    $mediaOrigin,
                                field:     'image',
                                color:     $color,
                                processed: $result['processed'],
                            );
                        }
                    }
                }

                if (!empty($insertObject)) {
                    $db->insertObject('#__alfa_media', $insertObject);
                }
            }

            // -------------------------------------------------------
            // EXISTING MEDIA
            // -------------------------------------------------------
            else {
                $toDelete = isset($media['delete']) && $media['delete'] == 1;
                $isFullDelete = (bool) $params->get('media_full_deletion', 1);

                if ($toDelete) {
                    // If full delete is enabled, remove files from the server first
                    if ($isFullDelete) {
                        // Fetch the main image path associated with this thumbnail before deleting
                        $query = $db->getQuery(true)
                            ->select('path, thumbnail')
                            ->from($db->quoteName('#__alfa_media'))
                            ->where($db->quoteName('id') . ' = ' . $db->quote($id));

                        $db->setQuery($query);
                        $result = $db->loadObject();

                        // Remove both thumbnail and main image from server
                        $resultArray = [$result->path, $result->thumbnail];

                        foreach ($resultArray as $path) {
                            if ($path && file_exists(JPATH_ROOT . '/' . $path)) {
                                File::delete(JPATH_ROOT . '/' . $path);
                            }
                        }
                    }

                    // Remove media record from database
                    $query = $db->getQuery(true)
                        ->delete($db->quoteName('#__alfa_media'))
                        ->where($db->quoteName('id') . ' = ' . (int) $id);

                    try {
                        $db->setQuery($query)->execute();
                    } catch (Exception $e) {
                        Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
                        return true;
                    }
                } else {
                    // Update ordering, alt text, and thumbnail for existing records
                    $obj = (object) [
                        'id' => (int) $id,
                        'path' => ltrim($media['source'], '/'),
                        'alt' => $media['alt'],
                        'ordering' => $ordering,
                        'thumbnail' => $finalThumbnail,
                    ];

                    $db->updateObject('#__alfa_media', $obj, 'id');
                }
            }
            $ordering++;
        }
        return true;
    }

    /**
     * Processes and saves a thumbnail image.
     *
     * Returns an empty string if the thumbnail is missing or matches the default placeholder,
     * returns the existing path if it's already within the save folder,
     * otherwise processes the source image and saves a new thumbnail.
     *
     * @param string $rawThumbnail Raw (relative) thumbnail path.
     * @param string $defaultThumbPath Path of the default placeholder thumbnail to compare against.
     * @param string $absolutePath Absolute filesystem path to the media save directory.
     * @param string $saveFolder Relative path of the media save folder (e.g., 'images/commerce').
     * @param object $params Component parameters object.
     *
     * @return string Relative path of the saved thumbnail, or empty string if none.
     */
    private static function processThumbnail(string $rawThumbnail, string $defaultThumbPath, string $absolutePath, string $saveFolder, object $params): string
    {
        // Thumbnail SIZE is a component concern (a thumbnail must never be the
        // full image). FORMAT/QUALITY are plugin concerns — applied only when a
        // plugin handles the event; otherwise the always-on baseline resize runs.
        $thumbnailWidth = (int) $params->get('media_thumbnail_width', 200);
        $thumbnailHeight = (int) $params->get('media_thumbnail_height', 200);
        $allowedMimes = self::resolveAllowedMimes($params->get('media_mime'));

        // Strip URL fragments (e.g., cache busters) and normalize leading slashes
        $cleanThumb = ltrim(strtok($rawThumbnail, '#'), '/');
        $cleanDefault = ltrim($defaultThumbPath, '/');

        // Save empty string when no thumbnail is set or user selected the default placeholder
        if (empty($cleanThumb) || $cleanThumb === $cleanDefault) {
            return '';
        }

        // Already processed — return as-is to avoid re-generating on subsequent saves
        if (str_starts_with($cleanThumb, $saveFolder)) {
            if (file_exists(JPATH_ROOT . '/' . $cleanThumb)) {
                return $cleanThumb;
            }
        }

        $sourcePath = JPATH_ROOT . '/' . $cleanThumb;
        $baseName = pathinfo($cleanThumb, PATHINFO_FILENAME); // Handle cases where there's no extension (e.g., "README")

        // Keep the source extension for the thumbnail; a plugin may convert it.
        $outputFormat = pathinfo($cleanThumb, PATHINFO_EXTENSION) ?: 'jpg';

        $uniqueName = self::getUniqueFilename(
            name:           $baseName,
            basePath:       $absolutePath,
            outputFormat:   $outputFormat,
            suffix:         '-thumb',
        );

        $destPath = $absolutePath . '/' . $uniqueName;

        // Hand thumbnail processing to the plugin layer, carrying the component's
        // thumbnail dimensions as the target size. The plugin owns FORMAT/QUALITY.
        $result = self::dispatchProcess(
            eventName:    'onAlfaMediaThumbnail',
            source:       $sourcePath,
            dest:         $destPath,
            origin:       'thumbnail',
            field:        'thumbnail',
            maxWidth:     $thumbnailWidth,
            maxHeight:    $thumbnailHeight,
            allowedMimes: $allowedMimes,
        );

        if ($result['processed']) {
            // Plugin resized/converted it — use the path it actually wrote.
            return ltrim(str_replace(JPATH_ROOT . '/', '', $result['dest']), '/');
        }

        // No plugin → always-on baseline resize so the thumbnail is never the
        // full image. Falls back to a plain copy only if GD resize fails.
        if (!self::resizeThumbnail($sourcePath, $destPath, $thumbnailWidth, $thumbnailHeight)) {
            try {
                File::copy($sourcePath, $destPath);
            } catch (Throwable $e) {
                return '';
            }
        }

        return ltrim(str_replace(JPATH_ROOT . '/', '', $destPath), '/');
    }

    /**
     * Generates a unique filename to prevent overwriting existing files.
     *
     * @param string $name Original or alias based filename (e.g., 'photo.jpg')
     * @param string $basePath The directory path to check for conflicts.
     * @param string $outputFormat The target file extension (e.g., 'webp').
     * @param string $suffix Optional suffix appended to the name (e.g., 'thumb').
     *
     * @return string The unique filename with extension.
     */
    private static function getUniqueFilename(
        string $name,
        string $basePath,
        string $outputFormat,
        string $suffix = '',
    ): string {
        $name = OutputFilter::stringURLSafe(pathinfo($name, PATHINFO_FILENAME));

        // Append suffix if provided (e.g., 'thumb' → 'my-article-thumb')
        if (!empty($suffix)) {
            $name .= '-' . OutputFilter::stringURLSafe($suffix);
        }

        // Increment until unique
        $candidate = $name;
        while (file_exists($basePath . '/' . $candidate . '.' . $outputFormat)) {
            $candidate = StringHelper::increment($candidate, 'dash');
        }

        return $candidate . '.' . $outputFormat;
    }

    /**
     * Get media data for items, optionally with placeholder fallback
     *
     * @param string $origin Media origin identifier
     * @param int|array $itemIDs Single ID or array of IDs
     *
     * @return array|object[] Media objects (single) or grouped array (multiple)
     */
    public static function getMediaData(string $origin, int|array|null $itemIDs, $usePlaceHolder = false): array
    {
        // 1. Config
        $params = ComponentHelper::getParams('com_alfa');
        $placeholderPath = trim($params->get('media_placeholder', ''));
        $urlThumbnailPath = trim($params->get('media_url_thumbnail', ''));

        // 2. Normalize input
        $single = !is_array($itemIDs);
        $itemIDs = array_map('intval', (array) $itemIDs);

        if (empty($itemIDs)) {
            return [];
        }

        // 3. Query
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_media'))
            ->whereIn($db->quoteName('item_id'), $itemIDs)
            ->where($db->quoteName('origin') . ' = ' . $db->quote($origin))
            ->order($db->quoteName('ordering'));
        $db->setQuery($query);
        $results = $db->loadObjectList();

        // 4. Process results (single loop: thumbnails + grouping)
        $grouped = [];
        foreach ($results as $result) {
            // Resolve empty thumbnail first (while data is raw)
            if (empty($result->thumbnail)) {
                $result->thumbnail = ltrim(self::resolveThumbnail(
                    result: $result,
                    urlThumbnailPath: $urlThumbnailPath,
                ), '/');
            }

            // THEN clean both paths for output (relative — kept for storage / admin
            // round-trip / server-side file ops).
            $result->path = ltrim($result->path, '/');

            // Display URLs (absolute) for templates — never mutate the relative path.
            $result->url = self::toUrl($result->path);
            $result->thumbnail_url = self::toUrl($result->thumbnail);

            $grouped[$result->item_id][] = $result;
        }

        // 5. Placeholder (if enabled)
        $placeholder = !empty($placeholderPath) && $usePlaceHolder
            ? self::getPlaceholderMedia($placeholderPath)
            : null;

        // 6. Return
        if ($single) {
            $firstGroup = reset($grouped) ?: [];
            return !empty($firstGroup) ? $firstGroup : ($placeholder ? [$placeholder] : []);
        }

        if ($placeholder) {
            $grouped += array_fill_keys($itemIDs, [$placeholder]);
        }

        return $grouped;
    }

    /**
     * Resolves a display thumbnail for records with no stored thumbnail.
     * Images fall back to their own path; URL media uses the configured URL thumbnail.
     */
    private static function resolveThumbnail(object $result, string $urlThumbnailPath): string
    {
        return match ($result->type) {
            'image' => $result->path,
            'url' => $urlThumbnailPath,
            default => $result->path
        };
    }

    /**
     * Get placeholder media object from component params
     *
     * @return object|null Placeholder media object or null if not configured
     */
    private static function getPlaceholderMedia(string $placeholderPath): ?object
    {
        // Create a pseudo-media object matching the structure of real media
        return (object) [
            'id' => 0,
            'item_id' => 0,
            'path' => $placeholderPath,
            'thumbnail' => $placeholderPath,
            'url' => self::toUrl($placeholderPath),
            'thumbnail_url' => self::toUrl($placeholderPath),
            'alt' => 'Placeholder image',
            'ordering' => 0,
            'type' => 'placeholder',
            'color' => null,
            'origin' => 'placeholder',
        ];
    }

    /**
     * Per-MIME GD loader functions, keyed by source MIME type. Used by
     * {@see self::dominantColor()} to open the final file for colour sampling.
     *
     * @var    array<string, string>
     * @since  1.0.2
     */
    private const COLOR_LOADERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/gif'  => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
    ];

    /**
     * Compute the dominant (average) colour of an image file as an rgb(r,g,b)
     * string. This is a CORE component concern — the value populates the
     * `color` column on #__alfa_media and is used as the image-load placeholder
     * regardless of whether the optimizer plugin is installed. It must always be
     * computed from the FINAL stored file (whether the plugin optimised it or it
     * was copied as-is).
     *
     * Samples every 10th pixel for speed (same logic that previously lived in
     * the processing engine). Returns '' when the file is missing/unreadable or
     * the server lacks the GD loader for the file's format — the column then
     * stays empty rather than blocking the save.
     *
     * @param string $imagePath Absolute path of the final image file.
     *
     * @return string Dominant colour as 'rgb(r,g,b)', or '' on failure.
     *
     * @since   1.0.2
     */
    private static function dominantColor(string $imagePath): string
    {
        if ($imagePath === '' || !is_file($imagePath)) {
            return '';
        }

        $info = @getimagesize($imagePath);

        if ($info === false) {
            return '';
        }

        $loaderFunction = self::COLOR_LOADERS[$info['mime']] ?? null;

        if ($loaderFunction === null || !function_exists($loaderFunction)) {
            return '';
        }

        $gd = @$loaderFunction($imagePath);

        if (!$gd) {
            return '';
        }

        if ($info['mime'] === 'image/png') {
            imagepalettetotruecolor($gd);
            imagealphablending($gd, true);
            imagesavealpha($gd, true);
        }

        $r_total = $g_total = $b_total = $total = 0;

        // Sample every 10th pixel instead of every single one for speed.
        $step = 10;

        for ($x = 0; $x < imagesx($gd); $x += $step) {
            for ($y = 0; $y < imagesy($gd); $y += $step) {
                $rgb = imagecolorat($gd, $x, $y);
                $r_total += ($rgb >> 16) & 0xFF;
                $g_total += ($rgb >> 8) & 0xFF;
                $b_total += $rgb & 0xFF;
                $total++;
            }
        }

        imagedestroy($gd);

        if ($total === 0) {
            return '';
        }

        return sprintf(
            'rgb(%d,%d,%d)',
            (int) round($r_total / $total),
            (int) round($g_total / $total),
            (int) round($b_total / $total),
        );
    }

    /**
     * Baseline thumbnail resize — the always-on component fallback used when no
     * optimizer plugin handles {@see onAlfaMediaThumbnail}. Scales the source to
     * FIT inside ($maxW × $maxH) preserving aspect ratio, and writes it in the
     * SAME format as the source (no conversion, no fancy compression — that is
     * the plugin's job). This guarantees a thumbnail is never the full image.
     *
     * Returns false when the file is missing/unreadable, the server lacks the GD
     * loader/encoder for the format, or the write fails — the caller then copies
     * the original as a last resort.
     *
     * @param string $src  Absolute source image path.
     * @param string $dest Absolute destination path (extension matches source).
     * @param int    $maxW Maximum thumbnail width.
     * @param int    $maxH Maximum thumbnail height.
     *
     * @return bool True when a resized thumbnail was written, false otherwise.
     *
     * @since   1.0.2
     */
    private static function resizeThumbnail(string $src, string $dest, int $maxW, int $maxH): bool
    {
        if ($src === '' || !is_file($src) || $maxW < 1 || $maxH < 1) {
            return false;
        }

        $info = @getimagesize($src);

        if ($info === false) {
            return false;
        }

        $loaderFunction = self::COLOR_LOADERS[$info['mime']] ?? null;

        if ($loaderFunction === null || !function_exists($loaderFunction)) {
            return false;
        }

        $srcGd = @$loaderFunction($src);

        if (!$srcGd) {
            return false;
        }

        $srcW = imagesx($srcGd);
        $srcH = imagesy($srcGd);

        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($srcGd);

            return false;
        }

        // Scale to fit inside the box, preserving aspect ratio; never upscale.
        $scale = min($maxW / $srcW, $maxH / $srcH, 1);
        $newW  = max(1, (int) round($srcW * $scale));
        $newH  = max(1, (int) round($srcH * $scale));

        $dstGd = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for formats that support it.
        if (\in_array($info['mime'], ['image/png', 'image/gif', 'image/webp', 'image/avif'], true)) {
            imagealphablending($dstGd, false);
            imagesavealpha($dstGd, true);
            $transparent = imagecolorallocatealpha($dstGd, 0, 0, 0, 127);
            imagefill($dstGd, 0, 0, $transparent);
        }

        imagecopyresampled($dstGd, $srcGd, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        // Encode in the SAME format as the source (no conversion here).
        $result = match ($info['mime']) {
            'image/jpeg' => imagejpeg($dstGd, $dest),
            'image/png'  => imagepng($dstGd, $dest),
            'image/gif'  => imagegif($dstGd, $dest),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dstGd, $dest) : false,
            'image/avif' => function_exists('imageavif') ? imageavif($dstGd, $dest) : false,
            default      => false,
        };

        imagedestroy($srcGd);
        imagedestroy($dstGd);

        return (bool) $result;
    }

    /**
     * Dispatch the pre-process validation event so a plugin can veto a file.
     *
     * @param string $source       Absolute source path of the upload.
     * @param string $origin       Media origin (item|category|manufacturer).
     * @param string $field        Logical field name ('image' | 'thumbnail').
     * @param array  $allowedMimes Allowed source MIME types.
     *
     * @return bool True when valid (or unhandled), false when a plugin vetoed it
     *              (the plugin's error message is enqueued here).
     *
     * @since   1.0.2
     */
    private static function dispatchValidate(string $source, string $origin, string $field, array $allowedMimes): bool
    {
        PluginHelper::importPlugin('alfa-media');

        $event = new ValidateEvent('onAlfaMediaValidate', [
            'source'       => $source,
            'origin'       => $origin,
            'field'        => $field,
            'allowedMimes' => $allowedMimes,
        ]);

        Factory::getApplication()->getDispatcher()->dispatch($event->getName(), $event);

        if (!$event->isValid()) {
            $error = $event->getError();

            if ($error !== '') {
                Factory::getApplication()->enqueueMessage($error, 'error');
            }

            return false;
        }

        return true;
    }

    /**
     * Dispatch an image-processing event (onAlfaMediaBeforeProcess for full-size
     * media, onAlfaMediaThumbnail for thumbnails) and read the result back.
     *
     * The component performs NO processing itself: a plugin resizes/converts
     * source -> dest and sets processed=true. When no plugin handles the file,
     * processed stays false, and the caller stores the original unchanged. The
     * dominant colour is NOT part of this contract — the component always
     * computes it from the final file via {@see self::dominantColor()}.
     *
     * The main-image path (onAlfaMediaBeforeProcess) carries NO
     * format/quality/dimensions — the plugin reads its own per-context settings.
     * The thumbnail path (onAlfaMediaThumbnail) carries the component's thumbnail
     * maxWidth/maxHeight (component owns thumbnail SIZE), and the plugin applies
     * its own FORMAT/QUALITY.
     *
     * @param string $eventName    Event name to dispatch.
     * @param string $source       Absolute source path.
     * @param string $dest         Absolute destination path (plugin may rewrite).
     * @param string $origin       Media origin / context.
     * @param string $field        Logical field name.
     * @param array  $allowedMimes Allowed source MIME types.
     * @param int    $maxWidth     Thumbnail target max width (thumbnail path only).
     * @param int    $maxHeight    Thumbnail target max height (thumbnail path only).
     *
     * @return array{dest:string, processed:bool}
     *
     * @since   1.0.2
     */
    private static function dispatchProcess(
        string $eventName,
        string $source,
        string $dest,
        string $origin,
        string $field,
        array $allowedMimes,
        int $maxWidth = 0,
        int $maxHeight = 0,
    ): array {
        PluginHelper::importPlugin('alfa-media');

        $arguments = [
            'source'       => $source,
            'dest'         => $dest,
            'origin'       => $origin,
            'field'        => $field,
            'maxWidth'     => $maxWidth,
            'maxHeight'    => $maxHeight,
            'allowedMimes' => $allowedMimes,
        ];

        // Thumbnails get their own distinct event class so listeners can target
        // them separately; everything else is a full-size process event.
        $event = $eventName === 'onAlfaMediaThumbnail'
            ? new ThumbnailEvent($eventName, $arguments)
            : new BeforeProcessEvent($eventName, $arguments);

        Factory::getApplication()->getDispatcher()->dispatch($event->getName(), $event);

        return [
            // getFinalPath() falls back to the proposed dest when the plugin
            // did not rewrite the path; dest itself is never mutated.
            'dest'      => $event->getFinalPath(),
            'processed' => $event->isProcessed(),
        ];
    }

    /**
     * Dispatch the post-process notification event (side-effects only).
     *
     * @param string $source    Absolute source path.
     * @param string $dest      Absolute destination path that was stored.
     * @param string $origin    Media origin / context.
     * @param string $field     Logical field name.
     * @param string $color     Dominant colour computed for the stored file.
     * @param bool   $processed Whether a plugin actually processed the image.
     *
     * @return void
     *
     * @since   1.0.2
     */
    private static function dispatchAfterProcess(string $source, string $dest, string $origin, string $field, string $color, bool $processed): void
    {
        PluginHelper::importPlugin('alfa-media');

        $event = new AfterProcessEvent('onAlfaMediaAfterProcess', [
            'source'    => $source,
            'dest'      => $dest,
            'origin'    => $origin,
            'field'     => $field,
            'color'     => $color,
            'processed' => $processed,
        ]);

        Factory::getApplication()->getDispatcher()->dispatch($event->getName(), $event);
    }

    /**
     * Dispatch the pre-delete cleanup event so a plugin can purge derivatives
     * before the component removes the media rows/files.
     *
     * @param object[] $rows  Media rows about to be deleted (path, thumbnail, ...).
     *
     * @return void
     *
     * @since   1.0.2
     */
    private static function dispatchBeforeDelete(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        PluginHelper::importPlugin('alfa-media');

        $paths = [];

        foreach ($rows as $row) {
            foreach ([$row->path ?? '', $row->thumbnail ?? ''] as $p) {
                $p = ltrim((string) $p, '/');

                if ($p !== '') {
                    $paths[] = $p;
                }
            }
        }

        $event = new BeforeDeleteEvent('onAlfaMediaBeforeDelete', [
            'rows'  => $rows,
            'paths' => $paths,
        ]);

        Factory::getApplication()->getDispatcher()->dispatch($event->getName(), $event);
    }
}
