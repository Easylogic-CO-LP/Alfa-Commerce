<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Maintenance;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Download\Storage;
use Generator;

/**
 * The store of purchased files, as Clear everything removes core's files from it: the one place
 * outside the database where core keeps a shop's files. An extension's files are the extension's to
 * clear (the Clear Data contract), and product images in Joomla's images folder are shared media,
 * kept.
 *
 * WHAT IS REMOVED. Only what core itself put there: the objects of the store's own layout
 * (`aa/bb/<64-hex key>`), and the shard folders they leave empty (`aa`, `aa/bb`). A store can be
 * configured anywhere, so a folder that holds other files is never emptied wholesale: the store root,
 * its guard files, and every other file and folder stay, empty or not.
 *
 * PATH SAFETY. The place is resolved and checked when it is built, while the configuration that
 * names it still exists, and checked again before anything is removed. Nothing is removed from a
 * place that is a symbolic link, resolves anywhere else than its own path (a link on the way
 * there), is the filesystem root, the site root or a folder that contains it, or one of Joomla's
 * own folders. Inside it no symbolic link is followed: a link is left where it is and reported.
 * Every file that could not be removed is reported with the reason, and so is every file left
 * because it was not core's.
 *
 * @since  2.1.0
 */
final class AlfaFileArea
{
    /**
     * The id of the store of purchased files.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const STORE = 'downloads';

    /**
     * What an entry is when its folder cannot be read: what it holds is unknown, so it is never
     * passed over as clean. An erase fails on it, and the check of a cleared shop counts it.
     *
     * @var string
     *
     * @since  2.1.0
     */
    public const UNREADABLE = 'not readable';

    /**
     * A shard folder of the store's layout, relative to its root: `aa` or `aa/bb`. The only folders
     * an erase removes, and only when they are empty.
     *
     * @var string
     *
     * @since  2.1.0
     */
    private const SHARD_FOLDER = '#^[0-9a-f]{2}(/[0-9a-f]{2})?$#';

    /**
     * Joomla's own folders, relative to the site root: never a place to remove files from.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    private const SYSTEM_FOLDERS = [
        'administrator', 'api', 'cache', 'cli', 'components', 'images', 'includes', 'language', 'layouts',
        'libraries', 'logs', 'media', 'modules', 'plugins', 'templates', 'tmp', 'media/com_alfa',
    ];

    /**
     * @param string $id {@see self::STORE}.
     * @param string|null $path The absolute path, or null when the shop never had this place.
     * @param string|null $refused Why nothing may be removed from it, or null when its files may be.
     *
     * @since  2.1.0
     */
    private function __construct(
        public readonly string $id,
        public readonly ?string $path,
        public readonly ?string $refused,
    ) {
    }

    /**
     * The store of purchased files, where the Options or the recorded root say it is.
     *
     * @since  2.1.0
     */
    public static function downloadStore(): self
    {
        return self::checked(id: self::STORE, path: Storage::currentRoot());
    }

    /**
     * The place a run recorded before it removed the configuration, checked again now.
     *
     * @param array{id: string, path: string|null} $state What {@see self::toState()} gave.
     *
     * @since  2.1.0
     */
    public static function fromState(array $state): self
    {
        $path = $state['path'] ?? null;

        return self::checked(id: self::STORE, path: \is_string($path) ? $path : null);
    }

    /**
     * What a run records about this place before anything is removed.
     *
     * @return array{id: string, path: string|null, refused: string|null}
     *
     * @since  2.1.0
     */
    public function toState(): array
    {
        return ['id' => $this->id, 'path' => $this->path, 'refused' => $this->refused];
    }

    /**
     * What the place holds: the files that would be removed (count and bytes) and the files that
     * would be left, with why.
     *
     * @return array{files: int, bytes: int, left: list<array{path: string, reason: string}>}
     *
     * @since  2.1.0
     */
    public function inspect(): array
    {
        $found = ['files' => 0, 'bytes' => 0, 'left' => []];

        if ($this->path === null || $this->refused !== null || !is_dir($this->path)) {
            return $found;
        }

        foreach ($this->walk() as [$path, $kind]) {
            if ($kind === 'own') {
                $found['files']++;
                $found['bytes'] += (int) (@lstat($path)['size'] ?? 0);
            } elseif ($kind !== 'guard' && $kind !== 'folder') {
                $found['left'][] = ['path' => $path, 'reason' => $kind];
            }
        }

        return $found;
    }

    /**
     * Remove core's objects from the store, then the shard folders they leave empty. A folder that
     * cannot be read is a failure: what it holds may be core's objects.
     *
     * @return array{deleted: int, bytes: int, failed: list<array{path: string, reason: string}>, left: list<array{path: string, reason: string}>}
     *
     * @since  2.1.0
     */
    public function erase(): array
    {
        $done = ['deleted' => 0, 'bytes' => 0, 'failed' => [], 'left' => []];

        if ($this->path === null || $this->refused !== null || !is_dir($this->path)) {
            return $done;
        }

        $folders = [];

        foreach ($this->walk() as [$path, $kind]) {
            if ($kind === 'folder') {
                $folders[] = $path;

                continue;
            }

            if ($kind === self::UNREADABLE) {
                $done['failed'][] = ['path' => $path, 'reason' => $kind];

                continue;
            }

            if ($kind !== 'own') {
                if ($kind !== 'guard') {
                    $done['left'][] = ['path' => $path, 'reason' => $kind];
                }

                continue;
            }

            // Asked again at the last moment: never unlink something that became a link.
            if (is_link($path) || !is_file($path)) {
                $done['left'][] = ['path' => $path, 'reason' => 'symbolic link'];

                continue;
            }

            $size = (int) (@lstat($path)['size'] ?? 0);
            error_clear_last();

            if (@unlink($path)) {
                $done['deleted']++;
                $done['bytes'] += $size;
            } else {
                $done['failed'][] = ['path' => $path, 'reason' => (string) (error_get_last()['message'] ?? 'not removed')];
            }
        }

        // Deepest first; only a shard folder of the layout, and only when empty: a folder of the host
        // stays even when it is empty, and a folder that still holds something is reported above.
        usort($folders, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($folders as $folder) {
            if (
                preg_match(self::SHARD_FOLDER, substr($folder, \strlen($this->path) + 1)) === 1
                && !is_link($folder) && is_dir($folder) && (scandir($folder) ?: ['x', 'y', 'z']) === ['.', '..']
            ) {
                @rmdir($folder);
            }
        }

        return $done;
    }

    /**
     * Every entry under the place, never through a symbolic link, each with what it is: `own` (an
     * object of the store's layout), `guard` (a guard file of the store root), `folder`, `symbolic
     * link`, {@see self::UNREADABLE} (a folder that cannot be read), or `not a file of Alfa`
     * (anything else).
     *
     * @return Generator<int, array{0: string, 1: string}>
     *
     * @since  2.1.0
     */
    private function walk(): Generator
    {
        $pending = [''];

        while ($pending !== []) {
            $relativeDir = array_pop($pending);
            $dir = $this->path . ($relativeDir === '' ? '' : '/' . $relativeDir);
            $entries = @scandir($dir);

            if ($entries === false) {
                yield [$dir, self::UNREADABLE];

                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relative = $relativeDir === '' ? $entry : $relativeDir . '/' . $entry;
                $path = $this->path . '/' . $relative;

                if (is_link($path)) {
                    yield [$path, 'symbolic link'];
                } elseif (is_dir($path)) {
                    $pending[] = $relative;

                    yield [$path, 'folder'];
                } elseif ($relativeDir === '' && \in_array($entry, Storage::GUARD_FILES, true)) {
                    yield [$path, 'guard'];
                } elseif (Storage::isObjectPath(relative: $relative)) {
                    yield [$path, 'own'];
                } else {
                    yield [$path, 'not a file of Alfa'];
                }
            }
        }
    }

    /**
     * Build a place and check it.
     *
     * @param string $id The place's id.
     * @param string|null $path Its absolute path, or null when there is none.
     *
     * @since  2.1.0
     */
    private static function checked(string $id, ?string $path): self
    {
        if ($path === null || trim($path) === '') {
            return new self(id: $id, path: null, refused: null);
        }

        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        return new self(id: $id, path: $path === '' ? '/' : $path, refused: self::refusal(path: $path === '' ? '/' : $path));
    }

    /**
     * Why no file may be removed from a path, or null when Alfa's files may be.
     *
     * @param string $path The absolute path, normalised.
     *
     * @since  2.1.0
     */
    private static function refusal(string $path): ?string
    {
        if (!preg_match('#^(/|[A-Za-z]:/)#', $path)) {
            return 'not an absolute path';
        }

        if (\in_array('..', explode('/', $path), true) || \in_array('.', explode('/', $path), true)) {
            return 'the path climbs out of itself (.. or .)';
        }

        if (is_link($path)) {
            return 'it is a symbolic link (' . (string) @readlink($path) . ')';
        }

        $real = file_exists($path) ? realpath($path) : false;

        if ($real !== false && str_replace('\\', '/', $real) !== $path) {
            return 'it resolves to another place (' . str_replace('\\', '/', $real) . ')';
        }

        $site = self::siteRoot();
        $checked = $real !== false ? str_replace('\\', '/', $real) : $path;

        if ($checked === '/' || preg_match('#^[A-Za-z]:/?$#', $checked)) {
            return 'it is the root of the filesystem';
        }

        if ($checked === $site || str_starts_with($site . '/', $checked . '/')) {
            return 'it is the site root or a folder that contains it';
        }

        foreach (self::SYSTEM_FOLDERS as $folder) {
            if ($checked === $site . '/' . $folder) {
                return 'it is a folder of Joomla (' . $folder . ')';
            }
        }

        return null;
    }

    /**
     * The site root, resolved and with forward slashes.
     *
     * @since  2.1.0
     */
    private static function siteRoot(): string
    {
        return rtrim(str_replace('\\', '/', (string) (realpath(JPATH_ROOT) ?: JPATH_ROOT)), '/');
    }
}
