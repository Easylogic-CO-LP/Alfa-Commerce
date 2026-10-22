<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Download;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use RuntimeException;

/**
 * Storage
 *
 * The ONLY class that knows where downloadable-product objects live and how
 * their paths are built. Everything else (admin upload panel, the site
 * download endpoint, grant snapshots) speaks in opaque `storage_key` ids and
 * goes through here, so the sharding layout (or the whole backend) can
 * change in exactly one place.
 *
 * Layout (local driver): `<root>/aa/bb/<key>` where aa/bb are the first two
 * byte-pairs of the 64-hex-char key. Directories are created on demand.
 * Objects carry NO extension and an unguessable random name; the real
 * filename/MIME live in the DB rows.
 *
 * Root: the store's absolute path is recorded among the Alfa tables, as
 * `downloads_root` in `#__alfa_meta`, and that row is what every caller
 * resolves through. An install records it when no row exists: the Options
 * path if one is set, else `<site root>/alfa-downloads`, a folder outside
 * every extension folder, so uninstalling the component leaves the files
 * together with the rows that point at them. The `downloads_storage_path`
 * Option shows the recorded path: a path saved there is recorded the first
 * time the store is used, and an install writes the recorded path back
 * into it when a reinstall has reset the Options ({@see self::provisionRoot()}).
 * Moving the store moves no file. The root is provisioned with deny-all
 * `.htaccess` + `web.config` + blank `index.html`, so a store inside the
 * web root never serves a byte directly on Apache, LiteSpeed or IIS.
 *
 * GC invariant: NOTHING deletes storage objects directly. Admin
 * replace/delete operations touch DB rows only; the object itself is removed
 * exclusively by deleteIfOrphaned(), which verifies that no
 * `#__alfa_item_downloads` row and no `#__alfa_order_downloads` snapshot
 * still references the key. One rule, no exceptions: a referenced object
 * can never disappear and an orphaned one can always be reclaimed.
 *
 * @since  2.0.0
 */
class Storage
{
    /**
     * Storage driver key for the local sharded filesystem store.
     */
    public const DISK_LOCAL = 'local';

    /**
     * Read-buffer size for streaming, in bytes.
     */
    public const CHUNK_SIZE = 65536;

    /**
     * The `#__alfa_meta` row that holds the store's absolute path.
     */
    public const META_ROOT = 'downloads_root';

    /**
     * The store's folder in the site root, used when an install finds no
     * recorded path and no Options path.
     */
    public const DEFAULT_FOLDER = 'alfa-downloads';

    /**
     * The recorded root, read once per request; false = not read yet.
     */
    private static string|null|false $recordedRoot = false;

    /**
     * Store an uploaded file as a new immutable object.
     *
     * Generates a random 64-hex key, shards it into the store and captures
     * the metadata the DB rows need. The source is MOVED (upload tmp files
     * must not linger in a public tmp dir).
     *
     * @param string $sourcePath Absolute path of the file to ingest
     *                           (typically the PHP upload tmp_name).
     * @param bool $isUpload True when $sourcePath is an HTTP upload;
     *                       uses move_uploaded_file() for the built-in
     *                       origin check, plain rename() otherwise.
     *
     * @return object{storage_disk: string, storage_key: string, sha256: string, file_size: int, mime_type: string}
     *
     * @throws RuntimeException When the source is unreadable or the store is not writable.
     *
     * @since  2.0.0
     */
    public static function put(string $sourcePath, bool $isUpload = true): object
    {
        if (!is_readable($sourcePath)) {
            throw new RuntimeException('Download source file is not readable.');
        }

        $key = hash(algo: 'sha256', data: random_bytes(32));
        $targetPath = self::pathFor(key: $key, provision: true);

        $sha256 = hash_file(algo: 'sha256', filename: $sourcePath);
        $fileSize = (int) filesize($sourcePath);
        $mimeType = self::detectMime(path: $sourcePath);

        $moved = $isUpload
            ? move_uploaded_file(from: $sourcePath, to: $targetPath)
            : rename(from: $sourcePath, to: $targetPath);

        if (!$moved) {
            throw new RuntimeException('Could not move the file into the download store.');
        }

        @chmod($targetPath, 0o640);

        return (object) [
            'storage_disk' => self::DISK_LOCAL,
            'storage_key' => $key,
            'sha256' => $sha256,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Deliver an object to the HTTP client and end the request.
     *
     * THE serving API: callers hand over a file descriptor after their own
     * authorization/claim gates and this method owns everything about
     * delivery: headers, transport, termination. That boundary is what lets
     * a future driver swap PHP streaming for X-Sendfile / X-Accel-Redirect /
     * object-storage presigned redirects without touching any caller.
     *
     * Local driver transport: chunked fread/echo with the session closed
     * first (a slow download must not lock the user's other requests) and
     * output buffers drained.
     *
     * @param object $file Descriptor: {storage_disk, storage_key, mime_type,
     *                     display_filename}, the shape of both
     *                     `#__alfa_item_downloads` rows and entitlement
     *                     snapshots.
     *
     * @return bool False when the object cannot be opened (nothing sent yet,
     *              caller may still respond). On success the method streams
     *              and CLOSES the application; it never returns.
     *
     * @since  2.0.0
     */
    public static function serve(object $file): bool
    {
        $disk = (string) ($file->storage_disk ?? self::DISK_LOCAL);
        $key = (string) ($file->storage_key ?? '');

        if (!self::exists(disk: $disk, key: $key)) {
            return false;
        }

        $handle = @fopen(self::pathFor(key: $key), 'rb');

        if (!$handle) {
            return false;
        }

        $app = Factory::getApplication();

        // ASCII fallback + RFC 5987 UTF-8 name, quotes/CR/LF stripped so the
        // stored filename can never break out of the header.
        $filename = str_replace(
            ['"', "\r", "\n"],
            '',
            (string) ($file->display_filename ?? '') !== '' ? (string) $file->display_filename : 'download',
        );
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'download';

        $size = self::size(disk: $disk, key: $key);

        $app->getSession()->close();

        $app->clearHeaders();
        $app->setHeader('Content-Type', (string) ($file->mime_type ?? '') ?: 'application/octet-stream', true);
        $app->setHeader(
            'Content-Disposition',
            'attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            true,
        );
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);

        if ($size !== null) {
            $app->setHeader('Content-Length', (string) $size, true);
        }

        $app->sendHeaders();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);

            if ($chunk === false) {
                break;
            }

            echo $chunk;
            flush();

            if (connection_aborted()) {
                break;
            }
        }

        fclose($handle);
        $app->close();

        return true;
    }

    /**
     * Whether an object exists in the store.
     *
     * @param string $disk Storage driver key.
     * @param string $key 64-hex object id.
     *
     * @since  2.0.0
     */
    public static function exists(string $disk, string $key): bool
    {
        if ($disk !== self::DISK_LOCAL || !self::isValidKey(key: $key)) {
            return false;
        }

        return is_file(self::pathFor(key: $key));
    }

    /**
     * Object size in bytes, or null when missing.
     *
     * @param string $disk Storage driver key.
     * @param string $key 64-hex object id.
     *
     * @since  2.0.0
     */
    public static function size(string $disk, string $key): ?int
    {
        if (!self::exists(disk: $disk, key: $key)) {
            return null;
        }

        $size = filesize(self::pathFor(key: $key));

        return $size === false ? null : (int) $size;
    }

    /**
     * Delete an object IF AND ONLY IF nothing references it any more.
     *
     * This is the single deletion path of the store (GC invariant). Callers
     * invoke it opportunistically after removing a DB reference; when the key
     * is still referenced anywhere (another item file row or any order
     * entitlement snapshot), the object stays untouched.
     *
     * @param string $disk Storage driver key.
     * @param string $key 64-hex object id.
     *
     * @return bool True when the object was deleted, false when kept (still
     *              referenced, already gone, or invalid key).
     *
     * @since  2.0.0
     */
    public static function deleteIfOrphaned(string $disk, string $key): bool
    {
        if ($disk !== self::DISK_LOCAL || !self::isValidKey(key: $key)) {
            return false;
        }

        if (self::isReferenced(key: $key)) {
            return false;
        }

        $path = self::pathFor(key: $key);

        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Whether any DB row still references a storage key.
     *
     * Checks both reference holders: the catalog files
     * (`#__alfa_item_downloads.storage_key`) and the frozen entitlement
     * snapshots (`#__alfa_order_downloads.storage_key`).
     *
     * On a DB error this returns TRUE (treat as referenced): the failure
     * mode must always be "keep the object", never "leak a deletion".
     *
     * @param string $key 64-hex object id.
     *
     * @since  2.0.0
     */
    public static function isReferenced(string $key): bool
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            foreach (['#__alfa_item_downloads', '#__alfa_order_downloads'] as $table) {
                $query = $db->getQuery(true)
                    ->select('1')
                    ->from($db->quoteName($table))
                    ->where($db->quoteName('storage_key') . ' = :key')
                    ->bind(':key', $key)
                    ->setLimit(1);

                $db->setQuery(query: $query);

                if ($db->loadResult()) {
                    return true;
                }
            }
        } catch (Exception $e) {
            Log::add(
                entry:    'Storage::isReferenced failed: ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.downloads',
            );

            return true;
        }

        return false;
    }

    /**
     * The files the store root holds for its protection, which no object is ever named like.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const GUARD_FILES = ['.htaccess', 'web.config', 'index.html'];

    /**
     * The root {@see self::root()} resolves to, without recording or creating anything: the
     * Options path when one is set, else the recorded root, else null (no store was ever set up).
     *
     * For Clear everything, which has to know where the store is before the configuration that
     * says so is removed.
     *
     * @since  2.1.0
     */
    public static function currentRoot(): ?string
    {
        $configured = rtrim(trim((string) ComponentHelper::getParams('com_alfa')->get('downloads_storage_path', '')), '/\\');

        return $configured !== '' ? $configured : self::recordedRoot();
    }

    /**
     * Whether a path relative to the store root is the place of an object: `aa/bb/<key>`, the
     * two byte-pairs of a 64-hex key and the key. Everything else in a store root (its guard
     * files, anything a person put there) is not an object of the store.
     *
     * @param string $relative A path relative to the store root, with forward slashes.
     *
     * @since  2.1.0
     */
    public static function isObjectPath(string $relative): bool
    {
        return (bool) preg_match('#^([a-f0-9]{2})/([a-f0-9]{2})/\1\2[a-f0-9]{60}$#', $relative);
    }

    /**
     * Forget the recorded root this request read, so the next read asks the database again.
     * Clear everything calls it once it has removed the table that recorded it.
     *
     * @since  2.1.0
     */
    public static function forgetRecordedRoot(): void
    {
        self::$recordedRoot = false;
    }

    /**
     * Absolute store root, provisioned with protection files on first use.
     *
     * The recorded root ({@see self::META_ROOT}), unless the Options name a
     * different path: the shop moved the store, and that path is recorded.
     *
     * @throws RuntimeException When no root is recorded, or it cannot be
     *                          created or written.
     *
     * @since  2.0.0
     */
    public static function root(): string
    {
        $root = self::settleRoot(
            configured: (string) ComponentHelper::getParams('com_alfa')->get('downloads_storage_path', ''),
            default:    null,
        );

        self::ensureRoot(root: $root);

        return $root;
    }

    /**
     * Install and update: settle the store root, recording
     * `<site root>/alfa-downloads` when neither a recorded path nor an
     * Options path exists, and create it with its protection files.
     *
     * @param string $configured The `downloads_storage_path` Option as stored
     *                           (empty after a reinstall reset the Options).
     *
     * @return string The recorded root, for the caller to show in the Options.
     *
     * @throws RuntimeException When the root cannot be recorded, created or written.
     *
     * @since  2.0.0
     */
    public static function provisionRoot(string $configured): string
    {
        $root = self::settleRoot(configured: $configured, default: JPATH_ROOT . '/' . self::DEFAULT_FOLDER);

        self::ensureRoot(root: $root);

        return $root;
    }

    /**
     * The one rule both {@see self::root()} and {@see self::provisionRoot()}
     * follow: a non-empty Options path that differs from the recorded one is
     * recorded; otherwise the recorded path stands; with neither, the default
     * is recorded when the caller gives one.
     *
     * @param string $configured The `downloads_storage_path` Option.
     * @param string|null $default The path to record when none is, or null
     *                             when only an install may choose one.
     *
     * @throws RuntimeException When nothing is recorded and no default is given.
     *
     * @since  2.0.0
     */
    private static function settleRoot(string $configured, ?string $default): string
    {
        $configured = rtrim(trim($configured), '/\\');
        $recorded = self::recordedRoot();

        if ($configured !== '' && $configured !== $recorded) {
            self::recordRoot(root: $configured, replace: $recorded !== null);

            return $configured;
        }

        if ($recorded !== null) {
            return $recorded;
        }

        if ($default === null) {
            throw new RuntimeException('The download store has no recorded location: install Alfa Commerce again to create it.');
        }

        self::recordRoot(root: $default, replace: false);

        return $default;
    }

    /**
     * The recorded root, or null when no row holds one.
     *
     * @since  2.0.0
     */
    private static function recordedRoot(): ?string
    {
        if (self::$recordedRoot === false) {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $value = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('value'))
                    ->from($db->quoteName('#__alfa_meta'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_ROOT)),
            )->loadResult();

            self::$recordedRoot = $value === null || $value === '' ? null : (string) $value;
        }

        return self::$recordedRoot;
    }

    /**
     * Record the root in `#__alfa_meta`.
     *
     * @param string $root Absolute store root.
     * @param bool $replace True when a row exists already.
     *
     * @since  2.0.0
     */
    private static function recordRoot(string $root, bool $replace): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->createQuery();

        if ($replace) {
            $query->update($db->quoteName('#__alfa_meta'))
                ->set($db->quoteName('value') . ' = ' . $db->quote($root))
                ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_ROOT));
        } else {
            $query->insert($db->quoteName('#__alfa_meta'))
                ->columns($db->quoteName(['name', 'value']))
                ->values($db->quote(self::META_ROOT) . ', ' . $db->quote($root));
        }

        $db->setQuery($query)->execute();

        self::$recordedRoot = $root;
    }

    /**
     * Create the root when missing and provision its protection files.
     *
     * @param string $root Absolute store root.
     *
     * @throws RuntimeException When the root cannot be created or written.
     *
     * @since  2.0.0
     */
    private static function ensureRoot(string $root): void
    {
        if (!is_dir($root) && !@mkdir($root, 0o750, true)) {
            throw new RuntimeException('Download store root cannot be created: ' . $root);
        }

        if (!is_writable($root)) {
            throw new RuntimeException('Download store root is not writable: ' . $root);
        }

        self::provisionProtection(root: $root);
    }

    /**
     * Absolute object path for a key: `<root>/aa/bb/<key>`.
     *
     * @param string $key 64-hex object id.
     * @param bool $provision Create the shard directories when missing
     *                        (true only on the write path).
     *
     * @throws RuntimeException On invalid keys: malformed input must never
     *                          be able to shape a filesystem path.
     *
     * @since  2.0.0
     */
    protected static function pathFor(string $key, bool $provision = false): string
    {
        if (!self::isValidKey(key: $key)) {
            throw new RuntimeException('Invalid download storage key.');
        }

        $shardDir = self::root() . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2);

        if ($provision && !is_dir($shardDir) && !@mkdir($shardDir, 0o750, true)) {
            throw new RuntimeException('Download store shard cannot be created.');
        }

        return $shardDir . '/' . $key;
    }

    /**
     * Keys are exactly 64 lowercase hex chars; anything else is rejected
     * before it can touch a path.
     *
     * @param string $key Candidate key.
     *
     * @since  2.0.0
     */
    protected static function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $key);
    }

    /**
     * Drop deny-all `.htaccess`, `web.config` and a blank `index.html` into
     * the store root when absent, so an in-webroot store never serves
     * objects directly (Apache/IIS). On nginx the unguessable extensionless
     * names are the fallback line of defence.
     *
     * @param string $root Absolute store root.
     *
     * @since  2.0.0
     */
    protected static function provisionProtection(string $root): void
    {
        $guards = [
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n",
            'index.html' => '',
        ];

        foreach ($guards as $file => $content) {
            $path = $root . '/' . $file;

            if (!is_file($path)) {
                @file_put_contents($path, $content);
            }
        }
    }

    /**
     * Best-effort MIME detection with a safe fallback.
     *
     * @param string $path Absolute file path.
     *
     * @since  2.0.0
     */
    protected static function detectMime(string $path): string
    {
        $mime = false;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
            }
        }

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
