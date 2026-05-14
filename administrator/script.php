<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// ---------------------------------------------------------------------------
// Constants used by update helpers to signal whether a DB row was touched.
// ---------------------------------------------------------------------------
define('MODIFIED',     1);
define('NOT_MODIFIED', 2);

defined('_JEXEC') or die();

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerScript;

/**
 * Installer / uninstaller script for the Alfa Commerce component.
 *
 * Responsibilities:
 *  - Validate environment requirements before installation (preflight).
 *  - Install / update bundled plugins and modules alongside the component.
 *  - Seed sensible default configuration parameters after a fresh install or
 *    after an update that introduces new config keys (postflight).
 *  - Clean up plugins and modules when the component is uninstalled.
 *
 * The default-params system works as follows:
 *  • On a FRESH INSTALL  – all keys in {@see self::$defaultParams} are written.
 *  • On an UPDATE        – only keys that do not yet exist in the stored params
 *                          are written; existing admin choices are never touched.
 *  • Multi-select fields – pass a plain PHP array as the value; the method
 *                          automatically JSON-encodes it to match Joomla's
 *                          storage format for fancy-select / multi-select fields.
 *
 * @author  Agamemnon Fakas <info@easylogic.gr>
 * @since   0.1b
 */
class com_alfaInstallerScript extends InstallerScript
{
	// =========================================================================
	// Class-level configuration
	// =========================================================================

	/**
	 * Human-readable extension title (shown in Joomla installer messages).
	 *
	 * @var string
	 */
	protected $extension = 'Alfa Commerce';

	/**
	 * Minimum Joomla! version required to install this extension.
	 *
	 * @var string
	 */
	protected $minimumJoomla = '4.0';

	/**
	 * Default component parameters to seed after install / update.
	 *
	 * Keys must match the `name` attribute of every <field> element defined in
	 * the component's config.xml.  Values are the desired defaults.
	 *
	 * Rules:
	 *  - Scalar values (int, float, string) are stored as-is inside the JSON
	 *    params blob that Joomla keeps in #__extensions.params.
	 *  - Array values are automatically json_encode()'d because Joomla stores
	 *    multi-select / fancy-select fields as a JSON-encoded array string.
	 *  - These values are ONLY written when the key is absent from the stored
	 *    params (safe for updates – never overwrites admin configuration).
	 *
	 * @var array<string, scalar|array<string>>
	 */
	private array $defaultParams = [

		// -----------------------------------------------------------------
		// General / category settings
		// -----------------------------------------------------------------

		/** Show items from child categories in a parent category view. */
		'include_subcategories'                  => 1,

		// -----------------------------------------------------------------
		// Stock management
		// -----------------------------------------------------------------

		/**
		 * Action to take when a product runs out of stock.
		 *  0 = No action
		 *  1 = Notify
		 *  2 = Hide out-of-stock items
		 */
		'stock_action'                           => 0,

		/** Number of units considered "low stock". */
		'stock_low'                              => 5,

		// -----------------------------------------------------------------
		// Price display settings
		// -----------------------------------------------------------------

		/** Show the base (pre-discount, pre-tax) price. */
		'base_price_show'                        => 1,

		/** Show a label next to the base price. */
		'base_price_show_label'                  => 1,

		/** Show the discount amount row. */
		'discount_amount_show'                   => 1,

		/** Show a label next to the discount amount. */
		'discount_amount_show_label'             => 1,

		/** Show the subtotal (base price after discounts applied). */
		'base_price_with_discounts_show'         => 1,

		/** Show a label next to the subtotal. */
		'base_price_with_discounts_show_label'   => 1,

		/** Show the tax amount row. */
		'tax_amount_show'                        => 1,

		/** Show a label next to the tax amount. */
		'tax_amount_show_label'                  => 1,

		/**
		 * Show base price WITH tax included.
		 * Disabled by default because most stores show tax separately.
		 */
		'base_price_with_tax_show'               => 0,

		/** Show a label next to the base-price-with-tax row. */
		'base_price_with_tax_show_label'         => 1,

		/** Show the final (all-inclusive) price. */
		'final_price_show'                       => 1,

		/** Show a label next to the final price. */
		'final_price_show_label'                 => 1,

		/**
		 * Show a full price breakdown panel.
		 * Disabled by default; useful for B2B / transparent pricing stores.
		 */
		'price_breakdown_show'                   => 0,

		// -----------------------------------------------------------------
		// Currency settings
		// -----------------------------------------------------------------

		/**
		 * ISO 4217 numeric code for the default currency.
		 * 978 = EUR.  Change to e.g. 840 (USD) or 826 (GBP) as needed.
		 */
		'default_currency'                       => 978,

		// -----------------------------------------------------------------
		// Media zone settings
		// -----------------------------------------------------------------

		/**
		 * Automatically convert uploaded images to this format.
		 * Empty string = no conversion.
		 * Options: '', 'jpg', 'png', 'webp', 'gif'
		 */
		'media_file_format'                      => 'webp',

		/** JPEG / WebP compression quality (1–100). */
		'media_image_quality'                    => 85,

		/** Maximum width (px) for full-size stored images. */
		'media_image_width'                      => 1920,

		/** Maximum height (px) for full-size stored images. */
		'media_image_height'                     => 1080,

		/** Width (px) of auto-generated thumbnails. */
		'media_thumbnail_width'                  => 200,

		/** Height (px) of auto-generated thumbnails. */
		'media_thumbnail_height'                 => 200,

		/** Joomla-relative path where uploaded media files are stored. */
		'media_save_location'                    => '/images/media-zone',

		/** Path to the image shown when no media has been assigned. */
		'media_placeholder'                      => 'media/com_alfa/images/placeholder_600x.webp',

		/** Default thumbnail used when an external URL has no preview. */
		'media_url_thumbnail'                    => 'media/com_alfa/images/url_thumbnail.jpg',

		/**
		 * When enabled, the file is renamed to the item's alias on upload.
		 * 0 = keep original filename, 1 = rename to alias.
		 */
		'media_name_from_alias'                  => 0,

		/**
		 * When enabled, deletes the physical file from disk when the media
		 * record is removed.  Use with caution in shared-media setups.
		 * 0 = keep file, 1 = delete file.
		 */
		'media_full_deletion'                    => 0,

		/**
		 * Allowed MIME types for uploads.
		 * Multi-select field → supply a PHP array; it will be json_encode()'d.
		 * Enable all common image formats by default.
		 */
		'media_mime'                             => [
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/bmp',
			'image/webp',
			'image/avif',
		],

		// -----------------------------------------------------------------
		// Component / history settings
		// -----------------------------------------------------------------

		/** Enable Joomla version history for component items. */
		'save_history'                           => 0,

		/** Maximum number of stored revisions per item. */
		'history_limit'                          => 5,

		/**
		 * Remove numeric IDs from product / item URLs (SEF).
		 * 1 = clean URLs (recommended), 0 = include IDs.
		 */
		'sef_ids'                                => 1,

		// -----------------------------------------------------------------
		// Cache settings
		// -----------------------------------------------------------------

		/** Master switch for the component's internal cache layer. */
		'enable_cache'                           => 1,

		/** Cache category tree / listing queries. */
		'cache_categories'                       => 1,
	];

	// =========================================================================
	// Installer lifecycle hooks
	// =========================================================================

	/**
	 * Called before the component is installed or updated.
	 *
	 * Validates Joomla version requirements via the parent implementation.
	 * Extend this method to add any additional pre-install checks.
	 *
	 * @param   string  $type    Type of process: 'install' or 'update'.
	 * @param   object  $parent  The installer object that triggered this call.
	 *
	 * @return  bool  True to proceed with installation, false to abort.
	 *
	 * @throws  \Exception
	 * @since   0.1b
	 */
	public function preflight($type, $parent): bool
	{
		// Parent validates $minimumJoomla (and $minimumPhp when set).
		$result = parent::preflight($type, $parent);

		if (!$result)
		{
			return false;
		}

		// Add any extra pre-flight checks here, e.g. minimum PHP extensions,
		// required third-party tables, or licence validation.

		return true;
	}

	/**
	 * Called after a fresh installation of the component.
	 *
	 * Installs all bundled plugins and modules that ship inside the component
	 * package.  The actual installation logic is shared with {@see update()}.
	 *
	 * @param   object  $parent  The installer object that triggered this call.
	 *
	 * @return  void
	 *
	 * @since   0.2b
	 */
	public function install($parent): void
	{
		$this->installPlugins($parent);
		$this->installModules($parent);
	}

	/**
	 * Called after the component package is updated.
	 *
	 * Re-runs plugin / module installation so that any new or changed bundled
	 * extensions are synchronised with this version.
	 *
	 * @param   object  $parent  The installer object that triggered this call.
	 *
	 * @return  void
	 *
	 * @since   0.2b
	 */
	public function update($parent): void
	{
		$this->installPlugins($parent);
		$this->installModules($parent);
	}

	/**
	 * Called after the component is uninstalled.
	 *
	 * Removes all plugins and modules that were installed as part of this
	 * component package.
	 *
	 * @param   object  $parent  The installer object that triggered this call.
	 *
	 * @return  void
	 *
	 * @since   0.2b
	 */
	public function uninstall($parent): void
	{
		$this->uninstallPlugins($parent);
		$this->uninstallModules($parent);
	}

	/**
	 * Called after install or update of the component is complete.
	 *
	 * Seeds default configuration parameters into #__extensions so the
	 * component works out of the box without requiring manual configuration.
	 *
	 * On a fresh install   → all keys from {@see self::$defaultParams} are set.
	 * On an update         → only NEW keys (absent from current params) are
	 *                        added; existing admin configuration is untouched.
	 *
	 * @param   string  $type    Type of process: 'install', 'update', etc.
	 * @param   object  $parent  The installer object that triggered this call.
	 *
	 * @return  bool
	 *
	 * @since   0.3b
	 */
	public function postflight($type, $parent): bool
	{
		if (in_array($type, ['install', 'update'], true))
		{
			$this->applyDefaultParams($type);
		}

		return true;
	}

	// =========================================================================
	// Default-params helper
	// =========================================================================

	/**
	 * Merges {@see self::$defaultParams} with the params currently stored in
	 * #__extensions and persists the result.
	 *
	 * Strategy:
	 *  - Existing DB values always WIN (array_merge puts $currentParams last,
	 *    so they override the defaults for any key that already exists).
	 *  - New keys from $defaultParams that have no stored value are inserted.
	 *  - PHP array values are json_encode()'d to match Joomla's storage format
	 *    for multi-select / fancy-select fields.
	 *
	 * @param   string  $type  'install' or 'update' (informational only here).
	 *
	 * @return  void
	 *
	 * @since   0.3b
	 */
	private function applyDefaultParams(string $type): void
	{
		$db    = Factory::getContainer()->get('DatabaseDriver');
		$query = $db->getQuery(true);

		// ------------------------------------------------------------------
		// 1. Load the params that are currently stored for this component.
		// ------------------------------------------------------------------
		$query
			->select($db->quoteName('params'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
			->where($db->quoteName('type')    . ' = ' . $db->quote('component'));

		$db->setQuery($query);
		$stored = $db->loadResult();

		// Gracefully handle a missing or malformed params blob.
		$currentParams = (!empty($stored)) ? json_decode($stored, true) : [];

		if (!is_array($currentParams))
		{
			$currentParams = [];
		}

		// ------------------------------------------------------------------
		// 2. Merge: defaults supply missing keys; current params always win.
		//    array_merge($defaults, $current) → $current keys overwrite defaults.
		// ------------------------------------------------------------------
		$mergedParams = array_merge($this->defaultParams, $currentParams);

		// ------------------------------------------------------------------
		// 3. Encode any PHP array values (multi-select fields) to JSON strings
		//    so Joomla can hydrate them correctly via JRegistry / JSON params.
		// ------------------------------------------------------------------
		foreach ($mergedParams as $key => $value)
		{
			if (is_array($value))
			{
				$mergedParams[$key] = json_encode(array_values($value));
			}
		}

		// ------------------------------------------------------------------
		// 4. Persist the merged params back to #__extensions.
		// ------------------------------------------------------------------
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

	/**
	 * Installs or updates every plugin declared in the component manifest.
	 *
	 * After installation the plugin is automatically enabled in #__extensions
	 * so administrators do not need to enable it manually.
	 *
	 * @param   object  $parent  The installer object that called install/update.
	 *
	 * @return  void
	 *
	 * @since   0.2b
	 */
	private function installPlugins($parent): void
	{
		$installationFolder = $parent->getParent()->getPath('source');
		$app                = Factory::getApplication();

		/** @var \SimpleXMLElement $plugins */
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

			$installer = new Installer;
			$installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));

			// Install on first run, update on subsequent runs.
			$result = $this->isAlreadyInstalled('plugin', $pluginName, $pluginGroup)
				? $installer->update($path)
				: $installer->install($path);

			if ($result)
			{
				$app->enqueueMessage(
					sprintf('Plugin %s/%s was installed successfully.', $pluginGroup, $pluginName)
				);
			}
			else
			{
				$app->enqueueMessage(
					sprintf('There was an issue installing the plugin %s/%s.', $pluginGroup, $pluginName),
					'error'
				);
			}

			// Auto-enable the plugin so it is active immediately after install.
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

	/**
	 * Uninstalls every plugin declared in the component manifest.
	 *
	 * The extension_id is looked up from #__extensions before attempting
	 * removal, so a partially installed state is handled gracefully.
	 *
	 * @param   object  $parent  The installer object that called uninstall.
	 *
	 * @return  void
	 *
	 * @since   0.2b
	 */
	private function uninstallPlugins($parent): void
	{
		$app = Factory::getApplication();

		/** @var \SimpleXMLElement $plugins */
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

			// Resolve the internal extension_id for this plugin.
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
				// Plugin was never fully installed; nothing to remove.
				continue;
			}

			$installer = new Installer;
			$installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));
			$result = $installer->uninstall('plugin', $extensionId);

			if ($result)
			{
				$app->enqueueMessage(
					sprintf('Plugin %s was uninstalled successfully.', $pluginName)
				);
			}
			else
			{
				$app->enqueueMessage(
					sprintf('There was an issue uninstalling the plugin %s.', $pluginName),
					'error'
				);
			}
		}
	}

	// =========================================================================
	// Module helpers
	// =========================================================================

	/**
	 * Installs or updates every module declared in the component manifest.
	 *
	 * @param   object  $parent  The installer object that called install/update.
	 *
	 * @return  void
	 *
	 * @since   0.2b
	 */
	private function installModules($parent): void
	{
		$installationFolder = $parent->getParent()->getPath('source');
		$app                = Factory::getApplication();

		/** @var \SimpleXMLElement $modules */
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

			$installer = new Installer;
			$installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));

			// Install on first run, update on subsequent runs.
			$result = $this->isAlreadyInstalled('module', $moduleName)
				? $installer->update($path)
				: $installer->install($path);

			if ($result)
			{
				$app->enqueueMessage(
					sprintf('Module %s was installed successfully.', $moduleName)
				);
			}
			else
			{
				$app->enqueueMessage(
					sprintf('There was an issue installing the module %s.', $moduleName),
					'error'
				);
			}
		}
	}

	/**
	 * Uninstalls every module declared in the component manifest.
	 *
	 * @param   object  $parent  The installer object that called uninstall.
	 *
	 * @return  void
	 *
	 * @since   0.2b
	 */
	private function uninstallModules($parent): void
	{
		$app = Factory::getApplication();

		/** @var \SimpleXMLElement $modules */
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

			// Resolve the internal extension_id for this module.
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
				// Module was never fully installed; nothing to remove.
				continue;
			}

			$installer = new Installer;
			$installer->setDatabase(Factory::getContainer()->get('DatabaseDriver'));
			$result = $installer->uninstall('module', $extensionId);

			if ($result)
			{
				$app->enqueueMessage(
					sprintf('Module %s was uninstalled successfully.', $moduleName)
				);
			}
			else
			{
				$app->enqueueMessage(
					sprintf('There was an issue uninstalling the module %s.', $moduleName),
					'error'
				);
			}
		}
	}

	// =========================================================================
	// Utility helpers
	// =========================================================================

	/**
	 * Checks whether an extension is already present on the filesystem.
	 *
	 * This is used to decide whether to call install() or update() on the
	 * child installer instance.  Filesystem presence is the most reliable
	 * indicator without querying the database (which may be inconsistent after
	 * a partial or failed install).
	 *
	 * @param   string       $type    Extension type: 'plugin', 'module', or 'template'.
	 * @param   string       $name    Extension element name (e.g. 'plg_alfa_cart').
	 * @param   string|null  $folder  Plugin folder / group (required for type 'plugin').
	 *
	 * @return  bool  True if the extension directory exists, false otherwise.
	 *
	 * @since   0.2b
	 */
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
