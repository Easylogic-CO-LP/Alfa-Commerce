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
     * Build the installable/PR package archive from a live install.
     *
     * Reads the manifest, re-assembles the repo-layout tree in a temporary
     * working directory, zips it, removes the working directory, and returns the
     * path to the finished archive (the caller is responsible for streaming it to
     * the browser and deleting it afterwards).
     *
     * @param string $installRoot Absolute path to the Joomla installation root (JPATH_ROOT).
     *
     * @return array{zip: string, filename: string, version: string} The archive
     *                                                               path on disk, the suggested download filename, and the manifest version.
     *
     * @throws RuntimeException If the manifest is missing/invalid or zipping fails.
     *
     * @since   1.0.3
     */
    public static function buildPackageZip(string $installRoot): array
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
            self::buildTree(installRoot: $installRoot, manifest: $manifest, destRoot: $workDir);
            self::zipTree(sourceDir: $workDir, zipPath: $zipPath);
        } finally {
            self::rrmdir(dir: $workDir);
        }

        return ['zip' => $zipPath, 'filename' => $filename, 'version' => $version];
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

        // --- Site component ---
        self::copyDir(
            source: $installRoot . '/components/' . $component,
            dest:   $destRoot . '/' . $manifest['siteFolder'],
        );

        // --- Admin component (minus the root artifacts, which we placed above) ---
        $adminSkip = [$manifest['manifestFilename']];

        if ($manifest['scriptfile'] !== '') {
            $adminSkip[] = $manifest['scriptfile'];
        }

        self::copyDir(
            source:  $adminCompat,
            dest:    $destRoot . '/' . $manifest['adminFolder'],
            skipTop: $adminSkip,
        );

        // --- API component ---
        if ($manifest['hasApi']) {
            self::copyDir(
                source: $installRoot . '/api/components/' . $component,
                dest:   $destRoot . '/' . $manifest['apiFolder'],
            );
        }

        // --- Component media (only if the manifest declares <media>) ---
        if ($manifest['hasMedia']) {
            self::copyDir(
                source: $installRoot . '/media/' . $manifest['mediaDest'],
                dest:   $destRoot . '/' . $manifest['mediaFolder'],
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

        // Code only — media + language folders are reconstructed from the manifest below.
        self::copyDir(source: $sourceDir, dest: $destBase, skipTop: ['media', 'language', 'languages']);

        self::copyDeclaredMediaAndLanguages(
            installRoot:  $installRoot,
            manifestPath: self::findSubManifest(dir: $sourceDir, preferredName: $name),
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

        self::copyDir(source: $sourceDir, dest: $destBase, skipTop: ['media', 'language', 'languages']);

        self::copyDeclaredMediaAndLanguages(
            installRoot:  $installRoot,
            manifestPath: self::findSubManifest(dir: $sourceDir, preferredName: $module),
            destBase:     $destBase,
            // Site modules install their language INIs to the frontend tree.
            langRoots:    [$installRoot . '/language', $installRoot . '/administrator/language'],
        );
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
     * Whether a file/folder name should always be skipped (VCS/IDE/OS cruft, backups).
     *
     * @param string $name Bare entry name.
     *
     *
     * @since   1.0.3
     */
    private static function isIgnored(string $name): bool
    {
        if (\in_array($name, self::IGNORE_NAMES, true)) {
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
