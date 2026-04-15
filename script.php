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

use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerScript;

/**
 * Installer / uninstaller script for the Alfa Commerce component.
 *
 * Responsibilities:
 *  - Validate environment requirements before installation (preflight).
 *  - Install / update bundled plugins and modules alongside the component.
 *  - Seed sensible default configuration parameters after install/update (postflight).
 *  - Bulk-sync existing Joomla users and usergroups into Alfa tables on install/update.
 *  - Clean up plugins and modules when the component is uninstalled.
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
        return parent::preflight($type, $parent);
    }

    /** @param object $parent */
    public function install($parent): void
    {
        $this->installPlugins($parent);
        $this->installModules($parent);
    }

    /** @param object $parent */
    public function update($parent): void
    {
        $this->installPlugins($parent);
        $this->installModules($parent);
    }

    /** @param object $parent */
    public function uninstall($parent): void
    {
        $this->uninstallPlugins($parent);
        $this->uninstallModules($parent);
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

        $db  = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();

        // 1. Seed params first — SyncHelper::buildDefaultPricesDisplay() reads
        //    from $this->defaultParams at install time (not from ComponentHelper)
        //    so ordering doesn't actually matter here, but it's good practice.
        $this->applyDefaultParams($type);

        // 2. Bulk-sync users
        try
        {
            $count = SyncHelper::bulkSyncUsers($db);
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
            $count = SyncHelper::bulkSyncUsergroups($db, $this->defaultParams);
            $app->enqueueMessage(
                sprintf('Usergroups synced successfully (%d new records).', $count)
            );
        }
        catch (\Throwable $e)
        {
            $app->enqueueMessage('Failed to sync usergroups: ' . $e->getMessage(), 'error');
        }

        return true;
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

    private function isAlreadyInstalled(string $type, string $name, ?string $folder = null): bool
    {
        return match ($type)
        {
            'plugin'   => file_exists(JPATH_PLUGINS . '/' . $folder . '/' . $name),
            'module'   => file_exists(JPATH_SITE    . '/modules/' . $name),
            'template' => file_exists(JPATH_SITE    . '/templates/' . $name),
            default    => false,
        };
    }
}
