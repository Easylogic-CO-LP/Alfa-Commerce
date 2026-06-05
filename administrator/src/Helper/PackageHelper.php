<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use FilesystemIterator;
use Joomla\CMS\Factory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SimpleXMLElement;
use SplFileInfo;
use ZipArchive;

/**
 * Developer packaging helper — re-assembles the com_alfa extension from a live
 * Joomla installation back into the canonical "repo" layout and zips it.
 *
 * During development the extension's files are scattered across the Joomla tree
 * (`components/com_alfa/`, `administrator/components/com_alfa/`, `media/com_alfa/`,
 * `plugins/<group>/<name>/`, `media/plg_*`, `language/<tag>/…`, etc.). The
 * GitHub-tracked source repo has a very different, self-contained shape (admin /
 * site / api as flat folders at the root, with each plugin's and module's media
 * and language files co-located inside it).
 *
 * This helper reads the component manifest (`alfa.xml`) as the single source of
 * truth, walks every component / plugin / module / language / media area it
 * declares, and copies each one back to its repo location inside a temporary
 * working directory, then zips that directory. The produced archive extracts to
 * the exact structure of the source repo, which makes it trivial to diff against
 * the repo for a pull request.
 *
 * Bundled libraries (`<libraries>` in the manifest) are intentionally NOT included:
 * Joomla runs each library's `script.php` once at install and never persists it,
 * so a live install cannot reproduce a complete, installable library folder. The
 * archive is therefore a PR artifact by default; to install/test it, the library
 * folders must be added by hand from the repo (see {@see self::describeLibraries()}).
 *
 * The reverse path → repo mapping mirrors the standalone `alfa-diffs` tool, run
 * one-way (install → repo) instead of as a diff.
 *
 * @since  1.0.3
 */
class PackageHelper
{
    /**
     * File / folder names skipped wherever they appear (VCS metadata, IDE config,
     * OS cruft) — never shipped in a package.
     *
     * @var string[]
     * @since 1.0.3
     */
    private const IGNORE_NAMES = ['.DS_Store', '.git', '.idea', '.vscode', '.svn', 'Thumbs.db'];

    /**
     * Folder/file name suffixes treated as local backups and skipped.
     *
     * @var string[]
     * @since 1.0.3
     */
    private const IGNORE_SUFFIXES = ['_BK', '_bk', '.bak', '~'];

    /**
     * Auto-created runtime data and dev/build-only files: never shipped (they are
     * regenerated at runtime or only used to build the component) and not flagged
     * as manifest drift even when present on disk. Empty for com_alfa — the install
     * currently has no such files. Add names here if any appear, e.g.
     * 'logs', 'debug_data', 'composer.json', 'composer.lock'.
     *
     * @var string[]
     * @since 1.0.3
     */
    private const IGNORE_RUNTIME = [];

    /**
     * Build the installable/PR package archive from a live install.
     *
     * Reads the manifest, re-assembles the repo-layout tree in a temporary
     * working directory, zips it, removes the working directory, and returns the
     * path to the finished archive (the caller is responsible for streaming it to
     * the browser and deleting it afterwards).
     *
     * @param string $installRoot Absolute path to the Joomla installation root (JPATH_ROOT).
     * @param string[]|null $onlyRelPaths When non-null, restrict the package to exactly these
     *                                    LIVE root-relative file paths (no leading slash) — the
     *                                    same keys {@see self::enumerateShippedFiles()} returns
     *                                    (e.g. from an integrity scan). Each one is copied into the
     *                                    repo layout via that map's `repo` destination, so the
     *                                    archive has the SAME structure as the full export but holds
     *                                    only these files. Paths not in the map (undeclared/added
     *                                    files) are skipped. null (the default) ships the complete
     *                                    package — existing callers are unaffected.
     *
     * @return array{zip: string, filename: string, version: string} The archive
     *                                                               path on disk, the suggested download filename, and the manifest version.
     *
     * @throws RuntimeException If the manifest is missing/invalid or zipping fails.
     *
     * @since   1.0.3
     */
    public static function buildPackageZip(string $installRoot, ?array $onlyRelPaths = null): array
    {
        $component = 'com_alfa';
        $manifestPath = $installRoot . '/administrator/components/' . $component . '/alfa.xml';
        $manifest = self::parseManifest(manifestPath: $manifestPath);

        $tmpDir = self::resolveTmpDir(installRoot: $installRoot);
        self::sweepStaleArchives(tmpDir: $tmpDir);

        $workDir = $tmpDir . '/alfa-package-' . bin2hex(random_bytes(6));
        $version = $manifest['version'] !== '' ? $manifest['version'] : 'dev';
        $filename = $component . '-' . $version . '.zip';
        $zipPath = $tmpDir . '/' . $component . '-' . $version . '-' . bin2hex(random_bytes(4)) . '.zip';

        try {
            if ($onlyRelPaths === null) {
                // Full export: assemble the entire repo-layout tree.
                self::buildTree(installRoot: $installRoot, manifest: $manifest, destRoot: $workDir);
            } else {
                // Changed-only export: resolve every requested LIVE path through the
                // shipped-files map and copy it to its repo destination, reproducing
                // buildTree's layout for just those files.
                $map = self::enumerateShippedFiles(installRoot: $installRoot);

                foreach ($onlyRelPaths as $rel) {
                    if (!isset($map[$rel])) {
                        // Undeclared/added file: not part of the package. It must be
                        // declared in the manifest first (see the contributing guide).
                        continue;
                    }

                    self::copyFile(
                        source: $map[$rel]['abs'],
                        dest:   $workDir . '/' . $map[$rel]['repo'],
                    );
                }
            }

            self::zipTree(sourceDir: $workDir, zipPath: $zipPath);
        } finally {
            self::rrmdir(dir: $workDir);
        }

        return ['zip' => $zipPath, 'filename' => $filename, 'version' => $version];
    }

    /**
     * Fingerprint every shipped file — sha256 hash + size + mtime — keyed by the
     * file's live root-relative path. Built on {@see self::enumerateShippedFiles()}
     * so it covers exactly the package's file set, and uses ONLY native PHP (no
     * Joomla runtime), so the standalone signing CLI can reuse it without
     * bootstrapping the CMS. Unreadable files are skipped.
     *
     * @param string $installRoot Absolute path to the Joomla installation root.
     *
     * @return array<string, array{h: string, s: int, m: int}> Live root-relative path
     *                                                         => {h: sha256, s: size, m: mtime}.
     *
     * @since   1.0.5
     */
    public static function hashShippedFiles(string $installRoot): array
    {
        return self::hashEntries(entries: self::enumerateShippedFiles(installRoot: $installRoot));
    }

    /**
     * SHA-256 every entry of an enumeration ({@see enumerateShippedFiles} or
     * {@see enumerateFromPackage}), keyed by its live root-relative path. The single
     * hashing path both the install-hash and the installable-hash run through, so a
     * checksum signed from a zip is byte-identical to one signed from a live install.
     *
     * @param array<string, array{abs: string, repo: string}> $entries liveRel => {abs, …}.
     *
     * @return array<string, array{h: string, s: int, m: int}> liveRel => {sha256, size, mtime}.
     *
     * @since   1.0.5
     */
    private static function hashEntries(array $entries): array
    {
        $out = [];

        foreach ($entries as $rel => $entry) {
            $abs = $entry['abs'];

            if (!is_file($abs) || !is_readable($abs)) {
                continue;
            }

            $hash = @hash_file('sha256', $abs);

            if ($hash === false) {
                continue;
            }

            $out[$rel] = [
                'h' => $hash,
                's' => (int) filesize($abs),
                'm' => (int) filemtime($abs),
            ];
        }

        return $out;
    }

    /**
     * Hash an INSTALLABLE zip into the same live-keyed checksum map a real install
     * produces — so a release can be signed straight from its package, with no throwaway
     * Joomla install. The zip is extracted to a temp dir and walked by
     * {@see enumerateFromPackage} (which keys every file by its post-install path via the
     * SAME manifest mapping the verifier uses); files are then SHA-256'd in place by the
     * shared {@see hashEntries}. Because Joomla's installer copies files verbatim,
     * hash(package file) == hash(installed file), so the result is identical to
     * {@see hashShippedFiles} on the installed component.
     *
     * @param string $zipPath Absolute path to the installable com_alfa zip (repo layout).
     *
     * @return array<string, array{h: string, s: int, m: int}> liveRel => {sha256, size, mtime}.
     *
     * @throws RuntimeException If zip support is missing or the package can't be read.
     *
     * @since   1.0.5
     */
    public static function hashInstallable(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to hash an installable.');
        }

        if (!is_file($zipPath)) {
            throw new RuntimeException('Installable not found: ' . $zipPath);
        }

        $tmp = sys_get_temp_dir() . '/alfa_pkg_' . bin2hex(random_bytes(6));

        try {
            $zip = new ZipArchive();

            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Cannot open installable: ' . $zipPath);
            }

            if (!@mkdir($tmp, 0o775, true) && !is_dir($tmp)) {
                throw new RuntimeException('Cannot create temp dir: ' . $tmp);
            }

            $zip->extractTo($tmp);
            $zip->close();

            return self::hashEntries(entries: self::enumerateFromPackage(packageRoot: $tmp));
        } finally {
            self::rrmdir(dir: $tmp);
        }
    }

    /**
     * The package-layout twin of {@see enumerateShippedFiles}: walks an extracted
     * installable (repo layout) and returns the SAME liveRel keys, with `abs` pointing
     * at the file inside the package. Every area is mapped to its post-install path with
     * the identical manifest logic, so the two enumerations agree file-for-file.
     *
     * @param string $packageRoot Absolute path to the extracted package root (contains alfa.xml).
     *
     * @return array<string, array{abs: string, repo: string}> liveRel => {abs: package path, repo}.
     *
     * @throws RuntimeException If the manifest is missing or invalid.
     *
     * @since   1.0.5
     */
    public static function enumerateFromPackage(string $packageRoot): array
    {
        $component = 'com_alfa';
        $m = self::parseManifest(manifestPath: $packageRoot . '/alfa.xml');

        $out = [];

        // --- Component code: package <folder> → live components/administrator/api ---
        self::collectDeclaredItems(
            items:    $m['siteItems'],
            absRoot:  $packageRoot . '/' . $m['siteFolder'],
            relRoot:  'components/' . $component,
            repoRoot: $m['siteFolder'],
            out:      $out,
        );

        self::collectDeclaredItems(
            items:    $m['adminItems'],
            absRoot:  $packageRoot . '/' . $m['adminFolder'],
            relRoot:  'administrator/components/' . $component,
            repoRoot: $m['adminFolder'],
            out:      $out,
        );

        if ($m['hasApi']) {
            self::collectDeclaredItems(
                items:    $m['apiItems'],
                absRoot:  $packageRoot . '/' . $m['apiFolder'],
                relRoot:  'api/components/' . $component,
                repoRoot: $m['apiFolder'],
                out:      $out,
            );
        }

        // --- Manifest XML + scriptfile: package ROOT → live admin component folder ---
        self::collectFile(
            abs:  $packageRoot . '/' . $m['manifestFilename'],
            rel:  'administrator/components/' . $component . '/' . $m['manifestFilename'],
            repo: $m['manifestFilename'],
            out:  $out,
        );

        if ($m['scriptfile'] !== '') {
            self::collectFile(
                abs:  $packageRoot . '/' . $m['scriptfile'],
                rel:  'administrator/components/' . $component . '/' . $m['scriptfile'],
                repo: $m['scriptfile'],
                out:  $out,
            );
        }

        // --- Component media: package <mediaFolder> → live media/<dest> ---
        if ($m['hasMedia']) {
            self::collectDeclaredItems(
                items:    $m['mediaItems'],
                absRoot:  $packageRoot . '/' . $m['mediaFolder'],
                relRoot:  'media/' . $m['mediaDest'],
                repoRoot: $m['mediaFolder'],
                out:      $out,
            );
        }

        // --- Component languages: package <langFolder>/<rel> → live (administrator/)language ---
        foreach ($m['siteLangs'] as [$tag, $rel]) {
            self::collectFile(
                abs:  $packageRoot . '/' . ($m['siteLangFolder'] !== '' ? $m['siteLangFolder'] . '/' : '') . $rel,
                rel:  'language/' . $tag . '/' . basename($rel),
                repo: ($m['siteLangFolder'] !== '' ? $m['siteLangFolder'] . '/' : '') . $rel,
                out:  $out,
            );
        }

        foreach ($m['adminLangs'] as [$tag, $rel]) {
            self::collectFile(
                abs:  $packageRoot . '/' . ($m['adminLangFolder'] !== '' ? $m['adminLangFolder'] . '/' : '') . $rel,
                rel:  'administrator/language/' . $tag . '/' . basename($rel),
                repo: ($m['adminLangFolder'] !== '' ? $m['adminLangFolder'] . '/' : '') . $rel,
                out:  $out,
            );
        }

        // --- Sub-extensions: plugins (admin-lang preference) + modules (site-lang) ---
        foreach ($m['plugins'] as [$group, $name]) {
            self::collectSubExtensionFromPackage(
                packageRoot: $packageRoot,
                relDir:      'plugins/' . $group . '/' . $name,
                preferred:   $name,
                langRoots:   ['administrator/language', 'language'],
                out:         $out,
            );
        }

        foreach ($m['modules'] as $module) {
            self::collectSubExtensionFromPackage(
                packageRoot: $packageRoot,
                relDir:      'modules/' . $module,
                preferred:   $module,
                langRoots:   ['language', 'administrator/language'],
                out:         $out,
            );
        }

        return $out;
    }

    /**
     * Package-layout twin of {@see collectSubExtension}: a sub-extension's files are
     * co-located inside its package folder; map them to the scattered live paths the
     * installer creates — code stays put, media → media/<destination>, language INIs →
     * the FIRST of $langRoots (matching the export's search preference, i.e. plugins land
     * in administrator/language, site modules in language).
     *
     * @param string $packageRoot Extracted package root.
     * @param string $relDir Sub-extension dir, e.g. plugins/system/alfasync.
     * @param string $preferred Preferred manifest basename.
     * @param array<int, string> $langRoots Live language roots, most-preferred first.
     * @param array<string, array{abs: string, repo: string}> $out Accumulator (by ref).
     *
     *
     * @since   1.0.5
     */
    private static function collectSubExtensionFromPackage(string $packageRoot, string $relDir, string $preferred, array $langRoots, array &$out): void
    {
        $pkgDir = $packageRoot . '/' . $relDir;
        $manifestPath = self::findSubManifest(dir: $pkgDir, preferredName: $preferred);

        if ($manifestPath === null) {
            return;
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return;
        }

        // Code: declared <files> — same relative path in package and install.
        foreach ($xml->files as $files) {
            $sub = trim((string) $files['folder']);
            $absSub = $sub !== '' ? $pkgDir . '/' . $sub : $pkgDir;
            $relSub = $sub !== '' ? $relDir . '/' . $sub : $relDir;

            self::collectDeclaredItems(
                items:    self::parseFilesItems(node: $files),
                absRoot:  $absSub,
                relRoot:  $relSub,
                repoRoot: $relSub,
                out:      $out,
            );
        }

        // The sub-extension's own manifest (same path in package and install).
        self::collectFile(
            abs:  $manifestPath,
            rel:  $relDir . '/' . basename($manifestPath),
            repo: $relDir . '/' . basename($manifestPath),
            out:  $out,
        );

        // Media: package <relDir>/<folder> → live media/<destination>.
        foreach ($xml->media as $media) {
            $destination = trim((string) $media['destination']);
            $folder = trim((string) $media['folder']) ?: 'media';

            if ($destination !== '') {
                self::collectFiles(
                    absDir:  $pkgDir . '/' . $folder,
                    relDir:  'media/' . $destination,
                    repoDir: $relDir . '/' . $folder,
                    out:     $out,
                );
            }
        }

        // Languages: package <relDir>/<langFolder>/<rel> → live <langRoots[0]>/<tag>/<file>.
        if (isset($xml->languages)) {
            $langFolder = trim((string) $xml->languages['folder']);
            $liveRoot = $langRoots[0];

            foreach ($xml->languages->language as $language) {
                $tag = trim((string) $language['tag']);
                $rel = trim((string) $language);

                if ($tag === '' || $rel === '') {
                    continue;
                }

                self::collectFile(
                    abs:  $pkgDir . '/' . ($langFolder !== '' ? $langFolder . '/' : '') . $rel,
                    rel:  $liveRoot . '/' . $tag . '/' . basename($rel),
                    repo: $relDir . '/' . ($langFolder !== '' ? $langFolder . '/' : '') . $rel,
                    out:  $out,
                );
            }
        }
    }

    /**
     * Enumerate EVERY file the export would ship from a live install, without
     * copying anything. The result is keyed by each file's LIVE root-relative path
     * (no leading slash) — exactly where the file sits on disk — and each value
     * carries BOTH paths the changed-only export needs:
     *
     *   - `abs`  the absolute path on the live install (the source to copy from);
     *   - `repo` the destRoot-RELATIVE path (no leading slash) that
     *            {@see self::buildTree()} writes that file to, i.e. its slot in the
     *            canonical repo layout. `repo` mirrors buildTree's destination
     *            mapping exactly, so copying source→`repo` reproduces the same tree
     *            the full export builds, file for file.
     *
     * The live key and the repo path diverge wherever the repo layout differs from
     * the install layout: component code is re-rooted under the manifest folder
     * names (site/administrator/api), component/sub-extension media and language
     * INIs are pulled out of the install's global media/ and language/ trees and
     * co-located inside the component or sub-extension folder, and the component
     * manifest + scriptfile move to the repo root. Plugin/module CODE keeps the same
     * relative path in both (install == repo for sub-extension code).
     *
     * Missing files/folders are silently skipped, so the map never contains a path
     * that does not exist on disk. Bundled libraries are excluded by design (see
     * the class docblock).
     *
     * @param string $installRoot Absolute path to the Joomla installation root (JPATH_ROOT).
     *
     * @return array<string, array{abs: string, repo: string}> Live root-relative path
     *                                                         => {abs: absolute live path, repo: destRoot-relative repo path}.
     *
     * @throws RuntimeException If the manifest is missing or invalid.
     *
     * @since   1.0.4
     */
    public static function enumerateShippedFiles(string $installRoot): array
    {
        $component = 'com_alfa';
        $adminCompat = $installRoot . '/administrator/components/' . $component;
        $manifestPath = $adminCompat . '/' . 'alfa.xml';
        $m = self::parseManifest(manifestPath: $manifestPath);

        $out = [];

        // --- Component code: site / admin / api declared <files> entries ---
        // Live root-relative key vs repo destination (re-rooted under the manifest
        // folder= names), matching buildTree's copyItems destRoot per area.
        self::collectDeclaredItems(
            items:    $m['siteItems'],
            absRoot:  $installRoot . '/components/' . $component,
            relRoot:  'components/' . $component,
            repoRoot: $m['siteFolder'],
            out:      $out,
        );

        self::collectDeclaredItems(
            items:    $m['adminItems'],
            absRoot:  $adminCompat,
            relRoot:  'administrator/components/' . $component,
            repoRoot: $m['adminFolder'],
            out:      $out,
        );

        if ($m['hasApi']) {
            self::collectDeclaredItems(
                items:    $m['apiItems'],
                absRoot:  $installRoot . '/api/components/' . $component,
                relRoot:  'api/components/' . $component,
                repoRoot: $m['apiFolder'],
                out:      $out,
            );
        }

        // --- Component manifest XML + scriptfile (live in the admin folder, but
        //     buildTree places them at the repo ROOT, so repo = bare filename) ---
        self::collectFile(
            abs:  $adminCompat . '/' . $m['manifestFilename'],
            rel:  'administrator/components/' . $component . '/' . $m['manifestFilename'],
            repo: $m['manifestFilename'],
            out:  $out,
        );

        if ($m['scriptfile'] !== '') {
            self::collectFile(
                abs:  $adminCompat . '/' . $m['scriptfile'],
                rel:  'administrator/components/' . $component . '/' . $m['scriptfile'],
                repo: $m['scriptfile'],
                out:  $out,
            );
        }

        // --- Component media (each declared <media> folder/filename) — live
        //     media/<dest>/<x>, repo <mediaFolder>/<x> per buildTree. ---
        if ($m['hasMedia']) {
            self::collectDeclaredItems(
                items:    $m['mediaItems'],
                absRoot:  $installRoot . '/media/' . $m['mediaDest'],
                relRoot:  'media/' . $m['mediaDest'],
                repoRoot: $m['mediaFolder'],
                out:      $out,
            );
        }

        // --- Component languages: site → language/<tag>/<file>, admin →
        //     administrator/language/<tag>/<file>; repo = <langFolder>/<manifest-rel>. ---
        foreach ($m['siteLangs'] as [$tag, $rel]) {
            $fileName = basename($rel);
            self::collectFile(
                abs:  $installRoot . '/language/' . $tag . '/' . $fileName,
                rel:  'language/' . $tag . '/' . $fileName,
                repo: ($m['siteLangFolder'] !== '' ? $m['siteLangFolder'] . '/' : '') . $rel,
                out:  $out,
            );
        }

        foreach ($m['adminLangs'] as [$tag, $rel]) {
            $fileName = basename($rel);
            self::collectFile(
                abs:  $installRoot . '/administrator/language/' . $tag . '/' . $fileName,
                rel:  'administrator/language/' . $tag . '/' . $fileName,
                repo: ($m['adminLangFolder'] !== '' ? $m['adminLangFolder'] . '/' : '') . $rel,
                out:  $out,
            );
        }

        // --- Plugins (code + media + languages, each from its own manifest) ---
        foreach ($m['plugins'] as [$group, $name]) {
            $dir = $installRoot . '/plugins/' . $group . '/' . $name;
            self::collectSubExtension(
                installRoot:  $installRoot,
                sourceDir:    $dir,
                relSourceDir: 'plugins/' . $group . '/' . $name,
                manifestPath: self::findSubManifest(dir: $dir, preferredName: $name),
                langRoots:    ['administrator/language', 'language'],
                out:          $out,
            );
        }

        // --- Modules (code + media + languages, each from its own manifest) ---
        foreach ($m['modules'] as $module) {
            $dir = $installRoot . '/modules/' . $module;
            self::collectSubExtension(
                installRoot:  $installRoot,
                sourceDir:    $dir,
                relSourceDir: 'modules/' . $module,
                manifestPath: self::findSubManifest(dir: $dir, preferredName: $module),
                langRoots:    ['language', 'administrator/language'],
                out:          $out,
            );
        }

        return $out;
    }

    /**
     * Describe the libraries declared in the manifest, so the UI can tell the
     * developer exactly which folders to add (and from where) for an installable
     * build. Returns an empty array if the manifest declares no libraries.
     *
     * @param string $installRoot Absolute path to the Joomla installation root.
     *
     * @return array<int, array{folder: string, libraryname: string, installCode: string, installManifest: string}>
     *                                                                                                              Per library: the repo folder name, the Joomla library name, and the
     *                                                                                                              install paths of its code and (renamed) manifest, for reference.
     *
     * @since   1.0.3
     */
    public static function describeLibraries(string $installRoot): array
    {
        $manifestPath = $installRoot . '/administrator/components/com_alfa/alfa.xml';

        if (!is_file($manifestPath)) {
            return [];
        }

        try {
            $manifest = self::parseManifest(manifestPath: $manifestPath);
        } catch (RuntimeException) {
            return [];
        }

        $out = [];

        foreach ($manifest['libraries'] as [$folder, $libraryName]) {
            // Joomla stores the code under libraries/<libraryname>/ and the manifest
            // under administrator/manifests/libraries/<last-segment>.xml.
            $out[] = [
                'folder' => $folder,
                'libraryname' => $libraryName,
                'installCode' => 'libraries/' . $libraryName . '/',
                'installManifest' => 'administrator/manifests/libraries/' . $libraryName . '.xml',
            ];
        }

        return $out;
    }

    /**
     * Suggest the NEXT version string after the one declared in the manifest, by
     * incrementing the last numeric segment (e.g. 1.0.4 → 1.0.5). Used by the
     * contribution guide so the SQL-update / version-bump examples always match
     * this install instead of a hard-coded number. Falls back to the current
     * version (or an empty string) when the manifest is unreadable or the version
     * has no trailing numeric segment.
     *
     * @param string $installRoot Absolute path to the Joomla installation root.
     *
     * @return string The suggested next version, or '' if it cannot be determined.
     *
     * @since   1.0.4
     */
    public static function nextVersion(string $installRoot): string
    {
        $manifestPath = $installRoot . '/administrator/components/com_alfa/alfa.xml';

        if (!is_file($manifestPath)) {
            return '';
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return '';
        }

        $current = trim((string) $xml->version);

        if ($current === '') {
            return '';
        }

        $parts = explode('.', $current);
        $last = end($parts);

        if (!ctype_digit($last)) {
            return $current;
        }

        $parts[array_key_last($parts)] = (string) ((int) $last + 1);

        return implode('.', $parts);
    }

    /**
     * Report whether the release artifacts for the CURRENT manifest version exist
     * on disk, so the contribution guide can independently remind the developer to
     * create them. Two artifacts, checked independently:
     *
     *   - the schema update <schemapath>/<version>.sql (path read from the
     *     manifest's <schemas><schemapath>, so it follows whatever the manifest
     *     declares — e.g. sql/updates/mysql);
     *   - the obsolete-file list files/removed/<version>.json.
     *
     * @param string $installRoot Absolute path to the Joomla installation root.
     *
     * @return array{version: string, sqlFile: string, removedFile: string, hasSqlUpdate: bool, hasRemovedJson: bool}
     *                                                                                                                version is '' (and both flags true) when it cannot be
     *                                                                                                                determined, so the UI shows no spurious reminder.
     *
     * @since   1.0.4
     */
    public static function releaseReadiness(string $installRoot): array
    {
        $componentAdmin = $installRoot . '/administrator/components/com_alfa';
        $manifestPath = $componentAdmin . '/alfa.xml';

        $out = [
            'version' => '',
            'sqlFile' => '',
            'removedFile' => '',
            'hasSqlUpdate' => true,
            'hasRemovedJson' => true,
        ];

        if (!is_file($manifestPath)) {
            return $out;
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return $out;
        }

        $version = trim((string) $xml->version);

        if ($version === '') {
            return $out;
        }

        // Schema-update folder comes from the manifest (single source of truth).
        $schemaPath = trim((string) ($xml->update->schemas->schemapath ?? '')) ?: 'sql/updates';
        $sqlRel = $schemaPath . '/' . $version . '.sql';
        $removedRel = 'files/removed/' . $version . '.json';

        $out['version'] = $version;
        $out['sqlFile'] = $sqlRel;
        $out['removedFile'] = $removedRel;
        $out['hasSqlUpdate'] = is_file($componentAdmin . '/' . $sqlRel);
        $out['hasRemovedJson'] = is_file($componentAdmin . '/' . $removedRel);

        return $out;
    }

    /**
     * Detect manifest⇄disk drift for the live install, so the export UI can warn
     * the developer BEFORE shipping a broken package. Two kinds are reported:
     *
     *   - missing:    a path declared in a <files>/<media> block that does NOT
     *                 exist on disk. Joomla's installer aborts on these (this is
     *                 the "site/helpers does not exist" class of failure).
     *   - undeclared: a top-level folder/file present on disk but NOT declared in
     *                 the relevant <files> block. It is silently left out of the
     *                 package and the install — features go missing with no error.
     *
     * Covers the component (site/admin/api code roots + media) and every declared
     * plugin/module. VCS/IDE/OS cruft and backups are ignored. A clean install
     * returns empty arrays.
     *
     * @param string $installRoot Absolute path to the live Joomla root (JPATH_ROOT).
     *
     * @return array{missing: string[], undeclared: string[]}
     *
     * @since   1.0.3
     */
    public static function detectDrift(string $installRoot): array
    {
        $missing = [];
        $undeclared = [];

        $manifestPath = $installRoot . '/administrator/components/com_alfa/alfa.xml';

        if (!is_file($manifestPath)) {
            return ['missing' => $missing, 'undeclared' => $undeclared];
        }

        try {
            $m = self::parseManifest(manifestPath: $manifestPath);
        } catch (RuntimeException) {
            return ['missing' => $missing, 'undeclared' => $undeclared];
        }

        $comp = 'com_alfa';

        // Component code roots: [label, dir, declaredItems, namesToSkipForUndeclared].
        $roots = [
            ['site',  $installRoot . '/components/' . $comp,               $m['siteItems'],  []],
            ['admin', $installRoot . '/administrator/components/' . $comp, $m['adminItems'], array_filter([$m['manifestFilename'], $m['scriptfile']])],
        ];

        if ($m['hasApi']) {
            $roots[] = ['api', $installRoot . '/api/components/' . $comp, $m['apiItems'], []];
        }

        foreach ($roots as [$label, $dir, $items, $extraSkip]) {
            self::driftDir(label: $comp . '/' . $label, dir: $dir, items: $items, extraSkip: $extraSkip, missing: $missing, undeclared: $undeclared);
        }

        // Media: declared-but-missing only — the destination may legitimately hold
        // extra build artefacts, so undeclared media files are not flagged.
        if ($m['hasMedia']) {
            foreach ($m['mediaItems'] as [$type, $name]) {
                $path = $installRoot . '/media/' . $m['mediaDest'] . '/' . $name;

                if ($type === 'folder' ? !is_dir($path) : !is_file($path)) {
                    $missing[] = $comp . '/media: ' . $name;
                }
            }
        }

        // Sub-extensions — each read from its own manifest's <files> block.
        foreach ($m['plugins'] as [$group, $name]) {
            $dir = $installRoot . '/plugins/' . $group . '/' . $name;
            self::driftSub(label: 'plugins/' . $group . '/' . $name, dir: $dir, manifestPath: self::findSubManifest(dir: $dir, preferredName: $name), installRoot: $installRoot, missing: $missing, undeclared: $undeclared);
        }

        foreach ($m['modules'] as $module) {
            $dir = $installRoot . '/modules/' . $module;
            self::driftSub(label: 'modules/' . $module, dir: $dir, manifestPath: self::findSubManifest(dir: $dir, preferredName: $module), installRoot: $installRoot, missing: $missing, undeclared: $undeclared);
        }

        return ['missing' => $missing, 'undeclared' => $undeclared];
    }

    /**
     * Compare a single declared code root against disk: flag declared entries that
     * are absent (→ $missing) and on-disk top-level entries that are not declared
     * and not in $extraSkip (→ $undeclared). Cruft is ignored.
     *
     * @param string $label Human-readable root label.
     * @param string $dir Absolute path of the root on disk.
     * @param array<int, array{0: string, 1: string}> $items Declared entries.
     * @param string[] $extraSkip Extra names exempt from the undeclared check.
     * @param string[] $missing Accumulator (by ref).
     * @param string[] $undeclared Accumulator (by ref).
     *
     * @since   1.0.3
     */
    private static function driftDir(string $label, string $dir, array $items, array $extraSkip, array &$missing, array &$undeclared): void
    {
        if (!is_dir($dir)) {
            // Whole declared root is gone — every declared entry would break install.
            foreach ($items as [, $name]) {
                $missing[] = $label . ': ' . $name;
            }

            return;
        }

        $declared = [];

        foreach ($items as [$type, $name]) {
            $declared[] = $name;
            $path = $dir . '/' . $name;

            if ($type === 'folder' ? !is_dir($path) : !is_file($path)) {
                $missing[] = $label . ': ' . $name;
            }
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || self::isIgnored(name: $entry)) {
                continue;
            }

            if (!\in_array($entry, $declared, true) && !\in_array($entry, $extraSkip, true)) {
                $undeclared[] = $label . ': ' . $entry;
            }
        }
    }

    /**
     * Drift-check a sub-extension (plugin/module) against its own manifest:
     * declared <files> entries that are absent, declared <media> paths that are
     * absent under media/<destination>, and (for plugins/modules) a missing
     * plugin="…"/module="…" element attribute — all three abort the install, so
     * they go to $missing. On-disk top-level entries not declared anywhere go to
     * $undeclared. The manifest XML and the tag-handled media/language folders are
     * exempt from the undeclared check.
     *
     * @param string $label Human-readable extension label.
     * @param string $dir The extension's install folder.
     * @param string|null $manifestPath Its manifest path (or null if not found).
     * @param string $installRoot Joomla root (to resolve media/<destination>).
     * @param string[] $missing Accumulator (by ref).
     * @param string[] $undeclared Accumulator (by ref).
     *
     * @since   1.0.3
     */
    private static function driftSub(string $label, string $dir, ?string $manifestPath, string $installRoot, array &$missing, array &$undeclared): void
    {
        if ($manifestPath === null || !is_dir($dir)) {
            return;
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return;
        }

        $declared = [];

        foreach ($xml->files as $files) {
            $sub = trim((string) $files['folder']);
            $base = $sub !== '' ? $dir . '/' . $sub : $dir;

            foreach (self::parseFilesItems(node: $files) as [$type, $name]) {
                $declared[] = $name;
                $path = $base . '/' . $name;

                if ($type === 'folder' ? !is_dir($path) : !is_file($path)) {
                    $missing[] = $label . ': ' . $name;
                }
            }
        }

        // <media>: declared folders/filenames must exist under media/<destination>,
        // or Joomla aborts the install looking for a path the package never shipped
        // (e.g. a declared css/ folder that was never actually created).
        foreach ($xml->media as $media) {
            $dest = trim((string) $media['destination']);

            if ($dest === '') {
                continue;
            }

            $mediaRoot = $installRoot . '/media/' . $dest;

            foreach (self::parseFilesItems(node: $media) as [$type, $name]) {
                $path = $mediaRoot . '/' . $name;

                if ($type === 'folder' ? !is_dir($path) : !is_file($path)) {
                    $missing[] = $label . ': media/' . $dest . '/' . $name;
                }
            }
        }

        // A plugin/module needs an element attribute (plugin="…" / module="…") on
        // one of its <files> children (folder OR filename) so the installer can
        // derive the extension element; without it the #__extensions insert fails
        // with "Field 'element' doesn't have a default value" and the install is
        // rolled back.
        $elementAttr = match ((string) $xml['type']) {
            'plugin' => 'plugin',
            'module' => 'module',
            default => null,
        };

        if ($elementAttr !== null) {
            $hasElement = false;

            foreach ($xml->files as $files) {
                foreach ($files->children() as $child) {
                    if (trim((string) $child->attributes()->{$elementAttr}) !== '') {
                        $hasElement = true;
                        break 2;
                    }
                }
            }

            if (!$hasElement) {
                $missing[] = $label . ': <files> is missing a ' . $elementAttr . '="…" element attribute';
            }
        }

        // <languages>: each declared INI must live in the install's GLOBAL language
        // tree (Joomla installs every extension's languages there — they are never
        // kept inside the extension). If it is not in language/ or
        // administrator/language/, it won't be packaged and the install aborts.
        if (isset($xml->languages)) {
            foreach (($xml->languages->language ?? []) as $language) {
                $tag = trim((string) $language['tag']);
                $rel = trim((string) $language);

                if ($tag === '' || $rel === '') {
                    continue;
                }

                $fileName = basename($rel);

                if (!is_file($installRoot . '/language/' . $tag . '/' . $fileName)
                    && !is_file($installRoot . '/administrator/language/' . $tag . '/' . $fileName)) {
                    $missing[] = $label . ': language ' . $tag . '/' . $fileName . ' (not in the global language tree)';
                }
            }
        }

        // Manifest XML is shipped explicitly; media/language are handled by their tags.
        $skip = [basename($manifestPath), 'media', 'language', 'languages'];

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || self::isIgnored(name: $entry)) {
                continue;
            }

            if (!\in_array($entry, $declared, true) && !\in_array($entry, $skip, true)) {
                $undeclared[] = $label . ': ' . $entry;
            }
        }
    }

    /**
     * Parse the component manifest into the structured shape the builder needs.
     *
     * @param string $manifestPath Absolute path to alfa.xml.
     *
     * @return array Structured manifest data (folders, languages, plugins,
     *               modules, libraries, version, scriptfile, manifest filename).
     *
     * @throws RuntimeException If the file is missing or not a valid <extension>.
     *
     * @since   1.0.3
     */
    private static function parseManifest(string $manifestPath): array
    {
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Component manifest not found: ' . $manifestPath);
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false || $xml->getName() !== 'extension') {
            throw new RuntimeException('Invalid component manifest: ' . $manifestPath);
        }

        // Folder attributes (with the Joomla defaults if an attribute is absent).
        $siteFolder = (string) ($xml->files['folder'] ?? 'site') ?: 'site';
        $adminFolder = (string) ($xml->administration->files['folder'] ?? 'administrator') ?: 'administrator';
        $apiFolder = (string) ($xml->api->files['folder'] ?? 'api') ?: 'api';
        $mediaFolder = (string) ($xml->media['folder'] ?? 'media') ?: 'media';
        $mediaDest = (string) ($xml->media['destination'] ?? 'com_alfa') ?: 'com_alfa';

        return [
            'manifestFilename' => basename($manifestPath),
            'version' => trim((string) $xml->version),
            'scriptfile' => trim((string) $xml->scriptfile),
            'siteFolder' => $siteFolder,
            'adminFolder' => $adminFolder,
            'apiFolder' => $apiFolder,
            'hasApi' => isset($xml->api->files),
            'hasMedia' => isset($xml->media),
            'mediaFolder' => $mediaFolder,
            'mediaDest' => $mediaDest,
            // Declared <files>/<media> entries — the package mirrors exactly what
            // each <files folder="…">/<media> block lists (manifest-driven), so it
            // matches what Joomla's installer will place. Nothing undeclared ships.
            'siteItems' => self::parseFilesItems(node: $xml->files),
            'adminItems' => self::parseFilesItems(node: $xml->administration->files),
            'apiItems' => self::parseFilesItems(node: $xml->api->files),
            'mediaItems' => self::parseFilesItems(node: $xml->media),
            'siteLangFolder' => (string) ($xml->languages['folder'] ?? ''),
            'siteLangs' => self::parseLanguages(node: $xml->languages),
            'adminLangFolder' => (string) ($xml->administration->languages['folder'] ?? ''),
            'adminLangs' => self::parseLanguages(node: $xml->administration->languages),
            'plugins' => self::parsePlugins(node: $xml->plugins),
            'modules' => self::parseModules(node: $xml->modules),
            'libraries' => self::parseLibraries(node: $xml->libraries),
        ];
    }

    /**
     * Extract declared file entries from a <files>/<media> node: each <folder>
     * and <filename> child, as ['folder'|'file', name] pairs. Drives the
     * manifest-driven copy (only declared paths ship).
     *
     * @param SimpleXMLElement|null $node The <files>/<media> element (or null).
     *
     * @return array<int, array{0: string, 1: string}>
     *
     * @since   1.0.3
     */
    private static function parseFilesItems(?SimpleXMLElement $node): array
    {
        $out = [];

        if ($node === null) {
            return $out;
        }

        foreach (($node->folder ?? []) as $folder) {
            $name = trim((string) $folder);

            if ($name !== '') {
                $out[] = ['folder', $name];
            }
        }

        foreach (($node->filename ?? []) as $file) {
            $name = trim((string) $file);

            if ($name !== '') {
                $out[] = ['file', $name];
            }
        }

        return $out;
    }

    /**
     * Extract [tag, relativeFile] pairs from a <languages> node.
     *
     * @param SimpleXMLElement|null $node The <languages> element (or null).
     *
     * @return array<int, array{0: string, 1: string}>
     *
     * @since   1.0.3
     */
    private static function parseLanguages(?SimpleXMLElement $node): array
    {
        $out = [];

        if ($node === null) {
            return $out;
        }

        foreach ($node->language as $lang) {
            $tag = trim((string) $lang['tag']);
            $rel = trim((string) $lang);

            if ($tag !== '' && $rel !== '') {
                $out[] = [$tag, $rel];
            }
        }

        return $out;
    }

    /**
     * Extract [group, name] pairs from a <plugins> node.
     *
     * @param SimpleXMLElement|null $node The <plugins> element (or null).
     *
     * @return array<int, array{0: string, 1: string}>
     *
     * @since   1.0.3
     */
    private static function parsePlugins(?SimpleXMLElement $node): array
    {
        $out = [];

        if ($node === null) {
            return $out;
        }

        foreach ($node->plugin as $plugin) {
            $group = trim((string) $plugin['group']);
            $name = trim((string) $plugin['plugin']);

            if ($group !== '' && $name !== '') {
                $out[] = [$group, $name];
            }
        }

        return $out;
    }

    /**
     * Extract module names from a <modules> node.
     *
     * @param SimpleXMLElement|null $node The <modules> element (or null).
     *
     * @return string[]
     *
     * @since   1.0.3
     */
    private static function parseModules(?SimpleXMLElement $node): array
    {
        $out = [];

        if ($node === null) {
            return $out;
        }

        foreach ($node->module as $module) {
            $name = trim((string) $module['module']);

            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Extract [folder, libraryname] pairs from a <libraries> node.
     *
     * @param SimpleXMLElement|null $node The <libraries> element (or null).
     *
     * @return array<int, array{0: string, 1: string}>
     *
     * @since   1.0.3
     */
    private static function parseLibraries(?SimpleXMLElement $node): array
    {
        $out = [];

        if ($node === null) {
            return $out;
        }

        foreach ($node->library as $library) {
            $folder = trim((string) $library['folder']);
            $libraryName = trim((string) $library['libraryname']);

            if ($folder !== '' && $libraryName !== '') {
                $out[] = [$folder, $libraryName];
            }
        }

        return $out;
    }

    /**
     * Re-assemble the full repo-layout tree under $destRoot by copying each
     * declared area from its scattered install location.
     *
     * @param string $installRoot Joomla installation root.
     * @param array $manifest Parsed manifest (see {@see self::parseManifest()}).
     * @param string $destRoot Temporary working directory to build into.
     *
     *
     * @since   1.0.3
     */
    private static function buildTree(string $installRoot, array $manifest, string $destRoot): void
    {
        $component = 'com_alfa';
        $adminCompat = $installRoot . '/administrator/components/' . $component;

        // --- Root artifacts: manifest + scriptfile live at the repo root ---
        self::copyFile(
            source: $adminCompat . '/' . $manifest['manifestFilename'],
            dest:   $destRoot . '/' . $manifest['manifestFilename'],
        );

        if ($manifest['scriptfile'] !== '') {
            self::copyFile(
                source: $adminCompat . '/' . $manifest['scriptfile'],
                dest:   $destRoot . '/' . $manifest['scriptfile'],
            );
        }

        // --- Site component (only the folders/files declared in <files folder="site">) ---
        self::copyItems(
            items:    $manifest['siteItems'],
            srcRoot:  $installRoot . '/components/' . $component,
            destRoot: $destRoot . '/' . $manifest['siteFolder'],
        );

        // --- Admin component (manifest + scriptfile already placed at the repo root) ---
        $adminSkip = [$manifest['manifestFilename']];

        if ($manifest['scriptfile'] !== '') {
            $adminSkip[] = $manifest['scriptfile'];
        }

        self::copyItems(
            items:    $manifest['adminItems'],
            srcRoot:  $adminCompat,
            destRoot: $destRoot . '/' . $manifest['adminFolder'],
            skip:     $adminSkip,
        );

        // --- API component (declared <api><files>) ---
        if ($manifest['hasApi']) {
            self::copyItems(
                items:    $manifest['apiItems'],
                srcRoot:  $installRoot . '/api/components/' . $component,
                destRoot: $destRoot . '/' . $manifest['apiFolder'],
            );
        }

        // --- Component media (each declared <media> folder/filename) ---
        if ($manifest['hasMedia']) {
            self::copyItems(
                items:    $manifest['mediaItems'],
                srcRoot:  $installRoot . '/media/' . $manifest['mediaDest'],
                destRoot: $destRoot . '/' . $manifest['mediaFolder'],
            );
        }

        // --- Site languages (install: language/<tag>/<file>) ---
        if ($manifest['siteLangFolder'] !== '') {
            foreach ($manifest['siteLangs'] as [$tag, $rel]) {
                self::copyFile(
                    source: $installRoot . '/language/' . $tag . '/' . basename($rel),
                    dest:   $destRoot . '/' . $manifest['siteLangFolder'] . '/' . $rel,
                );
            }
        }

        // --- Admin languages (install: administrator/language/<tag>/<file>) ---
        if ($manifest['adminLangFolder'] !== '') {
            foreach ($manifest['adminLangs'] as [$tag, $rel]) {
                self::copyFile(
                    source: $installRoot . '/administrator/language/' . $tag . '/' . basename($rel),
                    dest:   $destRoot . '/' . $manifest['adminLangFolder'] . '/' . $rel,
                );
            }
        }

        // --- Plugins ---
        foreach ($manifest['plugins'] as [$group, $name]) {
            self::buildPlugin(installRoot: $installRoot, destRoot: $destRoot, group: $group, name: $name);
        }

        // --- Modules ---
        foreach ($manifest['modules'] as $module) {
            self::buildModule(installRoot: $installRoot, destRoot: $destRoot, module: $module);
        }

        // Libraries are intentionally excluded — see the class docblock.
    }

    /**
     * Re-assemble a single plugin: its code (the whole install folder) plus the media
     * and language files its own manifest declares — see
     * {@see self::copyDeclaredMediaAndLanguages()}.
     *
     * @param string $installRoot Joomla installation root.
     * @param string $destRoot Working directory.
     * @param string $group Plugin group (e.g. "alfa-payments").
     * @param string $name Plugin name (e.g. "standard").
     *
     *
     * @since   1.0.3
     */
    private static function buildPlugin(string $installRoot, string $destRoot, string $group, string $name): void
    {
        $sourceDir = $installRoot . '/plugins/' . $group . '/' . $name;
        $destBase = $destRoot . '/plugins/' . $group . '/' . $name;
        $manifestPath = self::findSubManifest(dir: $sourceDir, preferredName: $name);

        // Code only — media + language folders are reconstructed from the manifest below.
        self::copyDeclaredFiles(sourceDir: $sourceDir, destBase: $destBase, manifestPath: $manifestPath);

        self::copyDeclaredMediaAndLanguages(
            installRoot:  $installRoot,
            manifestPath: $manifestPath,
            destBase:     $destBase,
            // Plugins install their language INIs to the backend tree.
            langRoots:    [$installRoot . '/administrator/language', $installRoot . '/language'],
        );
    }

    /**
     * Re-assemble a single module: its code (the whole install folder) plus the media
     * and language files its own manifest declares.
     *
     * @param string $installRoot Joomla installation root.
     * @param string $destRoot Working directory.
     * @param string $module Module name (e.g. "mod_alfa_cart").
     *
     *
     * @since   1.0.3
     */
    private static function buildModule(string $installRoot, string $destRoot, string $module): void
    {
        $sourceDir = $installRoot . '/modules/' . $module;
        $destBase = $destRoot . '/modules/' . $module;
        $manifestPath = self::findSubManifest(dir: $sourceDir, preferredName: $module);

        self::copyDeclaredFiles(sourceDir: $sourceDir, destBase: $destBase, manifestPath: $manifestPath);

        self::copyDeclaredMediaAndLanguages(
            installRoot:  $installRoot,
            manifestPath: $manifestPath,
            destBase:     $destBase,
            // Site modules install their language INIs to the frontend tree.
            langRoots:    [$installRoot . '/language', $installRoot . '/administrator/language'],
        );
    }

    /**
     * Copy a list of declared file entries (['folder'|'file', name] pairs) from a
     * source root to a destination root. Named entries in $skip are skipped (used
     * to keep the root manifest/scriptfile out of the admin copy). Missing sources
     * are silent no-ops.
     *
     * @param array<int, array{0: string, 1: string}> $items Declared entries.
     * @param string $srcRoot Source root directory.
     * @param string $destRoot Destination root.
     * @param string[] $skip Entry names to skip.
     *
     *
     * @since   1.0.3
     */
    private static function copyItems(array $items, string $srcRoot, string $destRoot, array $skip = []): void
    {
        foreach ($items as [$type, $name]) {
            if (\in_array($name, $skip, true)) {
                continue;
            }

            if ($type === 'folder') {
                self::copyDir(source: $srcRoot . '/' . $name, dest: $destRoot . '/' . $name);
            } else {
                self::copyFile(source: $srcRoot . '/' . $name, dest: $destRoot . '/' . $name);
            }
        }
    }

    /**
     * Copy a sub-extension's declared CODE files into the repo layout: each
     * <files> block's <folder>/<filename> entries (honouring a folder="" subdir),
     * plus the manifest XML itself — which is never listed in <files> but must
     * ship. Media and languages are handled separately by
     * {@see self::copyDeclaredMediaAndLanguages()}. A null/invalid manifest is a
     * silent no-op.
     *
     * @param string $sourceDir The sub-extension's install folder.
     * @param string $destBase Its folder in the repo layout.
     * @param string|null $manifestPath Absolute path to its manifest (or null).
     *
     *
     * @since   1.0.3
     */
    private static function copyDeclaredFiles(string $sourceDir, string $destBase, ?string $manifestPath): void
    {
        if ($manifestPath === null) {
            return;
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return;
        }

        foreach ($xml->files as $files) {
            $sub = trim((string) $files['folder']);
            $src = $sub !== '' ? $sourceDir . '/' . $sub : $sourceDir;
            $dst = $sub !== '' ? $destBase . '/' . $sub : $destBase;

            self::copyItems(items: self::parseFilesItems(node: $files), srcRoot: $src, destRoot: $dst);
        }

        // The sub-extension's own manifest XML is not a <files> entry — ship it too.
        self::copyFile(source: $manifestPath, dest: $destBase . '/' . basename($manifestPath));
    }

    /**
     * Place a sub-extension's media and language files into the repo layout exactly
     * where its own manifest declares them, so the package mirrors each plugin's /
     * module's real layout instead of assuming fixed folder names (e.g. some plugins
     * use a `language/` folder, others `languages/`). Media is sourced from the
     * install's global media/<destination>/ folder; language INIs from the install
     * language tree this extension type uses — plugins install to the backend
     * (administrator/language/), site modules to the frontend (language/) — given as
     * $langRoots and searched in priority order (first match wins). When the manifest
     * declares no <media> / <languages>, nothing is copied.
     *
     * @param string $installRoot Joomla installation root.
     * @param string|null $manifestPath Absolute path to the sub-extension manifest (or null).
     * @param string $destBase The sub-extension's folder in the repo layout.
     * @param string[] $langRoots Install language roots to search, highest priority first.
     *
     *
     * @since   1.0.3
     */
    private static function copyDeclaredMediaAndLanguages(string $installRoot, ?string $manifestPath, string $destBase, array $langRoots): void
    {
        if ($manifestPath === null) {
            return;
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return;
        }

        // Media: <media destination="X" folder="Y"/> — install media/X/ → repo <destBase>/Y/.
        foreach ($xml->media as $media) {
            $destination = trim((string) $media['destination']);
            $folder = trim((string) $media['folder']) ?: 'media';

            if ($destination !== '') {
                self::copyDir(
                    source: $installRoot . '/media/' . $destination,
                    dest:   $destBase . '/' . $folder,
                );
            }
        }

        // Languages: <languages folder="F"><language tag="T">rel/file.ini</language>… — the INI
        // lives in the install's admin or site language tree; mirror it to <destBase>/F/rel.
        if (isset($xml->languages)) {
            $folder = trim((string) $xml->languages['folder']);

            foreach ($xml->languages->language as $language) {
                $tag = trim((string) $language['tag']);
                $rel = trim((string) $language);

                if ($tag === '' || $rel === '') {
                    continue;
                }

                $fileName = basename($rel);

                // Source from this extension type's language roots in priority order
                // (plugins → backend first, site modules → frontend first); first match wins.
                $source = $langRoots[0] . '/' . $tag . '/' . $fileName;

                foreach ($langRoots as $langRoot) {
                    if (is_file($langRoot . '/' . $tag . '/' . $fileName)) {
                        $source = $langRoot . '/' . $tag . '/' . $fileName;
                        break;
                    }
                }

                self::copyFile(
                    source: $source,
                    dest:   $destBase . '/' . ($folder !== '' ? $folder . '/' : '') . $rel,
                );
            }
        }
    }

    /**
     * Locate a sub-extension's manifest XML. Prefers "<preferredName>.xml" (the Joomla
     * convention for plugins/modules); otherwise returns the first &lt;extension&gt; XML
     * in the folder. Returns null if none is found.
     *
     * @param string $dir The sub-extension's install folder.
     * @param string $preferredName Expected manifest basename without extension.
     *
     *
     * @since   1.0.3
     */
    private static function findSubManifest(string $dir, string $preferredName): ?string
    {
        $preferred = $dir . '/' . $preferredName . '.xml';

        if (is_file($preferred)) {
            return $preferred;
        }

        foreach ((array) glob($dir . '/*.xml') as $candidate) {
            $xml = @simplexml_load_file($candidate);

            if ($xml !== false && $xml->getName() === 'extension') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Enumerate a list of declared <files>/<media> entries (['folder'|'file', name]
     * pairs) from an absolute source root, keyed by their LIVE root-relative path. A
     * 'folder' entry is walked recursively; a 'file' entry is listed if it exists.
     * Ignored names are skipped at every level; missing paths are silent no-ops.
     * Mirrors the SOURCE side of {@see self::copyItems()} but lists instead of copies.
     * Each entry also records its repo destination, computed by swapping $relRoot for
     * $repoRoot (mirroring how buildTree re-roots the area under the manifest folder).
     *
     * @param array<int, array{0: string, 1: string}> $items Declared entries.
     * @param string $absRoot Absolute source root on the live install.
     * @param string $relRoot Root-relative path of that source root (no leading slash).
     * @param string $repoRoot Repo (destRoot-relative) root buildTree writes this area to.
     * @param array<string, array{abs: string, repo: string}> $out Accumulator: liveRel => {abs, repo} (by ref).
     *
     *
     * @since   1.0.4
     */
    private static function collectDeclaredItems(array $items, string $absRoot, string $relRoot, string $repoRoot, array &$out): void
    {
        foreach ($items as [$type, $name]) {
            if (self::isIgnored(name: $name)) {
                continue;
            }

            if ($type === 'folder') {
                self::collectFiles(
                    absDir:  $absRoot . '/' . $name,
                    relDir:  $relRoot . '/' . $name,
                    repoDir: $repoRoot . '/' . $name,
                    out:     $out,
                );
            } else {
                self::collectFile(
                    abs:  $absRoot . '/' . $name,
                    rel:  $relRoot . '/' . $name,
                    repo: $repoRoot . '/' . $name,
                    out:  $out,
                );
            }
        }
    }

    /**
     * Enumerate a single sub-extension (plugin/module) the way
     * {@see self::buildPlugin()}/{@see self::buildModule()} do, but listing instead
     * of copying: its declared <files> code (honouring a folder="" subdir) plus the
     * manifest XML itself, its declared <media> under the install's media/<destination>,
     * and its declared <languages> resolved from the install's global language tree
     * (the given $langRoots searched in priority order, first match wins). All keyed
     * at their REAL live root-relative path. A null/invalid manifest is a silent no-op.
     *
     * For sub-extension CODE and the manifest XML the repo path equals the live path
     * (install == repo). For MEDIA the install's global media/<destination>/ tree is
     * co-located inside the sub-extension folder at <relSourceDir>/<folder> (the
     * <media folder=""> name, default "media"), and for LANGUAGES each INI is
     * co-located at <relSourceDir>/<folder>/<manifest-rel> (the <languages folder="">
     * name) — both mirroring {@see self::copyDeclaredMediaAndLanguages()}.
     *
     * @param string $installRoot Joomla installation root.
     * @param string $sourceDir The sub-extension's absolute install folder.
     * @param string $relSourceDir The sub-extension's root-relative folder (no leading slash).
     * @param string|null $manifestPath Absolute path to its manifest (or null).
     * @param string[] $langRoots Root-relative language roots to search, highest priority first.
     * @param array<string, array{abs: string, repo: string}> $out Accumulator: liveRel => {abs, repo} (by ref).
     *
     *
     * @since   1.0.4
     */
    private static function collectSubExtension(string $installRoot, string $sourceDir, string $relSourceDir, ?string $manifestPath, array $langRoots, array &$out): void
    {
        if ($manifestPath === null) {
            return;
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return;
        }

        // Code: each <files folder="…"> block's declared <folder>/<filename> entries.
        // Sub-extension code keeps the same relative path in repo and install.
        foreach ($xml->files as $files) {
            $sub = trim((string) $files['folder']);
            $absSub = $sub !== '' ? $sourceDir . '/' . $sub : $sourceDir;
            $relSub = $sub !== '' ? $relSourceDir . '/' . $sub : $relSourceDir;

            self::collectDeclaredItems(
                items:    self::parseFilesItems(node: $files),
                absRoot:  $absSub,
                relRoot:  $relSub,
                repoRoot: $relSub,
                out:      $out,
            );
        }

        // The sub-extension's own manifest XML is not a <files> entry — ship it too
        // (repo path == live path).
        self::collectFile(
            abs:  $manifestPath,
            rel:  $relSourceDir . '/' . basename($manifestPath),
            repo: $relSourceDir . '/' . basename($manifestPath),
            out:  $out,
        );

        // Media: <media destination="X" folder="Y"/> → install media/X/ (walked
        // recursively) → repo <relSourceDir>/Y/ (folder default "media").
        foreach ($xml->media as $media) {
            $destination = trim((string) $media['destination']);
            $folder = trim((string) $media['folder']) ?: 'media';

            if ($destination !== '') {
                self::collectFiles(
                    absDir:  $installRoot . '/media/' . $destination,
                    relDir:  'media/' . $destination,
                    repoDir: $relSourceDir . '/' . $folder,
                    out:     $out,
                );
            }
        }

        // Languages: each declared INI lives in the install's global language tree;
        // search $langRoots in priority order (first match wins), key at its real
        // live path → repo <relSourceDir>/<folder>/<manifest-rel>.
        if (isset($xml->languages)) {
            $langFolder = trim((string) $xml->languages['folder']);

            foreach ($xml->languages->language as $language) {
                $tag = trim((string) $language['tag']);
                $rel = trim((string) $language);

                if ($tag === '' || $rel === '') {
                    continue;
                }

                $fileName = basename($rel);

                foreach ($langRoots as $langRoot) {
                    $relPath = $langRoot . '/' . $tag . '/' . $fileName;

                    if (is_file($installRoot . '/' . $relPath)) {
                        self::collectFile(
                            abs:  $installRoot . '/' . $relPath,
                            rel:  $relPath,
                            repo: $relSourceDir . '/' . ($langFolder !== '' ? $langFolder . '/' : '') . $rel,
                            out:  $out,
                        );
                        break;
                    }
                }
            }
        }
    }

    /**
     * Recursively list every file beneath an absolute directory, keyed by its LIVE
     * root-relative path "<relDir>/<name>", each value carrying its absolute live
     * path and its repo destination "<repoDir>/<name>" (the repo tree mirrors the
     * live tree's shape under $repoDir). Skips '.', '..' and ignored names at every
     * level, recursing into subdirectories. A missing directory is a silent no-op.
     *
     * @param string $absDir Absolute directory on the live install.
     * @param string $relDir Root-relative path of that directory (no leading slash).
     * @param string $repoDir Repo (destRoot-relative) path of that directory (no leading slash).
     * @param array<string, array{abs: string, repo: string}> $out Accumulator: liveRel => {abs, repo} (by ref).
     *
     *
     * @since   1.0.4
     */
    private static function collectFiles(string $absDir, string $relDir, string $repoDir, array &$out): void
    {
        if (!is_dir($absDir)) {
            return;
        }

        foreach ((array) scandir($absDir) as $entry) {
            if ($entry === '.' || $entry === '..' || self::isIgnored(name: $entry)) {
                continue;
            }

            $abs = $absDir . '/' . $entry;
            $rel = $relDir . '/' . $entry;
            $repo = $repoDir . '/' . $entry;

            if (is_dir($abs)) {
                self::collectFiles(absDir: $abs, relDir: $rel, repoDir: $repo, out: $out);
            } else {
                $out[$rel] = ['abs' => $abs, 'repo' => $repo];
            }
        }
    }

    /**
     * List a single file by its LIVE root-relative key, recording its absolute live
     * path and its repo destination, if it exists and is not ignored. A
     * missing/ignored file is a silent no-op.
     *
     * @param string $abs Absolute path on the live install.
     * @param string $rel Live root-relative path key (no leading slash).
     * @param string $repo Repo (destRoot-relative) destination path (no leading slash).
     * @param array<string, array{abs: string, repo: string}> $out Accumulator: liveRel => {abs, repo} (by ref).
     *
     *
     * @since   1.0.4
     */
    private static function collectFile(string $abs, string $rel, string $repo, array &$out): void
    {
        if (!is_file($abs) || self::isIgnored(name: basename($abs))) {
            return;
        }

        $out[$rel] = ['abs' => $abs, 'repo' => $repo];
    }

    /**
     * Recursively copy a directory, skipping ignored entries and (optionally) the
     * named immediate children. A missing source directory is a silent no-op.
     *
     * @param string $source Source directory.
     * @param string $dest Destination directory.
     * @param string[] $skipTop Immediate child names to skip at the source root.
     *
     *
     * @since   1.0.3
     */
    private static function copyDir(string $source, string $dest, array $skipTop = []): void
    {
        if (!is_dir($source)) {
            return;
        }

        foreach ((array) scandir($source) as $entry) {
            if ($entry === '.' || $entry === '..' || self::isIgnored(name: $entry) || \in_array($entry, $skipTop, true)) {
                continue;
            }

            $from = $source . '/' . $entry;
            $to = $dest . '/' . $entry;

            if (is_dir($from)) {
                self::copyDir(source: $from, dest: $to);
            } else {
                self::copyFile(source: $from, dest: $to);
            }
        }
    }

    /**
     * Copy a single file, creating the destination directory as needed. A missing
     * source file is a silent no-op (so optional/absent areas don't abort a build).
     *
     * @param string $source Source file.
     * @param string $dest Destination file.
     *
     *
     * @since   1.0.3
     */
    private static function copyFile(string $source, string $dest): void
    {
        if (!is_file($source) || self::isIgnored(name: basename($source))) {
            return;
        }

        $dir = \dirname($dest);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        copy($source, $dest);
    }

    /**
     * Zip the contents of a directory (files placed at the archive root, so the
     * archive extracts to the repo layout directly).
     *
     * @param string $sourceDir Directory to archive.
     * @param string $zipPath Destination .zip path.
     *
     *
     * @throws RuntimeException If the archive cannot be created.
     *
     * @since   1.0.3
     */
    private static function zipTree(string $sourceDir, string $zipPath): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required to export a package.');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the package archive at ' . $zipPath);
        }

        $base = rtrim($sourceDir, '/') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $absolute = $item->getPathname();
            $relative = substr($absolute, \strlen($base));

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($absolute, $relative);
            }
        }

        $zip->close();
    }

    /**
     * Recursively delete a directory and its contents. Missing path is a no-op.
     *
     * @param string $dir Directory to remove.
     *
     *
     * @since   1.0.3
     */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                self::rrmdir(dir: $path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Delete leftover package archives older than one hour, in case a previous
     * export's stream died before its archive was cleaned up.
     *
     * @param string $tmpDir Temporary directory holding the archives.
     *
     *
     * @since   1.0.3
     */
    private static function sweepStaleArchives(string $tmpDir): void
    {
        $cutoff = time() - 3600;

        foreach ((array) glob($tmpDir . '/com_alfa-*.zip') as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Resolve a writable temporary directory. Prefers Joomla's configured
     * tmp_path, then the install's /tmp, then the system temp dir — so the tool
     * works on a dev box even when tmp_path points at a different (production) host.
     *
     * @param string $installRoot Joomla installation root.
     *
     * @return string An existing, writable temporary directory path.
     *
     * @since   1.0.3
     */
    private static function resolveTmpDir(string $installRoot): string
    {
        $candidates = [
            (string) Factory::getApplication()->get('tmp_path'),
            $installRoot . '/tmp',
            sys_get_temp_dir(),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_dir($candidate) && is_writable($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        throw new RuntimeException('No writable temporary directory is available for the export.');
    }

    /**
     * Whether a file/folder name should always be skipped (VCS/IDE/OS cruft,
     * backups, and auto-created runtime/dev files — see IGNORE_RUNTIME).
     *
     * @param string $name Bare entry name.
     *
     *
     * @since   1.0.3
     */
    private static function isIgnored(string $name): bool
    {
        if (\in_array($name, self::IGNORE_NAMES, true) || \in_array($name, self::IGNORE_RUNTIME, true)) {
            return true;
        }

        foreach (self::IGNORE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
