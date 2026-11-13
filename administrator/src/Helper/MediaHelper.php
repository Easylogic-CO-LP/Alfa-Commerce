<?php

/**
 * @version    CVS: 1.0.0
 * @package    Com_Test
 * @author     Agamemnon <info@easylogic.gr>
 * @copyright  2024 Agamemnon
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Media\AfterProcessEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\BeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\BeforeProcessEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\ThumbnailEvent;
use Alfa\Component\Alfa\Administrator\Event\Media\ValidateEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Exception;
use FilesystemIterator;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;
use Joomla\String\StringHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class MediaHelper
{
    /**
     * The capability group that owns media processing.
     *
     * @var string
     * @since  2.0.0
     */
    private const MEDIA_GROUP = 'alfa-media';

    /**
     * The longest image description (alt) a media row holds: its column is varchar(75). The tile's input, saveMedia
     * and the API's media rows all hold to it, so a longer one never fails a gallery save (2.2.0).
     *
     * @var int
     * @since  2.2.0
     */
    public const ALT_MAX_LENGTH = 75;

    /**
     * The thumbnail box a site uses when it stores none (2.2.0: 500 x 500; Options' default and the installer's,
     * ComponentOptions::DEFAULTS).
     *
     * @var int
     * @since  2.2.0
     */
    public const THUMBNAIL_BOX = 500;

    /**
     * A row's thumbnail_origin when its thumbnail was made from the row's own image (2.2.0): the Recreate thumbnails job
     * may make it again.
     *
     * @var string
     * @since  2.2.0
     */
    public const THUMBNAIL_FROM_IMAGE = 'image';

    /**
     * A row's thumbnail_origin when its thumbnail is a picture the merchant chose on the tile (2.2.0): the Recreate
     * thumbnails job leaves it as it is. A row written before 2.2.0 stores neither (NULL).
     *
     * @var string
     * @since  2.2.0
     */
    public const THUMBNAIL_CHOSEN = 'chosen';

    /**
     * Standard image MIME types accepted out of the box.
     *
     * Single source of truth for the fallback used when the component's
     * "Allowed Image Types" (media_mime) setting is left empty: an empty
     * setting means "allow all standard image types", not "allow none". Shared
     * by the upload gate (MediaZoneField / grid layout -> client validation) and
     * server-side handling so both ends agree.
     *
     * @var string[]
     * @since  1.0.0
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
     * Canonical, safe file extension for each supported image MIME type.
     *
     * The stored file extension is ALWAYS taken from this map, keyed by the
     * real MIME detected from the file's own bytes (never from the client's
     * upload metadata), so a forged upload can never dictate the on-disk
     * extension (e.g. a ".php" masquerading as an image).
     *
     * @var array<string, string>
     * @since  2.0.0
     */
    private const MIME_EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/png' => 'png',
        'image/bmp' => 'bmp',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * The media manager's image extensions while com_media stores none: the default of its Legal Image Extensions, as
     * Joomla's media manager reads it.
     *
     * @var string
     * @since  2.2.1
     */
    private const MEDIA_IMAGE_EXTENSIONS = 'bmp,gif,jpg,jpeg,png,webp,avif';

    /**
     * The folders Joomla's Local filesystem plugin offers while its Directories were never saved: that plugin's own
     * default.
     *
     * @var string
     * @since  2.2.1
     */
    private const MEDIA_LOCAL_DIRECTORIES = '[{"directory":"images"},{"directory":"files"}]';

    /**
     * Resolve the effective allowed image MIME types.
     *
     * Returns the configured types, or, when none are configured, the full
     * {@see self::DEFAULT_IMAGE_MIMES} set, so uploads work without requiring the
     * admin to pre-select every type.
     *
     * @param mixed $configured The raw media_mime config value (array|string|null).
     *
     * @return string[] The effective allowed MIME types.
     *
     * @since  1.0.0
     */
    public static function resolveAllowedMimes($configured): array
    {
        $mimes = array_values(array_filter((array) $configured));

        return $mimes ?: self::DEFAULT_IMAGE_MIMES;
    }

    /**
     * Is this a remote image URL that is safe to store and later render?
     *
     * Only absolute http/https URLs with a host qualify. Everything else (
     * javascript:, data:, vbscript:, file:, protocol-relative "//evil/x.png"
     * and bare paths) is rejected, because the stored value is emitted
     * directly into an image src on the storefront and in documents.
     *
     * @param string $url Client-supplied media source.
     *
     *
     * @since  2.0.0
     */
    private static function isSafeRemoteUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /**
     * Validate a file as a real image and return the safe extension to store it under.
     *
     * Reads the actual image type from the file's magic bytes via getimagesize()
     * (the client-supplied MIME and the original filename extension are never
     * trusted) and confirms it is one of the allowed types. This is the
     * authoritative, plugin-independent gate: it holds even when no alfa-media
     * optimizer plugin is installed to process the upload.
     *
     * @param string $source Absolute path to the file on disk.
     * @param string[] $allowedMimes Effective allowed MIME types.
     *
     * @return string|null Canonical extension (e.g. "jpg"), or null when the file
     *                     is not a valid image of an allowed type.
     *
     * @since  2.0.0
     */
    private static function validatedImageExtension(string $source, array $allowedMimes): ?string
    {
        if ($source === '' || !is_file($source)) {
            return null;
        }

        // getimagesize() inspects the file header, so a mislabelled or disguised
        // file (e.g. PHP renamed to .png) is detected here.
        $info = @getimagesize($source);

        if ($info === false || empty($info['mime'])) {
            return null;
        }

        $mime = strtolower($info['mime']);

        if (!\in_array($mime, array_map('strtolower', $allowedMimes), true)) {
            return null;
        }

        $extension = self::MIME_EXTENSION_MAP[$mime] ?? null;

        if ($extension === null) {
            return null;
        }

        // A header alone can be faked (a valid image signature followed by a PHP
        // payload still satisfies getimagesize). When GD can decode this format,
        // require a full decode so only genuinely renderable images pass.
        if (self::gdCanDecode($mime) && @imagecreatefromstring((string) file_get_contents($source)) === false) {
            return null;
        }

        return $extension;
    }

    /**
     * Whether the local GD extension can fully decode the given image MIME type.
     *
     * Used to decide if a full-decode check is safe to enforce: for formats GD
     * cannot handle (e.g. AVIF on a GD build without libavif) the decode would
     * wrongly fail a valid file, so validation falls back to the header check.
     *
     * @param string $mime The detected image MIME type.
     *
     * @return bool True when GD can decode this format on this server.
     *
     * @since  2.0.0
     */
    private static function gdCanDecode(string $mime): bool
    {
        if (!\function_exists('imagecreatefromstring') || !\function_exists('gd_info')) {
            return false;
        }

        $gd = gd_info();

        return match ($mime) {
            'image/jpeg' => !empty($gd['JPEG Support']),
            'image/png' => !empty($gd['PNG Support']),
            'image/gif' => !empty($gd['GIF Read Support']),
            'image/bmp' => !empty($gd['BMP Support']),
            'image/webp' => !empty($gd['WebP Support']),
            'image/avif' => !empty($gd['AVIF Support']),
            default => false,
        };
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
     * @return int Effective max upload size in bytes (0 = unlimited).
     *
     * @since  1.0.0
     */
    public static function maxUploadBytes(): int
    {
        $toBytes = static function ($value): int {
            $value = trim((string) $value);

            if ($value === '') {
                return 0;
            }

            $number = (int) $value;
            $unit = strtolower($value[\strlen($value) - 1]);

            return match ($unit) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
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
     * already absolute (external `type=url` media (http/https), protocol-relative
     * URLs, or data URIs) are returned untouched.
     *
     * @param string|null $path Relative media path (e.g. "images/commerce/x.webp").
     *
     * @return string Absolute URL, or '' for an empty path.
     *
     * @since  1.0.0
     */
    public static function toUrl(?string $path): string
    {
        $path = trim((string) $path);

        // Empty, or already an absolute / protocol-relative / data URL -> leave as-is.
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
     * @since  1.0.0
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
        // whether removing media also deletes the file from disk: both here (whole
        // entity deleted) and in saveMedia (a single image removed). On by default.
        if ((bool) ComponentHelper::getParams('com_alfa')->get('media_full_deletion', 1)) {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'path', 'thumbnail']))
                    ->from($db->quoteName('#__alfa_media'))
                    ->where($db->quoteName('origin') . ' = ' . $db->quote($origin))
                    ->whereIn($db->quoteName('item_id'), $itemIds),
            )->loadObjectList();

            self::deleteFilesOfRows(rows: $rows);
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
     * @since  1.0.0
     */
    private const DELETE_BATCH_SIZE = 500;

    /**
     * Delete media by their own row ids: rows plus any existing local files.
     * Used by the Tools -> Media maintenance view to clean up selected orphan /
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
     * @since  1.0.0
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
                    ->select($db->quoteName(['id', 'path', 'thumbnail']))
                    ->from($db->quoteName('#__alfa_media'))
                    ->whereIn($db->quoteName('id'), $batch),
            )->loadObjectList();

            // Let a plugin clean up derivatives before the rows/files go away.
            self::dispatchBeforeDelete($rows);

            self::deleteFilesOfRows(rows: $rows);

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
     * Delete the local files of these rows, each only when no other media row names it as its path or its thumbnail
     * (2.2.0: two rows may name one file, a pick of an image already in the save folder or a tile thumbnail taken
     * from it, and deleting one row must never take the other's image). The rows themselves are the caller's to
     * delete; a file that only they name goes, a file another row names stays. External URLs and empty columns are
     * skipped; a file that is missing or locked never blocks the delete.
     *
     * @param object[] $rows Media rows (id, path, thumbnail) about to be deleted.
     *
     * @since  2.2.0
     */
    private static function deleteFilesOfRows(array $rows): void
    {
        $files = [];
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row->id;

            foreach ([$row->path, $row->thumbnail] as $file) {
                $file = self::localFile(path: (string) $file);

                if ($file !== '') {
                    $files[$file] = true;
                }
            }
        }

        foreach (array_diff(array_keys($files), self::filesNamedByOtherRows(files: array_keys($files), exceptIds: $ids)) as $file) {
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

    /**
     * The site-relative file a path or thumbnail column names, or '' for an empty column or an external URL (a type
     * url row stores an absolute URL).
     *
     * @param string $path The column as stored.
     *
     * @return string The relative path, no leading slash.
     *
     * @since  2.2.0
     */
    private static function localFile(string $path): string
    {
        $file = ltrim(trim($path), '/');

        if ($file === ''
            || str_starts_with($file, 'http://')
            || str_starts_with($file, 'https://')
            || str_starts_with($file, '//')
            || str_contains($file, '://')) {
            return '';
        }

        return $file;
    }

    /**
     * Which of these files another media row names as its path or its thumbnail (2.2.0): every row but the given
     * ones, a stored value with or without its leading slash.
     *
     * @param string[] $files Site-relative files, no leading slash.
     * @param int[] $exceptIds The rows that do not count (the ones being deleted or rewritten).
     *
     * @return string[] The files still named elsewhere.
     *
     * @since  2.2.0
     */
    public static function filesNamedByOtherRows(array $files, array $exceptIds = []): array
    {
        $files = array_values(array_unique(array_filter(array_map(static fn ($file): string => ltrim((string) $file, '/'), $files))));

        if ($files === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $named = [];

        foreach (array_chunk($files, self::DELETE_BATCH_SIZE) as $batch) {
            $values = implode(',', array_map([$db, 'quote'], array_merge($batch, array_map(static fn (string $file): string => '/' . $file, $batch))));
            $query = $db->getQuery(true)
                ->select($db->quoteName(['path', 'thumbnail']))
                ->from($db->quoteName('#__alfa_media'))
                ->where('(' . $db->quoteName('path') . ' IN (' . $values . ') OR ' . $db->quoteName('thumbnail') . ' IN (' . $values . '))');
            $except = array_values(array_filter(array_map('intval', $exceptIds), static fn (int $id): bool => $id > 0));

            if ($except !== []) {
                $query->whereNotIn($db->quoteName('id'), $except);
            }

            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                foreach ([$row->path, $row->thumbnail] as $file) {
                    $named[ltrim((string) $file, '/')] = true;
                }
            }
        }

        return array_values(array_intersect($files, array_keys($named)));
    }

    /**
     * Whether a site-relative file lies inside a folder: the folder's own path followed by a separator, so a save
     * folder images/commerce never takes in images/commerce-old (2.2.0).
     *
     * @param string $file Site-relative file.
     * @param string $folder Site-relative folder.
     *
     * @return bool True when it lies inside.
     *
     * @since  2.2.0
     */
    public static function insideFolder(string $file, string $folder): bool
    {
        $folder = trim($folder, '/');

        return $folder !== '' && str_starts_with(ltrim($file, '/'), $folder . '/');
    }

    /**
     * Return the ids of every media row whose (origin, item_id) no longer points
     * at a live parent entity (item / category / manufacturer / combination).
     * Detected purely
     * in SQL via LEFT JOINs. Used by the Tools -> Media "delete all orphans" action.
     *
     * @return int[]
     *
     * @since  1.0.0
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
            ->join('LEFT', $db->quoteName('#__alfa_item_combinations', 'co')
                . ' ON a.origin = ' . $db->quote('combination') . ' AND co.id = a.item_id')
            ->where('(CASE a.origin'
                . ' WHEN ' . $db->quote('item') . ' THEN (i.id IS NOT NULL)'
                . ' WHEN ' . $db->quote('category') . ' THEN (c.id IS NOT NULL)'
                . ' WHEN ' . $db->quote('manufacturer') . ' THEN (m.id IS NOT NULL)'
                . ' WHEN ' . $db->quote('combination') . ' THEN (co.id IS NOT NULL)'
                . ' ELSE 1 END) = 0');

        return array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
    }

    /**
     * Return the ids of every local-file media row whose file is absent on disk.
     * Disk presence is not expressible in SQL, so each local row is stat()-ed
     * here. External (type=url) and path-less rows are skipped. Used by both the
     * Tools -> Media "file missing" filter and the "delete all missing" action.
     *
     * @return int[]
     *
     * @since  1.0.0
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
     * @since  1.0.0
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
     * Every media path tracked in #__alfa_media: both `path` and `thumbnail`,
     * keyed by relative path for O(1) lookup. Including the thumbnail column
     * ensures generated thumbnails are never treated as untracked.
     *
     * @return array<string, true>
     *
     * @since  1.0.0
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
     * #__alfa_media (neither as a path nor a thumbnail): leftovers from aborted
     * uploads, manual copies, or records deleted without their files.
     *
     * @return object[] Each: { path (relative), size (int bytes), mtime (int) }.
     *
     * @since  1.0.0
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
     * before removal; a file referenced by any media row is never deleted.
     *
     * @param string[] $relativePaths Relative paths (as listed by findUntrackedFiles()).
     *
     * @return int Number of files deleted.
     *
     * @since  1.0.0
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
                // Best-effort: a locked/removed file must not abort the batch.
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
     * @since  1.0.0
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

        $dropIndex = 0;
        $ordering = 0;

        // The stored path and thumbnail of every existing row the form sends: a tile left as it was keeps its own
        // thumbnail, and only its own (processThumbnail()).
        $existingIds = array_values(array_filter(
            array_map('intval', array_filter(array_keys($mediaData), static fn ($id): bool => !str_contains((string) $id, 'new-'))),
            static fn (int $id): bool => $id > 0,
        ));
        $stored = $existingIds === [] ? [] : ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'path', 'thumbnail']))
                ->from($db->quoteName('#__alfa_media'))
                ->whereIn($db->quoteName('id'), $existingIds),
        )->loadObjectList('id') ?: []);

        foreach ($mediaData as $id => $media) {
            // Check if media is new
            $isNew = str_contains($id, 'new-');
            $alt = self::cappedAlt(alt: (string) ($media['alt'] ?? ''));
            $finalThumbnail = '';
            // What the thumbnail was made from (2.2.0): the row's own image, or a picture chosen on the tile; null for
            // none. An existing row's tile left as it was keeps its stored origin ($keepOrigin).
            $thumbnailOrigin = null;
            $keepOrigin = false;

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
                    params:             $params,
                    stored:             $isNew ? null : ($stored[(int) $id] ?? null),
                );

                if (!$isNew && isset($stored[(int) $id]) && self::keepsStoredThumbnail(cleanThumb: self::thumbnailSource(rawThumbnail: $media['thumbnail']), stored: $stored[(int) $id])) {
                    $keepOrigin = true;
                } elseif ($finalThumbnail !== '') {
                    // A new pick whose thumbnail is the picked file, or an existing row's tile pointed at its own image,
                    // is made from the row's image; any other picture was chosen on the tile.
                    $imageSource = $isNew
                        ? ($isPicker ? ltrim(rawurldecode(str_replace('picker://', '', HTMLHelper::cleanImageURL($media['source'])->url)), '/') : null)
                        : (isset($stored[(int) $id]) ? ltrim((string) $stored[(int) $id]->path, '/') : null);
                    $thumbnailOrigin = $imageSource !== null && self::thumbnailSource(rawThumbnail: $media['thumbnail']) === $imageSource
                        ? self::THUMBNAIL_FROM_IMAGE
                        : self::THUMBNAIL_CHOSEN;
                }
            }

            // New media
            if ($isNew) {
                $src = '';
                $nameForFile = '';

                // FIND THE UNIQUE NAME FOR THE FILE
                if ($isDrop) { // Dropped Media Handler
                    //dropped media and mediaData has the same ordering sequence
                    if (isset($droppedMedia[$dropIndex])) {
                        $file = $droppedMedia[$dropIndex];

                        $noError = $file['error'] === 0;

                        $dropIndex++; //keep track of the ordering sequence
                        if ($noError) {
                            // Authoritative server-side check: the stored extension is
                            // derived from the file's real magic bytes (NOT the client
                            // MIME), and anything that is not an allowed image is rejected.
                            $safeExtension = self::validatedImageExtension($file['tmp_name'], $allowedMimes);

                            if ($safeExtension === null) {
                                Factory::getApplication()->enqueueMessage(
                                    Text::sprintf('COM_ALFA_MEDIA_ERROR_INVALID_IMAGE', $file['name']),
                                    'error',
                                );
                            } else {
                                $src = $file['tmp_name'];

                                // Prevent errors and overwrites by setting a unique file name if it already exists in the directory
                                $nameForFile = self::getUniqueFilename(
                                    name:           $file['name'],
                                    basePath:       $absolutePath,
                                    outputFormat:   $safeExtension,
                                );
                            }
                        }
                    }
                } elseif ($isPicker) { // Joomla Media Picker Handler
                    $cleanPath = HTMLHelper::cleanImageURL($media['source']);
                    // The media field stores a URL: a file name with a space arrives as %20 (2.2.0).
                    $cleanPath = rawurldecode(str_replace('picker://', '', $cleanPath->url));

                    // Containment: the resolved file must sit inside one of the
                    // media roots, not merely inside the web root: a picker path
                    // is only ever produced by the media manager, so anything
                    // outside those roots is forged, and scoping this way stops an
                    // API caller referencing (and copying) images from
                    // administrator/ or any other non-media folder.
                    // BOTH com_media roots count: file_path and image_path are
                    // configured separately and are NOT interchangeable (this very
                    // site runs file_path=files while its images live under
                    // images/), plus com_alfa's own save folder.
                    $realSrc = realpath(JPATH_ROOT . '/' . $cleanPath);
                    $withinRoot = false;

                    if ($realSrc !== false) {
                        $mediaParams = ComponentHelper::getParams('com_media');

                        foreach (
                            [
                                $mediaParams->get('file_path', 'images'),
                                $mediaParams->get('image_path', 'images'),
                                $saveFolder,
                            ] as $candidate
                        ) {
                            $candidate = trim((string) $candidate, '/');

                            if ($candidate === '') {
                                continue;
                            }

                            $rootReal = realpath(JPATH_ROOT . '/' . $candidate);

                            if ($rootReal !== false && str_starts_with($realSrc, $rootReal . \DIRECTORY_SEPARATOR)) {
                                $withinRoot = true;
                                break;
                            }
                        }
                    }

                    // Same authoritative image check as the drop path; the stored
                    // extension comes from the real MIME, never the picked path.
                    $safeExtension = $withinRoot
                        ? self::validatedImageExtension($realSrc, $allowedMimes)
                        : null;

                    if ($safeExtension === null) {
                        Factory::getApplication()->enqueueMessage(
                            Text::sprintf('COM_ALFA_MEDIA_ERROR_INVALID_IMAGE', basename($cleanPath)),
                            'error',
                        );
                    } else {
                        $src = $realSrc;

                        $nameForFile = self::getUniqueFilename(
                            name:           $cleanPath,
                            basePath:       $absolutePath,
                            outputFormat:   $safeExtension,
                        );
                    }
                }

                // PREPARE OBJECT FOR INSERT ON MEDIA TABLE
                $insertObject = null;
                if ($isUrl) { // URL HANDLER
                    // The stored path is rendered straight into src/href, so only
                    // real remote image schemes may be persisted. Without this a
                    // javascript:/data:/file: source becomes stored XSS (the
                    // scheme check in MediaController::validateUrl guards only the
                    // AJAX preview endpoint, which API writes never reach).
                    if (!self::isSafeRemoteUrl((string) ($media['source'] ?? ''))) {
                        Factory::getApplication()->enqueueMessage(
                            Text::sprintf('COM_ALFA_MEDIA_ERROR_INVALID_IMAGE', (string) ($media['source'] ?? '')),
                            'error',
                        );
                        $ordering++;
                        continue;
                    }

                    $insertObject = (object) [
                        'item_id' => $itemId,
                        'path' => $media['source'],
                        'thumbnail' => $finalThumbnail,
                        'thumbnail_origin' => $thumbnailOrigin,
                        'alt' => $alt,
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
                        // carries NO format/quality/dimensions; the plugin reads its
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
                        $dest = $result['dest'];
                        $relativeDest = ltrim(str_replace(JPATH_ROOT . '/', '', $dest), '/');
                        $storeOk = true;

                        if (!$result['processed']) {
                            // No plugin processed the image -> store the original unchanged.
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

                        // A new image with no thumbnail of its own gets one from its stored main
                        // image: a drop (its blob URL is browser-only and unreachable server-side)
                        // and an API pick that names none (2.2.0: every image's thumbnail is real).
                        // On subsequent saves the thumbnail is the row's own, which the
                        // processThumbnail() call at the top of the loop keeps.
                        if ($storeOk && ((str_contains($media['thumbnail'], 'blob:') && $isDrop) || $finalThumbnail === '')) {
                            $finalThumbnail = self::processThumbnail(
                                rawThumbnail:       $relativeDest,
                                defaultThumbPath:   $urlDefaultThumbnail,
                                absolutePath:       $absolutePath,
                                params:             $params,
                            );
                            $thumbnailOrigin = $finalThumbnail === '' ? null : self::THUMBNAIL_FROM_IMAGE;
                        }

                        if ($storeOk) {
                            $insertObject = (object) [
                                'item_id' => $itemId,
                                'path' => $relativeDest,
                                'thumbnail' => $finalThumbnail,
                                'thumbnail_origin' => $thumbnailOrigin,
                                'color' => $color,
                                'alt' => $alt,
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

            // Existing media
            else {
                $toDelete = isset($media['delete']) && $media['delete'] == 1;
                $isFullDelete = (bool) $params->get('media_full_deletion', 1);

                if ($toDelete) {
                    // If full delete is enabled, remove files from the server first
                    if ($isFullDelete) {
                        // Fetch the main image path associated with this thumbnail before deleting
                        $query = $db->getQuery(true)
                            ->select($db->quoteName(['path', 'thumbnail']))
                            ->from($db->quoteName('#__alfa_media'))
                            ->where($db->quoteName('id') . ' = ' . (int) $id);

                        $db->setQuery($query);
                        $result = $db->loadObject();

                        // Remove both thumbnail and main image, but only files that
                        // actually resolve inside the media folder: a tampered path
                        // column can never lead a delete outside it; and never a file
                        // another row names (2.2.0).
                        if ($result) {
                            $mediaRoot = realpath($absolutePath);
                            $namedElsewhere = self::filesNamedByOtherRows(files: [(string) $result->path, (string) $result->thumbnail], exceptIds: [(int) $id]);

                            foreach ([$result->path, $result->thumbnail] as $path) {
                                if (!$path || \in_array(ltrim((string) $path, '/'), $namedElsewhere, true)) {
                                    continue;
                                }

                                $real = realpath(JPATH_ROOT . '/' . ltrim($path, '/'));

                                if ($real !== false
                                    && $mediaRoot !== false
                                    && str_starts_with($real, $mediaRoot . \DIRECTORY_SEPARATOR)) {
                                    File::delete($real);
                                }
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
                    // Update ordering, alt text and thumbnail for existing records.
                    // The stored file path is immutable and is never taken from the
                    // client here; only an external URL may be re-pointed.
                    $obj = (object) [
                        'id' => (int) $id,
                        'alt' => $alt,
                        'ordering' => $ordering,
                        'thumbnail' => $finalThumbnail,
                    ];

                    // The origin follows the thumbnail; a tile left as it was keeps its own (a row written before
                    // 2.2.0 keeps NULL).
                    if (!$keepOrigin) {
                        $obj->thumbnail_origin = $finalThumbnail === '' ? null : $thumbnailOrigin;
                    }

                    // Allow re-pointing a type=url record to another http/https URL
                    // only, never a local filesystem path.
                    $source = $media['source'] ?? '';
                    if (($media['type'] ?? '') === 'url'
                        && (str_starts_with($source, 'http://') || str_starts_with($source, 'https://'))) {
                        $obj->path = $source;
                    }

                    $db->updateObject('#__alfa_media', $obj, 'id', true);
                }
            }
            $ordering++;
        }
        return true;
    }

    /**
     * Processes and saves a thumbnail image.
     *
     * Returns an empty string if the thumbnail is missing or matches the default placeholder; returns the row's own
     * stored thumbnail when the tile still shows it (an existing row re-saved as it was: its stored thumbnail, or its
     * path where it stores none or stores its path), so a re-save writes no file; otherwise makes a new thumbnail of
     * the given image at the configured box. A file that merely lies inside the save folder is NOT kept as it is
     * (2.2.0): it may be another row's image, and a new row then gets its own copy, never another row's file. A source
     * that is not an image file of the media folders (isThumbnailSource()) is refused with a message and gives none
     * (2.2.1).
     *
     * @param string $rawThumbnail Raw (relative) thumbnail path, possibly URL-encoded and with a fragment.
     * @param string $defaultThumbPath Path of the default placeholder thumbnail to compare against.
     * @param string $absolutePath Absolute filesystem path to the media save directory.
     * @param object $params Component parameters object.
     * @param object|null $stored The row's stored path and thumbnail when it already exists; null for a new row.
     *
     * @return string Relative path of the thumbnail, or empty string if none.
     * @since  1.0.0
     */
    private static function processThumbnail(string $rawThumbnail, string $defaultThumbPath, string $absolutePath, object $params, ?object $stored = null): string
    {
        // Strip URL fragments (e.g., cache busters), decode what the media field encoded (a space is %20, 2.2.0) and
        // normalize leading slashes.
        $cleanThumb = self::thumbnailSource(rawThumbnail: $rawThumbnail);
        $cleanDefault = ltrim($defaultThumbPath, '/');

        // Save empty string when no thumbnail is set or user selected the default placeholder
        if (empty($cleanThumb) || $cleanThumb === $cleanDefault) {
            return '';
        }

        // The row's own thumbnail, unchanged: kept as stored, with no file written.
        if ($stored !== null && self::keepsStoredThumbnail(cleanThumb: $cleanThumb, stored: $stored)) {
            return (string) $stored->thumbnail;
        }

        // The source is a client-supplied relative path: only an image file of the media folders may be one (2.2.1,
        // isThumbnailSource()). Anything else is refused, said so, and nothing is read or copied.
        $sourcePath = JPATH_ROOT . '/' . $cleanThumb;

        if (!self::isThumbnailSource(file: $sourcePath)) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_ALFA_MEDIA_ERROR_THUMBNAIL_SOURCE', htmlspecialchars($cleanThumb, ENT_QUOTES, 'UTF-8')),
                'error',
            );

            return '';
        }

        return self::makeThumbnail(source: $sourcePath, dest: self::thumbnailDestination(source: $cleanThumb, absolutePath: $absolutePath), params: $params);
    }

    /**
     * A thumbnail as the tile or the API sends it, read as a site-relative file: its fragment (a cache buster, the media
     * field's joomlaImage part) dropped, what the media field encoded decoded (a space arrives as %20, 2.2.0), no leading
     * slash.
     *
     * @param string $rawThumbnail The thumbnail as sent.
     *
     * @return string Site-relative file, or '' for none.
     *
     * @since  2.2.0
     */
    private static function thumbnailSource(string $rawThumbnail): string
    {
        return ltrim(rawurldecode((string) strtok($rawThumbnail, '#')), '/');
    }

    /**
     * Whether a tile still shows the row's own stored thumbnail (an existing row re-saved as it was): its stored
     * thumbnail, or its path where it stores none or stores its path. Such a tile writes no file and keeps its origin.
     *
     * @param string $cleanThumb The tile's thumbnail, read by {@see self::thumbnailSource()}.
     * @param object $stored The row's stored path and thumbnail.
     *
     * @since  2.2.0
     */
    private static function keepsStoredThumbnail(string $cleanThumb, object $stored): bool
    {
        $ownThumbnail = ltrim((string) $stored->thumbnail, '/');
        $ownPath = ltrim((string) $stored->path, '/');

        return $cleanThumb === $ownThumbnail || ($cleanThumb === $ownPath && ($ownThumbnail === '' || $ownThumbnail === $ownPath));
    }

    /**
     * Where a new thumbnail of an image is written (2.2.0, saveMedia's and the Recreate thumbnails job's one naming): in
     * the save folder, `<the image's name>-thumb` with the image's extension, at a name whose stem no file on disk has in
     * any extension and no media row names, so a plugin writing another format can never overwrite another file.
     *
     * @param string $source The image, site-relative.
     * @param string $absolutePath Absolute path of the save folder.
     *
     * @return string Absolute path proposed for the thumbnail.
     *
     * @since  2.2.0
     */
    public static function thumbnailDestination(string $source, string $absolutePath): string
    {
        return rtrim($absolutePath, '/') . '/' . self::getUniqueFilename(
            name:           pathinfo($source, PATHINFO_FILENAME),
            basePath:       $absolutePath,
            outputFormat:   pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg',
            suffix:         '-thumb',
        );
    }

    /**
     * Write a thumbnail of an image at the configured box (2.2.0: the one writer, for a saved tile and for the Recreate
     * thumbnails job): the plugin layer first (onAlfaMediaThumbnail, which owns the format and the quality), else the
     * always-on baseline resize, else, only when GD cannot resize, a plain copy. An existing file at the destination is
     * overwritten. Only a source isThumbnailSource() accepts is used (2.2.1): any other file is not handed to a plugin,
     * resized or copied, and none is written, so the plain copy is only ever of an image GD cannot read (an AVIF on a
     * GD without AVIF).
     *
     * @param string $source Absolute path of the image.
     * @param string $dest Absolute path proposed for the thumbnail.
     * @param object|null $params Component parameters; null to read them.
     *
     * @return string Relative path of the file written (a plugin may change its extension), or '' when none was.
     *
     * @since  2.2.0
     */
    public static function makeThumbnail(string $source, string $dest, ?object $params = null): string
    {
        if (!self::isThumbnailSource(file: $source)) {
            return '';
        }

        // Thumbnail SIZE is a component concern (a thumbnail must never be the
        // full image). FORMAT/QUALITY are plugin concerns, applied only when a
        // plugin handles the event; otherwise the always-on baseline resize runs.
        [$thumbnailWidth, $thumbnailHeight] = self::thumbnailBox(params: $params);
        $params ??= ComponentHelper::getParams('com_alfa');
        $allowedMimes = self::resolveAllowedMimes($params->get('media_mime'));

        // Hand thumbnail processing to the plugin layer, carrying the component's
        // thumbnail dimensions as the target size. The plugin owns FORMAT/QUALITY.
        $result = self::dispatchProcess(
            eventName:    'onAlfaMediaThumbnail',
            source:       $source,
            dest:         $dest,
            origin:       'thumbnail',
            field:        'thumbnail',
            maxWidth:     $thumbnailWidth,
            maxHeight:    $thumbnailHeight,
            allowedMimes: $allowedMimes,
        );

        if ($result['processed']) {
            // Plugin resized/converted it; use the path it actually wrote, and only a file that is there (2.2.0): a
            // plugin that claims the thumbnail and writes nothing leaves no thumbnail, never a name of no file.
            $written = ltrim(str_replace(JPATH_ROOT . '/', '', $result['dest']), '/');

            return $written !== '' && is_file(JPATH_ROOT . '/' . $written) ? $written : '';
        }

        // No plugin -> always-on baseline resize so the thumbnail is never the
        // full image. Falls back to a plain copy only if GD resize fails.
        if (!self::resizeThumbnail($source, $dest, $thumbnailWidth, $thumbnailHeight)) {
            try {
                File::copy($source, $dest);
            } catch (Throwable $e) {
                return '';
            }
        }

        return ltrim(str_replace(JPATH_ROOT . '/', '', $dest), '/');
    }

    /**
     * Whether a file may be the source of a thumbnail (2.2.1): the one rule the administrator save, the API and the
     * Recreate thumbnails job share. makeThumbnail() applies it before anything is handed to a plugin, resized or
     * copied; processThumbnail() and ThumbnailRecreation ask it first, to say why no thumbnail was made.
     *
     * The file must (a) lie inside a folder Joomla's media manager offers, or the component's own save folder
     * (thumbnailRoots()); (b) carry one of the media manager's image extensions (com_media's Legal Image Extensions),
     * under its own name and the file it leads to alike; and (c) be an image of that kind: the type getimagesize() reads
     * from its header agrees with its extension. Before, any file the site could read inside its root (a backup, a log,
     * the configuration file) could be named as a thumbnail and was copied, its extension kept, into the save folder.
     *
     * @param string $file Absolute path of the file.
     *
     * @since  2.2.1
     */
    public static function isThumbnailSource(string $file): bool
    {
        $real = realpath($file);

        if ($real === false || !is_file($real)) {
            return false;
        }

        $inside = false;

        foreach (self::thumbnailRoots() as $root) {
            if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                $inside = true;

                break;
            }
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowed = array_map(
            static fn (string $one): string => strtolower(trim($one)),
            explode(',', (string) ComponentHelper::getParams('com_media')->get('image_extensions', self::MEDIA_IMAGE_EXTENSIONS)),
        );

        if (!$inside || $extension === '' || !\in_array($extension, $allowed, true) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== $extension) {
            return false;
        }

        $info = @getimagesize($real);
        $type = $info === false ? null : (self::MIME_EXTENSION_MAP[strtolower($info['mime'])] ?? null);

        return $type !== null && ($type === $extension || ($type === 'jpg' && $extension === 'jpeg'));
    }

    /**
     * The folders a thumbnail may be read from (2.2.1), as real absolute paths: each directory Joomla's media manager
     * offers through its Local filesystem plugin (the plugin's Directories, read as Joomla reads them; the plugin's own
     * default, images and files, while they were never saved; none while the plugin is disabled), and the component's
     * own save folder, where the images it stores are. A folder that does not exist is left out, and so is one that is
     * the site root itself or holds it (an empty setting, a link to a parent).
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    private static function thumbnailRoots(): array
    {
        $folders = [trim((string) ComponentHelper::getParams('com_alfa')->get('media_save_location', 'images/commerce'), '/')];

        $plugin = PluginHelper::getPlugin('filesystem', 'local');

        if (\is_object($plugin)) {
            $directories = (new Registry($plugin->params))->get('directories', self::MEDIA_LOCAL_DIRECTORIES);

            if (\is_string($directories)) {
                $directories = json_decode($directories);
            }

            foreach ((array) $directories as $entry) {
                $folders[] = trim((string) (((array) $entry)['directory'] ?? ''), '/');
            }
        }

        $siteRoot = realpath(JPATH_ROOT);
        $roots = [];

        foreach ($folders as $folder) {
            $real = $folder === '' ? false : realpath(JPATH_ROOT . '/' . $folder);

            // A folder that is the site root or holds it is never a root; the filesystem root itself ends with its
            // separator, so the separator is trimmed before it is added.
            $holdsSite = $siteRoot !== false && $real !== false
                && ($real === $siteRoot || str_starts_with($siteRoot, rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));

            if ($real !== false && $siteRoot !== false && is_dir($real) && !$holdsSite) {
                $roots[$real] = $real;
            }
        }

        return array_values($roots);
    }

    /**
     * The thumbnail box, width and height: the site's stored values, else 500 x 500 (2.2.0).
     *
     * @param object|null $params Component parameters; null to read them.
     *
     * @return array{0: int, 1: int} Width, height.
     *
     * @since  2.2.0
     */
    public static function thumbnailBox(?object $params = null): array
    {
        $params ??= ComponentHelper::getParams('com_alfa');

        return [
            max(1, (int) $params->get('media_thumbnail_width', self::THUMBNAIL_BOX)),
            max(1, (int) $params->get('media_thumbnail_height', self::THUMBNAIL_BOX)),
        ];
    }

    /**
     * An image description as a row holds it: at most {@see self::ALT_MAX_LENGTH} characters. A longer one is
     * shortened, and the editor is told once per save, in plain words, instead of the save failing on the column
     * (2.2.0). The form's own input stops at the same length, and the API refuses a longer one.
     *
     * @param string $alt The description as sent.
     *
     * @return string The description as stored.
     *
     * @since  2.2.0
     */
    private static function cappedAlt(string $alt): string
    {
        if (StringHelper::strlen($alt) <= self::ALT_MAX_LENGTH) {
            return $alt;
        }

        static $told = false;

        if (!$told) {
            $told = true;

            try {
                Factory::getApplication()->enqueueMessage(Text::sprintf('COM_ALFA_MEDIA_ALT_TOO_LONG', self::ALT_MAX_LENGTH), 'warning');
            } catch (Throwable) {
                // No application to tell (a CLI caller): the description is shortened all the same.
            }
        }

        return StringHelper::substr($alt, 0, self::ALT_MAX_LENGTH);
    }

    /**
     * Generates a unique filename to prevent overwriting existing files.
     *
     * UNIQUE BY STEM (2.2.0): a media plugin may write another format than the one proposed (the Media Optimizer set to
     * WebP turns photo.jpg into photo.webp), so a name is free only when no file on disk has its stem in any extension
     * and no media row names a file of that stem (a row whose file is gone keeps its name); otherwise a second upload of
     * one name would overwrite the first item's image.
     *
     * @param string $name Original or alias based filename (e.g., 'photo.jpg')
     * @param string $basePath The directory path to check for conflicts.
     * @param string $outputFormat The target file extension (e.g., 'webp').
     * @param string $suffix Optional suffix appended to the name (e.g., 'thumb').
     *
     * @return string The unique filename with extension.
     * @since  1.0.0
     */
    private static function getUniqueFilename(
        string $name,
        string $basePath,
        string $outputFormat,
        string $suffix = '',
    ): string {
        $name = OutputFilter::stringURLSafe(pathinfo($name, PATHINFO_FILENAME));

        // Append suffix if provided (e.g., 'thumb' -> 'my-article-thumb')
        if (!empty($suffix)) {
            $name .= '-' . OutputFilter::stringURLSafe($suffix);
        }

        // Increment until no file and no row has the stem.
        $candidate = $name;
        while (self::stemTaken(basePath: $basePath, stem: $candidate)) {
            $candidate = StringHelper::increment($candidate, 'dash');
        }

        return $candidate . '.' . $outputFormat;
    }

    /**
     * Whether a stem is taken in a folder: a file of that stem in any extension (or none), or a media row naming one as
     * its path or its thumbnail, stored with or without a leading slash.
     *
     * @param string $basePath Absolute path of the folder.
     * @param string $stem The name without its extension.
     *
     * @since  2.2.0
     */
    private static function stemTaken(string $basePath, string $stem): bool
    {
        $folder = rtrim($basePath, '/');
        $pattern = preg_replace('/[\\[\\]*?{}]/', '\\\\$0', $folder . '/' . $stem) . '.*';

        if (file_exists($folder . '/' . $stem) || (glob($pattern, GLOB_NOSORT) ?: []) !== []) {
            return true;
        }

        $relative = ltrim(str_starts_with($folder, JPATH_ROOT . '/') ? substr($folder, \strlen(JPATH_ROOT) + 1) : $folder, '/');
        $db = Factory::getContainer()->get('DatabaseDriver');
        $likes = [];

        foreach ([$relative . '/' . $stem, '/' . $relative . '/' . $stem] as $file) {
            $like = $db->quote($db->escape($file, true) . '.%', false);
            $likes[] = $db->quoteName('path') . ' LIKE ' . $like;
            $likes[] = $db->quoteName('thumbnail') . ' LIKE ' . $like;
        }

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_media'))
                ->where('(' . implode(' OR ', $likes) . ')'),
        )->loadResult() > 0;
    }

    /**
     * Get media data for items, optionally with placeholder fallback
     *
     * @param string $origin Media origin identifier
     * @param int|array $itemIDs Single ID or array of IDs
     *
     * @return array|object[] Media objects (single) or grouped array (multiple)
     * @since  1.0.0
     */
    public static function getMediaData(string $origin, int|array|null $itemIDs, $usePlaceHolder = false): array
    {
        static $cache = [];

        // 1. Normalize input
        $single = !is_array($itemIDs);
        $itemIDs = array_map('intval', (array) $itemIDs);

        if (empty($itemIDs)) {
            return [];
        }

        // 2. Request-level memo: the same (origin, items) is fetched several times
        // per page (product render + cart + listing); media is read-only here.
        sort($itemIDs);
        $cacheKey = $origin . '|' . implode(',', $itemIDs) . '|' . (int) $usePlaceHolder . '|' . (int) $single;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // 3. Config
        $params = ComponentHelper::getParams('com_alfa');
        $placeholderPath = trim($params->get('media_placeholder', ''));
        $urlThumbnailPath = trim($params->get('media_url_thumbnail', ''));

        // 4. Query
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

            // THEN clean both paths for output (relative, kept for storage / admin
            // round-trip / server-side file ops).
            $result->path = ltrim($result->path, '/');

            // Display URLs (absolute) for templates; never mutate the relative path.
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

            return $cache[$cacheKey] = !empty($firstGroup) ? $firstGroup : ($placeholder ? [$placeholder] : []);
        }

        if ($placeholder) {
            $grouped += array_fill_keys($itemIDs, [$placeholder]);
        }

        return $cache[$cacheKey] = $grouped;
    }

    /**
     * Resolves a display thumbnail for records with no stored thumbnail.
     * Images fall back to their own path; URL media uses the configured URL thumbnail.
     * @since  1.0.0
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
     * @since  1.0.0
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
     * @var array<string, string>
     * @since  1.0.0
     */
    private const COLOR_LOADERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/gif' => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
    ];

    /**
     * Compute the dominant (average) colour of an image file as an rgb(r,g,b)
     * string. This is a CORE component concern: the value populates the
     * `color` column on #__alfa_media and is used as the image-load placeholder
     * regardless of whether the optimizer plugin is installed. It must always be
     * computed from the FINAL stored file (whether the plugin optimised it or it
     * was copied as-is).
     *
     * Samples every 10th pixel for speed (same logic that previously lived in
     * the processing engine). Returns '' when the file is missing/unreadable or
     * the server lacks the GD loader for the file's format; the column then
     * stays empty rather than blocking the save.
     *
     * @param string $imagePath Absolute path of the final image file.
     *
     * @return string Dominant colour as 'rgb(r,g,b)', or '' on failure.
     *
     * @since  1.0.0
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
     * Baseline thumbnail resize: the always-on component fallback used when no
     * optimizer plugin handles {@see onAlfaMediaThumbnail}. Scales the source to
     * FIT inside ($maxW x $maxH) preserving aspect ratio, and writes it in the
     * SAME format as the source (no conversion, no fancy compression; that is
     * the plugin's job). This guarantees a thumbnail is never the full image.
     *
     * Returns false when the file is missing/unreadable, the server lacks the GD
     * loader/encoder for the format, or the write fails; the caller then copies
     * the original as a last resort.
     *
     * @param string $src Absolute source image path.
     * @param string $dest Absolute destination path (extension matches source).
     * @param int $maxW Maximum thumbnail width.
     * @param int $maxH Maximum thumbnail height.
     *
     * @return bool True when a resized thumbnail was written, false otherwise.
     *
     * @since  1.0.0
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
        $newW = max(1, (int) round($srcW * $scale));
        $newH = max(1, (int) round($srcH * $scale));

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
            'image/png' => imagepng($dstGd, $dest),
            'image/gif' => imagegif($dstGd, $dest),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dstGd, $dest) : false,
            'image/avif' => function_exists('imageavif') ? imageavif($dstGd, $dest) : false,
            default => false,
        };

        imagedestroy($srcGd);
        imagedestroy($dstGd);

        return (bool) $result;
    }

    /**
     * Dispatch the pre-process validation event so a plugin can veto a file.
     *
     * @param string $source Absolute source path of the upload.
     * @param string $origin Media origin (item|category|manufacturer).
     * @param string $field Logical field name ('image' | 'thumbnail').
     * @param array $allowedMimes Allowed source MIME types.
     *
     * @return bool True when valid (or unhandled), false when a plugin vetoed it
     *              (the plugin's error message is enqueued here).
     *
     * @since  1.0.0
     */
    private static function dispatchValidate(string $source, string $origin, string $field, array $allowedMimes): bool
    {
        $event = new ValidateEvent('onAlfaMediaValidate', [
            'source' => $source,
            'origin' => $origin,
            'field' => $field,
            'allowedMimes' => $allowedMimes,
        ]);

        self::dispatchMedia($event);

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
     * dominant colour is NOT part of this contract; the component always
     * computes it from the final file via {@see self::dominantColor()}.
     *
     * The main-image path (onAlfaMediaBeforeProcess) carries NO
     * format/quality/dimensions; the plugin reads its own per-context settings.
     * The thumbnail path (onAlfaMediaThumbnail) carries the component's thumbnail
     * maxWidth/maxHeight (component owns thumbnail SIZE), and the plugin applies
     * its own FORMAT/QUALITY.
     *
     * @param string $eventName Event name to dispatch.
     * @param string $source Absolute source path.
     * @param string $dest Absolute destination path (plugin may rewrite).
     * @param string $origin Media origin / context.
     * @param string $field Logical field name.
     * @param array $allowedMimes Allowed source MIME types.
     * @param int $maxWidth Thumbnail target max width (thumbnail path only).
     * @param int $maxHeight Thumbnail target max height (thumbnail path only).
     *
     * @return array{dest:string, processed:bool}
     *
     * @since  1.0.0
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
        $arguments = [
            'source' => $source,
            'dest' => $dest,
            'origin' => $origin,
            'field' => $field,
            'maxWidth' => $maxWidth,
            'maxHeight' => $maxHeight,
            'allowedMimes' => $allowedMimes,
        ];

        // Thumbnails get their own distinct event class so listeners can target
        // them separately; everything else is a full-size process event.
        $event = $eventName === 'onAlfaMediaThumbnail'
            ? new ThumbnailEvent($eventName, $arguments)
            : new BeforeProcessEvent($eventName, $arguments);

        self::dispatchMedia($event);

        return [
            // getFinalPath() falls back to the proposed dest when the plugin
            // did not rewrite the path; dest itself is never mutated.
            'dest' => $event->getFinalPath(),
            'processed' => $event->isProcessed(),
        ];
    }

    /**
     * Dispatch the post-process notification event (side-effects only).
     *
     * @param string $source Absolute source path.
     * @param string $dest Absolute destination path that was stored.
     * @param string $origin Media origin / context.
     * @param string $field Logical field name.
     * @param string $color Dominant colour computed for the stored file.
     * @param bool $processed Whether a plugin actually processed the image.
     *
     *
     * @since  1.0.0
     */
    private static function dispatchAfterProcess(string $source, string $dest, string $origin, string $field, string $color, bool $processed): void
    {
        $event = new AfterProcessEvent('onAlfaMediaAfterProcess', [
            'source' => $source,
            'dest' => $dest,
            'origin' => $origin,
            'field' => $field,
            'color' => $color,
            'processed' => $processed,
        ]);

        self::dispatchMedia($event);
    }

    /**
     * Dispatch the pre-delete cleanup event so a plugin can purge derivatives
     * before the component removes the media rows/files.
     *
     * @param object[] $rows Media rows about to be deleted (path, thumbnail, ...).
     *
     *
     * @since  1.0.0
     */
    private static function dispatchBeforeDelete(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

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
            'rows' => $rows,
            'paths' => $paths,
        ]);

        self::dispatchMedia($event);
    }

    /**
     * Raise one media event on the shared dispatcher (2.2.0, design 2.15.3): mode A, the alfa-media group imported
     * first, under Alfa's isolated policy, so every enabled and loaded listener hears it whatever its group, and one
     * that throws is logged while the next runs, as the media pipeline always absorbed a failing plugin.
     *
     * The same event instance travels through every listener in turn, so isValid(), getFinalPath() and isProcessed()
     * accumulate across them.
     *
     * @param AbstractEvent $event The media event.
     *
     * @return AbstractEvent The same event, after every listener has (maybe) mutated it.
     *
     * @since  2.0.0
     */
    private static function dispatchMedia(AbstractEvent $event): AbstractEvent
    {
        $plugins = AlfaPluginHelper::get();
        $plugins->importGroup(self::MEDIA_GROUP);
        $plugins->dispatchIsolated($event);

        return $event;
    }
}
