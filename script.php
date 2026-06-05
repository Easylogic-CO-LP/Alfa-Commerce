<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

define('MODIFIED',     1);
define('NOT_MODIFIED', 2);

defined('_JEXEC') or die();

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerScript;

/**
 * Installer / uninstaller script for the Alfa Commerce component.
 *
 *
 * Responsibilities:
 *  - Validate environment requirements before installation (preflight).
 *  - Install / update bundled libraries, plugins and modules alongside the component.
 *  - Seed sensible default configuration parameters after install/update (postflight).
 *  - Bulk-sync existing Joomla users and usergroups into Alfa tables on install/update.
 *  - Clean up libraries, plugins and modules when the component is uninstalled.
 *
 * Sync logic lives entirely in SyncHelper so it is shared with the runtime
 * plugin (PlgSystemAlfasync) and any frontend registration code — there is no
 * duplicated INSERT/SELECT logic anywhere.
 *
 * The default-params system works as follows:
 *  • Fresh install  → all keys in {@see self::$defaultParams} are written.
 *  • Update         → only keys absent from the current stored params are added;
 *                     existing admin choices are never touched.
 *  • Multi-select   → pass a plain PHP array; it is json_encode()'d automatically.
 *
 * Library bundling:
 *  • Libraries are declared in com_alfa.xml under <libraries>:
 *        <library folder="lib_libphonenumber" libraryname="libphonenumber"/>
 *    where `folder`      = path inside the component zip holding the library's files
 *          `libraryname` = matches <libraryname> in the library's own manifest
 *  • Libraries are installed BEFORE plugins/modules so plugins can `use` their classes.
 *  • Libraries are uninstalled AFTER plugins/modules for reverse dependency safety.
 *  • After any library install/update the PSR-4 namespace cache is flushed so the
 *    library's <namespace> tag is picked up on the very next request.
 *
 * @since  0.1b
 */
class com_alfaInstallerScript extends InstallerScript
{
    // =========================================================================
    // Class-level configuration
    // =========================================================================

    /** @var string */
    protected $extension = 'Alfa Commerce';

    /** @var string */
    protected $minimumJoomla = '4.0';

    /**
     * Version we are updating FROM, captured in preflight before the new files
     * overwrite the old manifest. Drives the obsolete-file cleanup in postflight.
     *
     * @var string
     */
    private string $fromVersion = '';

    /**
     * Default component parameters to seed after install / update.
     *
     * Keys must match the `name` attribute of every <field> in config.xml.
     *
     * The subset of keys listed in SyncHelper::PRICES_DISPLAY_KEYS is passed
     * to SyncHelper::buildDefaultPricesDisplay() to build the per-usergroup
     * prices_display JSON — no hardcoded JSON strings anywhere in this codebase.
     *
     * @var array<string, scalar|array<string>>
     */
    private array $defaultParams = [

        // General
        'include_subcategories'                => 1,

        // Stock
        'stock_action'                         => 0,
        'stock_low'                            => 5,

        // Price display
        // These keys are also read by SyncHelper::PRICES_DISPLAY_KEYS to build
        // the per-usergroup prices_display JSON.  Keep values consistent.
        'base_price_show'                      => 0,
        'base_price_show_label'                => 0,
        'discount_amount_show'                 => 0,
        'discount_amount_show_label'           => 0,
        'base_price_with_discounts_show'       => 0,
        'base_price_with_discounts_show_label' => 0,
        'tax_amount_show'                      => 0,
        'tax_amount_show_label'                => 0,
        'base_price_with_tax_show'             => 0,
        'base_price_with_tax_show_label'       => 0,
        'final_price_show'                     => 1,
        'final_price_show_label'               => 1,
        'price_breakdown_show'                 => 0,

        // Currency
        'default_currency'                     => 978,  // EUR

        // Media
        'media_file_format'                    => 'webp',
        'media_image_quality'                  => 85,
        'media_image_width'                    => 1920,
        'media_image_height'                   => 1080,
        'media_thumbnail_width'                => 200,
        'media_thumbnail_height'               => 200,
        'media_save_location'                  => '/images/media-zone',
        'media_placeholder'                    => 'media/com_alfa/images/placeholder_600x.webp',
        'media_url_thumbnail'                  => 'media/com_alfa/images/url_thumbnail.jpg',
        'media_name_from_alias'                => 0,
        'media_full_deletion'                  => 0,
        'media_mime'                           => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'image/webp',
            'image/avif',
        ],

        // History
        'save_history'                         => 0,
        'history_limit'                        => 5,

        // SEF
        'sef_ids'                              => 1,

        // Cache
        'enable_cache'                         => 1,
        'cache_categories'                     => 1,
    ];

    // =========================================================================
    // Installer lifecycle hooks
    // =========================================================================

    /**
     * @param   string  $type
     * @param   object  $parent
     *
     * @return  bool
     * @throws  \Exception
     */
    public function preflight($type, $parent): bool
    {
        // Capture the version we are updating FROM while the OLD manifest is still
        // on disk (the new files have not been copied yet). postflight uses it to
        // know which version-keyed deletion lists to apply.
        if ($type === 'update')
        {
            $oldManifest = JPATH_ADMINISTRATOR . '/components/com_alfa/alfa.xml';

            if (is_file($oldManifest))
            {
                $xml = @simplexml_load_file($oldManifest);

                if ($xml !== false)
                {
                    $this->fromVersion = trim((string) $xml->version);
                }
            }
        }

        return parent::preflight($type, $parent);
    }

    /**
     * Install-time hook.
     *
     * Order matters: libraries first (plugins may `use` library classes),
     * then plugins, then modules.
     *
     * @param   object  $parent
     *
     * @return  void
     */
    public function install($parent): void
    {
        $this->installLibraries($parent);
        $this->installPlugins($parent);
        $this->installModules($parent);
    }

    /**
     * Update-time hook.
     *
     * Same order as install() — a library might have been added in this
     * release that a new/updated plugin relies on.
     *
     * @param   object  $parent
     *
     * @return  void
     */
    public function update($parent): void
    {
        $this->installLibraries($parent);
        $this->installPlugins($parent);
        $this->installModules($parent);
    }

    /**
     * Uninstall-time hook.
     *
     * Reverse of install order: remove plugins and modules first (they may
     * reference classes from our libraries), then the libraries themselves.
     *
     * @param   object  $parent
     *
     * @return  void
     */
    public function uninstall($parent): void
    {
        $this->uninstallPlugins($parent);
        $this->uninstallModules($parent);
        $this->uninstallLibraries($parent);
    }

    /**
     * Post-flight: runs after install or update is complete.
     *
     * Execution order:
     *  1. applyDefaultParams()              – seeds / merges component config.
     *  2. SyncHelper::bulkSyncUsers()       – batch-inserts missing users.
     *  3. SyncHelper::bulkSyncUsergroups()  – batch-inserts missing groups.
     *
     * @param   string  $type
     * @param   object  $parent
     *
     * @return  bool
     */
    public function postflight($type, $parent): bool
    {
        if (!in_array($type, ['install', 'update'], true))
        {
            return true;
        }

        // On update, clean up files/folders that older versions shipped but this
        // version no longer does (Joomla's installer never removes them on its own).
        if ($type === 'update')
        {
            $this->removeObsoleteFiles($this->fromVersion !== '' ? $this->fromVersion : '0.0.0');
        }

        // Register the component namespace so every Alfa\Component\Alfa\Administrator\…
        // class autoloads here — the component autoloader isn't active yet at postflight
        // time, and SyncHelper::syncLanguageSchema() pulls in further helpers
        // (e.g. MultilingualAliasConfig) that a single require_once would miss.
        \JLoader::registerNamespace(
            'Alfa\\Component\\Alfa\\Administrator',
            JPATH_ADMINISTRATOR . '/components/com_alfa/src'
        );

        $syncHelperPath = JPATH_ADMINISTRATOR . '/components/com_alfa/src/Helper/SyncHelper.php';

        if (file_exists($syncHelperPath))
        {
            require_once $syncHelperPath;
        }
        else
        {
            Factory::getApplication()->enqueueMessage('SyncHelper not found at: ' . $syncHelperPath, 'error');
            return false;
        }

        $db  = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();

        // 1. Seed params first — SyncHelper::buildDefaultPricesDisplay() reads
        //    from $this->defaultParams at install time (not from ComponentHelper)
        //    so ordering doesn't actually matter here, but it's good practice.
        $this->applyDefaultParams($type);

        // 2. Bulk-sync users
        try
        {
            $count = \Alfa\Component\Alfa\Administrator\Helper\SyncHelper::bulkSyncUsers($db);
            $app->enqueueMessage(
                sprintf('Users synced successfully (%d new records).', $count)
            );
        }
        catch (\Throwable $e)
        {
            $app->enqueueMessage('Failed to sync users: ' . $e->getMessage(), 'error');
        }

        // 3. Bulk-sync usergroups
        //    Pass $this->defaultParams so buildDefaultPricesDisplay() uses our
        //    local array (safe even if DB params aren't fully committed yet).
        try
        {
            $count = \Alfa\Component\Alfa\Administrator\Helper\SyncHelper::bulkSyncUsergroups($db, $this->defaultParams);
            $app->enqueueMessage(
                sprintf('Usergroups synced successfully (%d new records).', $count)
            );
        }
        catch (\Throwable $e)
        {
            $app->enqueueMessage('Failed to sync usergroups: ' . $e->getMessage(), 'error');
        }

        // 4. Create / update the per-language translation tables, discovered from
        //    the form XML (every field with a multilingual_table attribute).
        //    MultilingualHelper is loaded explicitly — the component autoloader
        //    may not be registered yet at postflight time.
        $multilingualHelperPath = JPATH_ADMINISTRATOR . '/components/com_alfa/src/Helper/MultilingualHelper.php';

        if (file_exists($multilingualHelperPath))
        {
            require_once $multilingualHelperPath;

            try
            {
                $result = \Alfa\Component\Alfa\Administrator\Helper\SyncHelper::syncLanguageSchema();
                $app->enqueueMessage(
                    sprintf('Language tables synced (%d translatable tables processed).', count($result['tables']))
                );

                if (!empty($result['errors']))
                {
                    $app->enqueueMessage(
                        'Some language tables failed: ' . implode('; ', array_keys($result['errors'])),
                        'warning'
                    );
                }
            }
            catch (\Throwable $e)
            {
                $app->enqueueMessage('Failed to sync language tables: ' . $e->getMessage(), 'error');
            }
        }

        // File integrity is verified online against the signed canonical checksums on the
        // CDN (single source of truth — IntegrityHelper::verifyAgainstOfficial), so there
        // is no local baseline to capture. The verdict is cached for 24h, though, and an
        // update changes the files — so drop the cache here, exactly as the Tools view does
        // on each visit, and the next Security check re-verifies this version cleanly.
        $integrityHelperPath = JPATH_ADMINISTRATOR . '/components/com_alfa/src/Helper/IntegrityHelper.php';

        if (file_exists($integrityHelperPath))
        {
            require_once $integrityHelperPath;

            try
            {
                \Alfa\Component\Alfa\Administrator\Helper\IntegrityHelper::clearVerdictCache();
            }
            catch (\Throwable $e)
            {
                // Non-fatal: a stale cache only delays the re-check by up to 24h.
            }
        }

        return true;
    }

    // =========================================================================
    // Obsolete-file cleanup (version-keyed, mirrors sql/updates)
    // =========================================================================

    /**
     * Delete files and folders that earlier releases installed but the current one
     * no longer ships. Mirrors the SQL-update model: each release that drops files
     * adds a version-keyed list at
     * `administrator/components/com_alfa/files/removed/<version>.json`
     * (sibling of `sql/updates/<version>.sql`), and on update every list newer than
     * the installed version is applied (so a customer skipping several versions is
     * fully cleaned up).
     *
     * Each JSON file has the shape (paths are relative to the Joomla root):
     *   {
     *     "files":   ["/components/com_alfa/controller.php", ...],
     *     "folders": ["/media/com_alfa/js/legacy", ...]
     *   }
     *
     * Auto-created runtime folders (logs, caches, generated data) must NOT be
     * listed — they are regenerated and may hold live data. Deletion is guarded by
     * Joomla's removeFiles(), which no-ops on paths that no longer exist.
     *
     * @param   string  $fromVersion  The version being updated from.
     *
     * @return  void
     */
    private function removeObsoleteFiles(string $fromVersion): void
    {
        $dir = JPATH_ADMINISTRATOR . '/components/com_alfa/files/removed';

        if (!is_dir($dir))
        {
            return;
        }

        $files   = [];
        $folders = [];

        foreach ((array) glob($dir . '/*.json') as $path)
        {
            $version = basename($path, '.json');

            // Only lists for releases newer than the one we are updating from.
            if (version_compare($version, $fromVersion, '<='))
            {
                continue;
            }

            $data = json_decode((string) file_get_contents($path), true);

            if (!is_array($data))
            {
                continue;
            }

            foreach ((array) ($data['files'] ?? []) as $file)
            {
                $files[] = '/' . ltrim((string) $file, '/');
            }

            foreach ((array) ($data['folders'] ?? []) as $folder)
            {
                $folders[] = '/' . ltrim((string) $folder, '/');
            }
        }

        if (!$files && !$folders)
        {
            return;
        }

        // Feed Joomla's InstallerScript::removeFiles(), which prefixes JPATH_ROOT
        // and silently skips anything already gone.
        $this->deleteFiles   = array_values(array_unique($files));
        $this->deleteFolders = array_values(array_unique($folders));

        $this->removeFiles();

        Factory::getApplication()->enqueueMessage(
            sprintf(
                'Cleaned up %d obsolete file(s) and %d folder(s) from previous versions.',
                count($this->deleteFiles),
                count($this->deleteFolders)
            )
        );
    }

    // =========================================================================
    // Default-params helper
    // =========================================================================

    /**
     * Merges $defaultParams with currently stored params and persists the result.
     *
     * Strategy: existing DB values win (array_merge puts $currentParams last).
     * New keys from $defaultParams are inserted; nothing is ever overwritten.
     *
     * @param   string  $type
     *
     * @return  void
     */
    private function applyDefaultParams(string $type): void
    {
        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
            ->where($db->quoteName('type')    . ' = ' . $db->quote('component'));

        $db->setQuery($query);
        $stored = $db->loadResult();

        $currentParams = (!empty($stored)) ? json_decode($stored, true) : [];

        if (!is_array($currentParams))
        {
            $currentParams = [];
        }

        $mergedParams = array_merge($this->defaultParams, $currentParams);

        foreach ($mergedParams as $key => $value)
        {
            if (is_array($value))
            {
                $mergedParams[$key] = json_encode(array_values($value));
            }
        }

        $query
            ->clear()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($mergedParams)))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
            ->where($db->quoteName('type')    . ' = ' . $db->quote('component'));

        $db->setQuery($query);
        $db->execute();
    }

    // =========================================================================
    // Library helpers
    // =========================================================================

    /**
     * Install or update all libraries declared in the component manifest.
     *
     * Expected manifest shape:
     *   <libraries>
     *       <library folder="lib_libphonenumber" libraryname="libphonenumber"/>
     *       <library folder="lib_giggsey_locale" libraryname="giggsey/locale"/>
     *   </libraries>
     *
     * After at least one successful install/update, the Joomla PSR-4 namespace
     * cache (cache/autoload_psr4.php) is deleted so any <namespace> tag from
     * the freshly installed library is picked up on the next request. Joomla
     * core's InstallModel does NOT clear this cache, so without this flush the
     * library's classes would not be autoloadable until the admin manually
     * clears caches — leading to a "class not found" on first use.
     *
     * @param   object  $parent
     *
     * @return  void
     */
    private function installLibraries($parent): void
    {
        $installationFolder = $parent->getParent()->getPath('source');
        $app                = Factory::getApplication();

        $libraries = method_exists($parent, 'getManifest')
            ? $parent->getManifest()->libraries
            : $parent->get('manifest')->libraries;

        if (empty($libraries) || !count($libraries->children()))
        {
            return;
        }

        $didAnything = false;

        foreach ($libraries->children() as $library)
        {
            $folder      = (string) $library['folder'];
            $libraryName = (string) $library['libraryname'];
            $path        = $installationFolder . '/libraries/' . $folder;

            if (!is_dir($path))
            {
                $app->enqueueMessage(
                    sprintf('Library source missing at %s — skipped.', $path),
                    'warning'
                );
                continue;
            }

            $installer = new Installer();
            $installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));

            $result = $this->isAlreadyInstalled('library', $libraryName)
                ? $installer->update($path)
                : $installer->install($path);

            $app->enqueueMessage(
                $result
                    ? sprintf('Library %s installed successfully.', $libraryName)
                    : sprintf('There was an issue installing library %s.', $libraryName),
                $result ? 'message' : 'error'
            );

            $didAnything = $didAnything || $result;
        }

        if ($didAnything)
        {
            $this->flushNamespaceCache();
        }
    }

    /**
     * Uninstall every library declared in the component manifest.
     *
     * Looks up each by `libraryname` in #__extensions (the value Joomla stores
     * in the `element` column for libraries) and dispatches a standard
     * Installer::uninstall('library', $id) call.
     *
     * @param   object  $parent
     *
     * @return  void
     */
    private function uninstallLibraries($parent): void
    {
        $app = Factory::getApplication();

        $libraries = method_exists($parent, 'getManifest')
            ? $parent->getManifest()->libraries
            : $parent->get('manifest')->libraries;

        if (empty($libraries) || !count($libraries->children()))
        {
            return;
        }

        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        foreach ($libraries->children() as $library)
        {
            $libraryName = (string) $library['libraryname'];

            $query
                ->clear()
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where([
                    'type LIKE '    . $db->quote('library'),
                    'element LIKE ' . $db->quote($libraryName),
                ]);

            $db->setQuery($query);
            $extensionId = $db->loadResult();

            if (empty($extensionId))
            {
                continue;
            }

            $installer = new Installer();
            $installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));
            $result = $installer->uninstall('library', $extensionId);

            $app->enqueueMessage(
                $result
                    ? sprintf('Library %s uninstalled successfully.', $libraryName)
                    : sprintf('There was an issue uninstalling library %s.', $libraryName),
                $result ? 'message' : 'error'
            );
        }

        $this->flushNamespaceCache();
    }

    /**
     * Delete Joomla's cached PSR-4 namespace map so it is regenerated on the
     * next request. Required after any library install/update/uninstall
     * because Joomla core does not invalidate this cache automatically.
     *
     * @return  void
     */
    private function flushNamespaceCache(): void
    {
        $cacheFile = JPATH_CACHE . '/autoload_psr4.php';

        if (is_file($cacheFile))
        {
            @unlink($cacheFile);
        }
    }

    // =========================================================================
    // Plugin helpers
    // =========================================================================

    private function installPlugins($parent): void
    {
        $installationFolder = $parent->getParent()->getPath('source');
        $app                = Factory::getApplication();

        $plugins = method_exists($parent, 'getManifest')
            ? $parent->getManifest()->plugins
            : $parent->get('manifest')->plugins;

        if (empty($plugins) || !count($plugins->children()))
        {
            return;
        }

        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        foreach ($plugins->children() as $plugin)
        {
            $pluginName  = (string) $plugin['plugin'];
            $pluginGroup = (string) $plugin['group'];
            $path        = $installationFolder . '/plugins/' . $pluginGroup . '/' . $pluginName;

            $installer = new Installer();
            $installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));

            $result = $this->isAlreadyInstalled('plugin', $pluginName, $pluginGroup)
                ? $installer->update($path)
                : $installer->install($path);

            $app->enqueueMessage(
                $result
                    ? sprintf('Plugin %s/%s installed successfully.', $pluginGroup, $pluginName)
                    : sprintf('There was an issue installing plugin %s/%s.', $pluginGroup, $pluginName),
                $result ? 'message' : 'error'
            );

            $query
                ->clear()
                ->update($db->quoteName('#__extensions'))
                ->set('enabled = 1')
                ->where([
                    'type LIKE '    . $db->quote('plugin'),
                    'element LIKE ' . $db->quote($pluginName),
                    'folder LIKE '  . $db->quote($pluginGroup),
                ]);

            $db->setQuery($query);
            $db->execute();
        }
    }

    private function uninstallPlugins($parent): void
    {
        $app = Factory::getApplication();

        $plugins = method_exists($parent, 'getManifest')
            ? $parent->getManifest()->plugins
            : $parent->get('manifest')->plugins;

        if (empty($plugins) || !count($plugins->children()))
        {
            return;
        }

        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        foreach ($plugins->children() as $plugin)
        {
            $pluginName  = (string) $plugin['plugin'];
            $pluginGroup = (string) $plugin['group'];

            $query
                ->clear()
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where([
                    'type LIKE '    . $db->quote('plugin'),
                    'element LIKE ' . $db->quote($pluginName),
                    'folder LIKE '  . $db->quote($pluginGroup),
                ]);

            $db->setQuery($query);
            $extensionId = $db->loadResult();

            if (empty($extensionId))
            {
                continue;
            }

            $installer = new Installer();
            $installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));
            $result = $installer->uninstall('plugin', $extensionId);

            $app->enqueueMessage(
                $result
                    ? sprintf('Plugin %s uninstalled successfully.', $pluginName)
                    : sprintf('There was an issue uninstalling plugin %s.', $pluginName),
                $result ? 'message' : 'error'
            );
        }
    }

    // =========================================================================
    // Module helpers
    // =========================================================================

    private function installModules($parent): void
    {
        $installationFolder = $parent->getParent()->getPath('source');
        $app                = Factory::getApplication();

        $modules = method_exists($parent, 'getManifest')
            ? $parent->getManifest()->modules
            : $parent->get('manifest')->modules;

        if (empty($modules) || !count($modules->children()))
        {
            return;
        }

        foreach ($modules->children() as $module)
        {
            $moduleName = (string) $module['module'];
            $path       = $installationFolder . '/modules/' . $moduleName;

            $installer = new Installer();
            $installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));

            $result = $this->isAlreadyInstalled('module', $moduleName)
                ? $installer->update($path)
                : $installer->install($path);

            $app->enqueueMessage(
                $result
                    ? sprintf('Module %s installed successfully.', $moduleName)
                    : sprintf('There was an issue installing module %s.', $moduleName),
                $result ? 'message' : 'error'
            );
        }
    }

    private function uninstallModules($parent): void
    {
        $app = Factory::getApplication();

        $modules = method_exists($parent, 'getManifest')
            ? $parent->getManifest()->modules
            : $parent->get('manifest')->modules;

        if (empty($modules) || !count($modules->children()))
        {
            return;
        }

        $db    = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        foreach ($modules->children() as $module)
        {
            $moduleName = (string) $module['module'];

            $query
                ->clear()
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where([
                    'type LIKE '    . $db->quote('module'),
                    'element LIKE ' . $db->quote($moduleName),
                ]);

            $db->setQuery($query);
            $extensionId = $db->loadResult();

            if (empty($extensionId))
            {
                continue;
            }

            $installer = new Installer();
            $installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));
            $result = $installer->uninstall('module', $extensionId);

            $app->enqueueMessage(
                $result
                    ? sprintf('Module %s uninstalled successfully.', $moduleName)
                    : sprintf('There was an issue uninstalling module %s.', $moduleName),
                $result ? 'message' : 'error'
            );
        }
    }

    // =========================================================================
    // Utility
    // =========================================================================

    /**
     * Cheap filesystem check for "is this extension already installed?".
     *
     * For libraries, $name is the full `libraryname` (may contain a slash,
     * e.g. "giggsey/locale") — mirrors the path produced by LibraryAdapter.
     *
     * @param   string       $type    One of: plugin, module, template, library
     * @param   string       $name    Extension element name (plugin/module/template name, or libraryname)
     * @param   string|null  $folder  Plugin group (only used for plugins)
     *
     * @return  bool
     */
    private function isAlreadyInstalled(string $type, string $name, ?string $folder = null): bool
    {
        return match ($type)
        {
            'plugin'   => file_exists(JPATH_PLUGINS   . '/' . $folder . '/' . $name),
            'module'   => file_exists(JPATH_SITE      . '/modules/'   . $name),
            'template' => file_exists(JPATH_SITE      . '/templates/' . $name),
            'library'  => file_exists(JPATH_LIBRARIES . '/' . $name),
            default    => false,
        };
    }
}
