<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die();

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;

/**
 * Installer / uninstaller script for the Alfa Commerce component.
 *
 *
 * Responsibilities:
 *  - Refuse the install before anything is touched when the site runs a Joomla this
 *    build is not tested on (older than 6.1, or 7 and later), a PHP that is too old
 *    or lacks a required extension, or an Alfa Commerce older than 2.0.0 (preflight).
 *  - Install / update bundled libraries, plugins and modules alongside the component.
 *  - Seed sensible default configuration parameters after install/update (postflight).
 *  - Bulk-sync existing Joomla users and usergroups into Alfa tables on install/update.
 *  - Clean up libraries, plugins, modules and guided tours when the component is
 *    uninstalled, keeping every Alfa table and its data.
 *
 * Sync logic lives entirely in SyncHelper so it is shared with the runtime
 * plugin (PlgSystemAlfasync) and any frontend registration code; there is no
 * duplicated INSERT/SELECT logic anywhere.
 *
 * The default-params system works as follows:
 *  - Fresh install  -> all keys in {@see self::$defaultParams} are written.
 *  - Update         -> only keys absent from the current stored params are added;
 *                     existing admin choices are never touched.
 *  - Multi-select   -> pass a plain PHP array; it is stored as a real JSON array,
 *                     the same shape com_config writes (never a string-encoded copy).
 *
 * Library bundling:
 *  - Libraries are declared in com_alfa.xml under <libraries>:
 *        <library folder="lib_libphonenumber" libraryname="libphonenumber"/>
 *    where `folder`      = path inside the component zip holding the library's files
 *          `libraryname` = matches <libraryname> in the library's own manifest
 *  - Libraries are installed BEFORE plugins/modules so plugins can `use` their classes.
 *  - Libraries are uninstalled AFTER plugins/modules for reverse dependency safety.
 *  - After any library install/update the PSR-4 namespace cache is flushed so the
 *    library's <namespace> tag is picked up on the very next request.
 *
 * @since  1.0.0
 */
class com_alfaInstallerScript extends InstallerScript
{
    // Class-level configuration

    /** @var string */
    protected $extension = 'Alfa Commerce';

    /**
     * Platform floor, enforced in {@see self::preflight()}.
     *
     * Only what is tested is accepted: the release suites
     * run on Joomla 6.1, so a site on 6.0 is told to update Joomla first. Below 5.3
     * nothing would work at all: the admin views build their forms with
     * Joomla\CMS\Form\Form::addControlField() (`@since 5.3.0`, libraries/src/Form/Form.php).
     *
     * @var string
     */
    protected $minimumJoomla = '6.1.0';

    /**
     * The first Joomla version this build is not tested on, refused in
     * {@see self::preflight()}. Joomla 7 is accepted by a later release, once it
     * has been tested.
     *
     * @var string
     */
    protected $untestedJoomla = '7.0.0';

    /**
     * PHP floor, enforced in {@see self::preflight()}.
     *
     * The oldest PHP the release suites run on: 8.3, 8.4 and 8.5 are tested, and
     * 8.4 is recommended. The update server states the same floor as php_minimum,
     * but a package uploaded by hand never reads the update server, so the
     * installer checks it as well.
     *
     * @var string
     */
    protected $minimumPhp = '8.3.0';

    /**
     * PHP extensions the component cannot run without, enforced in
     * {@see self::preflight()}: bcmath (exact decimal arithmetic of stock
     * quantities), intl (text normalisation, currency and date formatting),
     * mbstring (multibyte text: search, aliases, names), gd (image thumbnails
     * and upload validation) and openssl (HTTPS calls to payment gateways and
     * the update server).
     *
     * @var string[]
     */
    protected array $requiredExtensions = ['bcmath', 'intl', 'mbstring', 'gd', 'openssl'];

    /**
     * The oldest Alfa Commerce version this build installs over.
     *
     * 2.0.0 is the first stable release and the schema baseline. From 2.0.0 on the
     * manifest's <update> element has Joomla record the schema version at install
     * and, on update, run every sql/updates/mysql file newer than the recorded one
     * (InstallerAdapter::parseQueries), so a later 2.x installs over 2.0.0 in place.
     * The 1.x betas have no such baseline: Joomla would copy the new files over
     * theirs and leave their schema behind, a site that boots and then fails on its
     * first query. Refusing them is the only honest outcome, and preflight is the
     * last point at which nothing has been touched.
     *
     * @var string
     */
    public const CLEAN_INSTALL_FLOOR = '2.0.0';

    /**
     * The database schema this package's install SQL creates: the newest file in
     * sql/updates/mysql, the version Joomla records for the component at install.
     *
     * The Alfa tables carry it themselves, as schema_version in #__alfa_meta: the
     * install SQL writes it first and {@see self::postflight()} raises it after an
     * update. It stays when the component is uninstalled, together with every
     * other Alfa table, and {@see self::preflight()} reconnects kept tables only
     * when it equals this. A release with a new update file raises it, in the
     * install SQL as well.
     *
     * @var string
     */
    public const SCHEMA_VERSION = '2.0.0';

    /**
     * Whether this run completes an install that never finished: preflight ran
     * its install SQL on Joomla's update route, and postflight does what an
     * install does ({@see self::postflightType()}).
     *
     * @since 2.0.0
     */
    private bool $completingUnfinishedInstall = false;

    /**
     * Default component parameters to seed after install / update.
     *
     * Keys must match the `name` attribute of every <field> in config.xml.
     *
     * The subset of keys listed in SyncHelper::PRICES_DISPLAY_KEYS is passed
     * to SyncHelper::buildDefaultPricesDisplay() to build the per-usergroup
     * prices_display JSON; no hardcoded JSON strings anywhere in this codebase.
     *
     * @var array<string, scalar|array<string>>
     */
    private array $defaultParams = [

        // General / catalog
        'include_subcategories'                => 1,
        'category_filter_subtree'              => 0,
        'show_items_filters'                   => 0,
        'show_items_field_facets'              => 1,
        'show_manufacturers_filters'           => 0,

        // Listings (floor of the category -> menu -> global chain). items_limits
        // deliberately EMPTY: the storefront keeps its standard page sizes, and
        // "All" stays unavailable until a merchant adds a 0 entry themselves.
        'items_limit'                          => 25,
        'items_limits'                         => '',
        'items_ordering'                       => 'a.id DESC',

        // Pricing
        'combination_adjust_target'            => 'final',

        // Stock
        'stock_action'                         => 0,
        'stock_low'                            => 5,
        'backorder'                            => 'deny',
        'negative_physical'                    => 0,
        'over_shipment'                        => 'strict',
        'min_qty'                              => 0,

        // Notifications
        'notifications_history_days'           => 7,

        // Setup checklist / first-run wizard. Only store_name / store_email have
        // config.xml fields; the rest is engine state (deliberately no field,
        // like save_history). Guided tours are seeded from tours/*.json in
        // postflight (TourSeeder: data rows in core tables, no sql/updates).
        'store_name'                           => '',
        'store_email'                          => '',
        'setup_completed'                      => 0,
        'setup_version'                        => '',

        // Search indexing
        'search_index_aliases'                 => 1,
        'search_index_manufacturers'           => 1,
        'search_index_categories'              => 0,
        'search_index_short_desc'              => 0,
        'search_index_full_desc'               => 0,
        'search_index_meta_text'               => 0,

        // Documents
        'documents_issuer'                     => 'com_alfa',

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

        // Currency: the ISO-4217 NUMBER (the `number` column, the Options field's
        // key_field, and what Pricing\Currency / OrderCreator read). NEVER the row id.
        'default_currency'                     => 978,  // EUR

        // Media (main-image format/quality/dimensions live in the optimizer plugin)
        'media_thumbnail_width'                => 200,
        'media_thumbnail_height'               => 200,
        'media_save_location'                  => '/images/commerce',
        'media_placeholder'                    => 'media/com_alfa/images/placeholders/no-image.webp',
        'media_placeholder_items'              => 1,
        'media_placeholder_categories'         => 1,
        'media_placeholder_manufacturers'      => 1,
        'media_url_thumbnail'                  => 'media/com_alfa/images/placeholders/url.webp',
        'media_full_deletion'                  => 1,
        'media_mime'                           => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'image/webp',
            'image/avif',
        ],

        // History: read by Joomla CORE versioning (VersionableModelTrait /
        // Versioning), not by com_alfa code; deliberately no config.xml field.
        'save_history'                         => 0,
        'history_limit'                        => 5,

        // Cache
        'enable_cache'                         => 1,
        'cache_categories'                     => 1,
    ];

    // Installer lifecycle hooks

    /**
     * Pre-flight: the last point at which nothing has been touched.
     *
     * InstallerAdapter::install() calls triggerManifestScript('preflight') at
     * line 714 and only afterwards createExtensionRoot() (730), copyBaseFiles()
     * (740), storeExtension() (758) and parseQueries() (767); on the
     * discover_install route preflight (386) likewise precedes storeExtension
     * (401) and parseQueries (410). triggerManifestScript turns a `false` return
     * into a RuntimeException, which install() catches and hands to
     * Installer::abort(). So a refusal here happens before a single file is
     * copied and before a single query runs: the existing installation is left
     * exactly as it was found.
     *
     * Joomla asks preflight before an uninstall as well (InstallerAdapter::uninstall()),
     * and a false there stops the removal. Removing the component is never refused:
     * a site that has since moved to a Joomla, a PHP or an extension set this build
     * does not accept must still be able to remove it.
     *
     * Six refusals, in order of how early they can be known:
     *
     *  1. The platform floor ({@see self::$minimumJoomla}).
     *  2. A Joomla this build is not tested on yet ({@see self::$untestedJoomla}).
     *  3. The PHP floor ({@see self::$minimumPhp}).
     *  4. A required PHP extension this PHP lacks ({@see self::$requiredExtensions}),
     *     every missing one named.
     *  5. An Alfa Commerce older than {@see self::CLEAN_INSTALL_FLOOR} already
     *     installed. The version is read from
     *     the manifest still on disk; ComponentAdapter::setupUpdates() reads the
     *     same file for its own oldFiles bookkeeping, and copyBaseFiles() has not
     *     run yet, so what is on disk is the OLD manifest, whatever route Joomla
     *     took to get here. The check therefore runs whenever a manifest is found
     *     at all: a genuinely clean install finds none and proceeds, a 2.x
     *     reinstall or update meets the floor and proceeds, and a leftover
     *     1.x tree is refused on 'install' exactly as it is on 'update'.
     *  6. Alfa tables kept in the database that this package cannot reconnect
     *     ({@see self::keptDataRefusal()}). Uninstalling keeps every Alfa table,
     *     and an install runs the install SQL over whatever it finds, which
     *     creates what is missing and changes nothing that exists. That is a
     *     reconnection, never a migration, so it is allowed only over tables at
     *     this package's schema. Asked on the routes that run the install SQL,
     *     and on the update route of an install that never finished (no installed
     *     manifest: Joomla copies it last), which is then completed by running the
     *     install SQL here ({@see self::completeUnfinishedInstall()}).
     *
     * @param   string  $type    Install route: install, update, discover_install, uninstall.
     * @param   object  $parent  The installer adapter.
     *
     * @return  bool  False aborts the install before anything is modified.
     * @since  1.10.0
     */
    public function preflight($type, $parent): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $app = Factory::getApplication();

        if (version_compare($this->platformVersion(), $this->minimumJoomla, 'lt')) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_MINIMUM_JOOMLA',
                    fallback: 'Alfa Commerce %1$s requires Joomla %2$s or newer; this site runs Joomla %3$s.'
                        . ' Update Joomla first, then install Alfa Commerce again.'
                        . ' Nothing was installed and the site was not modified.',
                    values:   [self::CLEAN_INSTALL_FLOOR, $this->minimumJoomla, $this->platformVersion()],
                ),
                'error'
            );

            return false;
        }

        // A pre-release of the next major ("7.0.0-alpha1") counts as that major.
        if (version_compare(preg_replace('/-.*$/', '', $this->platformVersion()), $this->untestedJoomla, 'ge')) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_UNTESTED_JOOMLA',
                    fallback: 'Alfa Commerce %1$s is tested on Joomla %2$s only; this site runs Joomla %3$s,'
                        . ' which it is not tested on yet. Nothing was installed and the site was not modified.',
                    values:   [self::CLEAN_INSTALL_FLOOR, $this->testedJoomla(), $this->platformVersion()],
                ),
                'error'
            );

            return false;
        }

        if (version_compare($this->phpVersion(), $this->minimumPhp, 'lt')) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_MINIMUM_PHP',
                    fallback: 'Alfa Commerce %1$s requires PHP %2$s or newer; this site runs PHP %3$s.'
                        . ' Nothing was installed and the site was not modified.',
                    values:   [self::CLEAN_INSTALL_FLOOR, $this->minimumPhp, $this->phpVersion()],
                ),
                'error'
            );

            return false;
        }

        $missing = $this->missingExtensions();

        if ($missing !== []) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_PHP_EXTENSIONS',
                    fallback: 'Alfa Commerce %1$s requires the PHP extensions %2$s; this site\'s PHP is missing %3$s.'
                        . ' Enable the missing extensions, then install Alfa Commerce again.'
                        . ' Nothing was installed and the site was not modified.',
                    values:   [
                        self::CLEAN_INSTALL_FLOOR,
                        implode(separator: ', ', array: $this->requiredExtensions),
                        implode(separator: ', ', array: $missing),
                    ],
                ),
                'error'
            );

            return false;
        }

        $installed = $this->installedVersion();

        if ($installed !== null && version_compare($installed, self::CLEAN_INSTALL_FLOOR, 'lt')) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_CLEAN_INSTALL_ONLY',
                    fallback: 'Alfa Commerce %1$s requires a clean installation and cannot upgrade the'
                        . ' Alfa Commerce %2$s already on this site. Nothing was modified: no file was'
                        . ' replaced and no database change was made. Remove the existing installation'
                        . ' and install %1$s fresh.',
                    values:   [self::CLEAN_INSTALL_FLOOR, $installed],
                ),
                'error'
            );

            return false;
        }

        // An install that never finished: Joomla takes the update route because the extension row
        // and the component folder exist, but no manifest is installed (it is copied last). That run
        // is the install it is: the install route's kept-data check, then the install SQL.
        $unfinished = $type === 'update' && $installed === null;

        if (\in_array($type, ['install', 'discover_install'], true) || $unfinished) {
            $refusal = $this->keptDataRefusal();

            if ($refusal !== null) {
                $app->enqueueMessage($refusal, 'error');

                return false;
            }
        }

        if ($unfinished) {
            if (!$this->completeUnfinishedInstall(installer: $parent->getParent())) {
                $app->enqueueMessage(
                    $this->installerText(
                        key:      'COM_ALFA_INSTALL_ERROR_UNFINISHED',
                        fallback: 'Alfa Commerce %1$s found an installation that had stopped before it finished, and could'
                            . ' not complete its database tables: the database refused a statement of the installation'
                            . ' SQL. No file was replaced. Correct the cause, then install %1$s again.',
                        values:   [self::CLEAN_INSTALL_FLOOR],
                    ),
                    'error',
                );

                return false;
            }

            $this->completingUnfinishedInstall = true;
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_COMPLETED_UNFINISHED',
                    fallback: 'Alfa Commerce %1$s found an installation that had stopped before it finished, and completed'
                        . ' its database tables; existing tables and rows were left as they were.',
                    values:   [self::CLEAN_INSTALL_FLOOR],
                ),
            );
        }

        return true;
    }

    /**
     * Run this package's install SQL for an install that never finished, before Joomla copies a
     * file, so that nothing after it (the update files, postflight's language and user sync, the
     * tours, the task) builds on a schema the install SQL has not completed.
     *
     * Joomla's own runner (Installer::parseSQLFiles(), the one the install route uses) reads the
     * package's copy: the extension root is pointed at the package's administrator folder for the
     * call and put back after it. The install SQL is re-runnable: it creates what is missing and
     * changes nothing that exists.
     *
     * @param Installer $installer The installer running this package.
     *
     * @return bool False when a statement failed. Joomla's runner reports a statement the
     *              database refused to run; one it refused to prepare is reported here.
     * @since 2.0.0
     */
    protected function completeUnfinishedInstall(Installer $installer): bool
    {
        $manifest = $installer->getManifest();
        $root = $installer->getPath('extension_root');

        $installer->setPath(
            'extension_root',
            rtrim((string) $installer->getPath('source'), '/\\') . '/' . trim((string) $manifest->administration->files['folder']),
        );

        try {
            return $installer->parseSQLFiles($manifest->install->sql) !== false;
        } catch (\RuntimeException $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');

            return false;
        } finally {
            $installer->setPath('extension_root', $root);
        }
    }

    /**
     * Why the Alfa tables kept in this site's database cannot be reconnected by
     * this package, or null when they can (or when there are none).
     *
     * No schema version among them means tables an earlier Alfa Commerce left
     * behind, from before the version was recorded: they need the clean
     * installation the 1.x betas need. A recorded version other than
     * {@see self::SCHEMA_VERSION} means a database this install SQL does not
     * describe: it would create what is missing and leave every other table in
     * its old shape, so the release that matches it has to be installed instead
     * and updated from there.
     *
     * @return  string|null  The refusal, in the site's language when it can be.
     * @since   2.0.0
     */
    private function keptDataRefusal(): ?string
    {
        $kept = $this->keptSchema();

        if ($kept['tables'] === 0) {
            return null;
        }

        if ($kept['schema'] === null) {
            return $this->installerText(
                key:      'COM_ALFA_INSTALL_ERROR_KEPT_DATA_UNMARKED',
                fallback: 'Alfa Commerce %1$s requires a clean installation and cannot use the Alfa Commerce tables'
                    . ' an earlier version left in this site\'s database (their names start with %2$s).'
                    . ' Nothing was modified: no file was replaced and no database change was made.'
                    . ' Back up the database, delete those tables, then install %1$s again.',
                values:   [self::CLEAN_INSTALL_FLOOR, $kept['prefix']],
            );
        }

        if ($kept['schema'] !== self::SCHEMA_VERSION) {
            return $this->installerText(
                key:      'COM_ALFA_INSTALL_ERROR_KEPT_DATA_SCHEMA',
                fallback: 'Alfa Commerce %1$s cannot reconnect the Alfa Commerce data kept in this site\'s database:'
                    . ' its tables are at database schema %2$s, and this package installs schema %3$s.'
                    . ' Reinstalling does not convert a database.'
                    . ' Nothing was modified: no file was replaced and no database change was made.'
                    . ' Install Alfa Commerce %2$s to reconnect the data, then update it.',
                values:   [self::CLEAN_INSTALL_FLOOR, $kept['schema'], self::SCHEMA_VERSION],
            );
        }

        return null;
    }

    /**
     * What this site's database keeps of Alfa Commerce: how many Alfa tables it
     * holds (every Alfa table is named with the site's table prefix followed by
     * "alfa_"), and the schema version recorded among them, or null when none is.
     *
     * @return  array{tables: int, schema: string|null, prefix: string}
     * @since   2.0.0
     */
    protected function keptSchema(): array
    {
        $db     = Factory::getContainer()->get('DatabaseDriver');
        $prefix = strtolower($db->getPrefix() . 'alfa_');
        $tables = array_values(array_filter(
            array:    array_map('strtolower', $db->getTableList()),
            callback: static fn (string $table): bool => str_starts_with($table, $prefix),
        ));
        $schema = null;

        if (\in_array(strtolower($db->replacePrefix('#__alfa_meta')), $tables, true)) {
            $value = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('value'))
                    ->from($db->quoteName('#__alfa_meta'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote('schema_version'))
            )->loadResult();
            $schema = $value === null ? null : (string) $value;
        }

        return ['tables' => \count($tables), 'schema' => $schema, 'prefix' => $db->getPrefix() . 'alfa_'];
    }

    /**
     * The Alfa Commerce version currently installed on this site, or null when
     * none is.
     *
     * Read from the manifest on disk rather than from #__extensions: on the
     * discover_install route the files are present with no database row at all,
     * and the files are what this build is about to overwrite.
     *
     * @return  string|null
     * @since  1.10.0
     */
    protected function installedVersion(): ?string
    {
        $manifest = JPATH_ADMINISTRATOR . '/components/com_alfa/alfa.xml';

        if (!is_file($manifest)) {
            return null;
        }

        $xml = @simplexml_load_file($manifest);

        if ($xml === false) {
            return null;
        }

        $version = trim((string) $xml->version);

        return $version !== '' ? $version : null;
    }

    /**
     * The Joomla version this refusal is measured against.
     *
     * @return  string
     * @since  1.10.0
     */
    protected function platformVersion(): string
    {
        return JVERSION;
    }

    /**
     * The PHP version this refusal is measured against.
     *
     * @return  string
     * @since  2.0.0
     */
    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * Whether the PHP this refusal is measured against has the extension loaded.
     *
     * @param   string  $extension  The extension's name as PHP reports it.
     *
     * @return  bool
     * @since  2.0.0
     */
    protected function extensionLoaded(string $extension): bool
    {
        return \extension_loaded(extension: $extension);
    }

    /**
     * The required PHP extensions this PHP lacks, in the order of
     * {@see self::$requiredExtensions}.
     *
     * @return  string[]
     * @since  2.0.0
     */
    private function missingExtensions(): array
    {
        return array_values(array: array_filter(
            array:    $this->requiredExtensions,
            callback: fn (string $extension): bool => !$this->extensionLoaded(extension: $extension),
        ));
    }

    /**
     * The tested Joomla range as people read it: the floor's major and minor
     * followed by "+" ("6.1+"). The range ends before {@see self::$untestedJoomla}.
     *
     * @return  string
     * @since  2.0.0
     */
    private function testedJoomla(): string
    {
        [$major, $minor] = array_pad(explode('.', $this->minimumJoomla), 2, '0');

        return $major . '.' . $minor . '+';
    }

    /**
     * An installer message in the site's language, falling back to English.
     *
     * A clean install has no com_alfa language file on disk when preflight runs,
     * so Text::_() answers with the key itself. A refusal has to be readable in
     * exactly that case, which is the only case a clean-install refusal happens in.
     *
     * @param   string    $key       Language key.
     * @param   string    $fallback  English sprintf pattern used when the key is unresolved.
     * @param   string[]  $values    Substituted into whichever pattern is used.
     *
     * @return  string
     * @since  1.10.0
     */
    private function installerText(string $key, string $fallback, array $values): string
    {
        $pattern = Text::_($key);

        return vsprintf($pattern === $key ? $fallback : $pattern, $values);
    }

    /**
     * Install-time hook.
     *
     * Order matters: the component's namespace first (the bundled extensions'
     * install scripts use component classes), then libraries (plugins may `use`
     * library classes), then plugins, then modules.
     *
     * @param   object  $parent
     *
     * @return  void
     * @since  1.0.0
     */
    public function install($parent): void
    {
        $this->registerComponentNamespace();
        $this->installLibraries($parent);
        $this->installPlugins($parent);
        $this->installModules($parent);
    }

    /**
     * Update-time hook.
     *
     * Same order as install(): a library might have been added in this
     * release that a new/updated plugin relies on.
     *
     * @param   object  $parent
     *
     * @return  void
     * @since  1.0.0
     */
    public function update($parent): void
    {
        $this->registerComponentNamespace();
        $this->installLibraries($parent);
        $this->installPlugins($parent);
        $this->installModules($parent);
    }

    /**
     * Make the component's administrator classes autoloadable in this request.
     *
     * Joomla adds a newly installed extension's namespace to its autoloader only
     * on a later request, while the bundled extensions are installed in this one
     * and their install scripts use component classes (the standard shipments
     * plugin completes its log table with PluginLogTable). The installer has
     * copied the component's files before it calls install(), update() or
     * postflight(), so the folder is in place.
     *
     * @return  void
     * @since  2.0.0
     */
    private function registerComponentNamespace(): void
    {
        \JLoader::registerNamespace(
            'Alfa\\Component\\Alfa\\Administrator',
            JPATH_ADMINISTRATOR . '/components/com_alfa/src'
        );
    }

    /**
     * Uninstall-time hook.
     *
     * Reverse of install order: remove plugins and modules first (they may
     * reference classes from our libraries), then the libraries themselves,
     * then the guided tours, which Joomla does not remove with a component.
     *
     * Every Alfa table and every row in it stays: orders, documents, payments,
     * credit, customers, stock, the catalogue and its translations, and the
     * tables of the bundled plugins. The component declares no uninstall SQL,
     * so neither an uninstall nor the rollback of a failed install can drop
     * them (Joomla runs a manifest's uninstall SQL as that rollback too), and
     * installing the same schema again reconnects the shop to its data
     * ({@see self::preflight()}). Joomla removes its own records of the
     * extension: its row, Options, permissions, admin menu and files.
     *
     * @param   object  $parent
     *
     * @return  void
     * @since  1.0.0
     */
    public function uninstall($parent): void
    {
        $this->registerComponentNamespace();
        $this->uninstallPlugins($parent);
        $this->uninstallModules($parent);
        $this->uninstallLibraries($parent);
        $this->removeGuidedTours();
    }

    /**
     * Remove the component's guided tours, with their steps and the per-user keys
     * that refer to them. Joomla removes a component's menu items and assets on
     * uninstall, but not the tours it seeded.
     *
     * @return  void
     * @since  2.0.0
     */
    private function removeGuidedTours(): void
    {
        $app = Factory::getApplication();

        try {
            $removed = \Alfa\Component\Alfa\Administrator\Setup\TourSeeder::removeAll();
            $app->enqueueMessage(sprintf('Alfa guided tours removed: %d.', \count($removed)));
        } catch (\Throwable $e) {
            $app->enqueueMessage('The guided tours of Alfa Commerce were not removed: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * Post-flight: runs after install or update is complete.
     *
     * Execution order:
     *  0. recordSchemaVersion()             - the schema the Alfa tables are at.
     *  1. applyDefaultParams()              - seeds / merges component config.
     *     provisionDownloadStore()          - the download store and its Options path.
     *  2. SyncHelper::bulkSyncUsers()       - batch-inserts missing users.
     *  3. SyncHelper::bulkSyncUsergroups()  - batch-inserts missing groups.
     *
     * An install that never finished, completed by preflight, runs as an
     * install ({@see self::postflightType()}).
     *
     * @param   string  $type
     * @param   object  $parent
     *
     * @return  bool
     * @since  1.0.0
     */
    public function postflight($type, $parent): bool
    {
        $type = $this->postflightType(type: $type);

        if (!in_array($type, ['install', 'update'], true))
        {
            return true;
        }

        // Every Alfa\Component\Alfa\Administrator\... class autoloads here: install()
        // and update() registered the namespace already, and it is registered again
        // so this method does not depend on them. SyncHelper::syncLanguageSchema()
        // pulls in further helpers (e.g. MultilingualAliasConfig) that a single
        // require_once would miss.
        $this->registerComponentNamespace();

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

        // 0. The Alfa tables are at this package's schema now: say so among them.
        $this->recordSchemaVersion();

        // 1. Seed params first: SyncHelper::buildDefaultPricesDisplay() reads
        //    from $this->defaultParams at install time (not from ComponentHelper)
        //    so ordering doesn't actually matter here, but it's good practice.
        $this->applyDefaultParams($type);

        // 1b. Point the shop's delivery destination at the country field the
        //     install SQL just seeded. Fresh installs only; see the method.
        $this->seedDeliveryPlaceMapping($type);

        // 1c. The download store: its recorded location, the folder, and the
        //     Options path that shows it (a reinstall resets the Options).
        $this->provisionDownloadStore();

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
        //    MultilingualHelper is loaded explicitly; the component autoloader
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
        // CDN (single source of truth: IntegrityHelper::verifyAgainstOfficial), so there
        // is no local baseline to capture. The verdict is cached for 24h, though, and an
        // update changes the files, so drop the cache here, exactly as the Tools view does
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

        // Guided tours: make the DB match the shipped tours/*.json (upsert by uid,
        // stable ids, version-stamped, cheap on updates, see TourSeeder).
        try
        {
            $seederPath = JPATH_ADMINISTRATOR . '/components/com_alfa/src/Setup/TourSeeder.php';

            if (file_exists($seederPath))
            {
                require_once $seederPath;

                $tours = \Alfa\Component\Alfa\Administrator\Setup\TourSeeder::seedAll();

                if ($tours['seeded'] !== [] || $tours['removed'] !== [])
                {
                    Factory::getApplication()->enqueueMessage(
                        'Alfa guided tours: ' . count($tours['seeded']) . ' seeded, ' . count($tours['removed']) . ' removed.',
                    );
                }

                if ($tours['errors'] !== [])
                {
                    Factory::getApplication()->enqueueMessage('Alfa guided tours: ' . implode('; ', $tours['errors']), 'warning');
                }
            }
        }
        catch (\Throwable $e)
        {
            // Non-fatal: the Tools "Reseed tours" action re-runs this on demand.
            Factory::getApplication()->enqueueMessage('Alfa guided tours seeding skipped: ' . $e->getMessage(), 'warning');
        }

        // The scheduled task that prepares the list prices of new combinations of customer
        // groups: without it a new combination is never prepared. Created enabled, every
        // five minutes, unless a task of its routine exists (one the shop disabled stays so);
        // the lazy scheduler runs it with no cron. The task plugin is installed by now.
        try
        {
            $taskId = \Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService::ensurePreparationTask();

            if ($taskId !== null)
            {
                $app->enqueueMessage(sprintf('Scheduled task #%d created: it prepares the list prices of customer groups every 5 minutes.', $taskId));
            }
        }
        catch (\Throwable $e)
        {
            $app->enqueueMessage('The scheduled task that prepares the list prices of customer groups was not created: ' . $e->getMessage()
                . ' Create one under System > Scheduled Tasks.', 'warning');
        }

        return true;
    }

    /**
     * The operation postflight performs. An install that never finished, which
     * preflight completed on Joomla's update route, is the install it is: the
     * steps a fresh install takes (the delivery-place mapping) are owed to it.
     * Any other run is what Joomla says it is.
     *
     * @param string $type The installer's operation.
     *
     * @since 2.0.0
     */
    protected function postflightType(string $type): string
    {
        return $this->completingUnfinishedInstall && $type === 'update' ? 'install' : $type;
    }

    // Obsolete-file cleanup (version-keyed, mirrors sql/updates)

    /**
     * Record {@see self::SCHEMA_VERSION} as the schema the Alfa tables are at,
     * once an install or update has brought them there.
     *
     * The install SQL wrote it already on a fresh install; an update raises it
     * after Joomla has run the update files. It is never lowered: an older
     * package installed over newer tables does not make them older, and a
     * later reinstall must still be told what they are.
     *
     * A failure is said, never a reason to fail the install: the shop works,
     * and only a later reinstall over the kept data would be refused.
     *
     * @return  void
     * @since   2.0.0
     */
    private function recordSchemaVersion(): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $recorded = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('value'))
                    ->from($db->quoteName('#__alfa_meta'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote('schema_version'))
            )->loadResult();

            if ($recorded === null) {
                $db->setQuery(
                    $db->createQuery()
                        ->insert($db->quoteName('#__alfa_meta'))
                        ->columns($db->quoteName(['name', 'value']))
                        ->values($db->quote('schema_version') . ', ' . $db->quote(self::SCHEMA_VERSION))
                )->execute();
            } elseif (version_compare((string) $recorded, self::SCHEMA_VERSION, '<')) {
                $db->setQuery(
                    $db->createQuery()
                        ->update($db->quoteName('#__alfa_meta'))
                        ->set($db->quoteName('value') . ' = ' . $db->quote(self::SCHEMA_VERSION))
                        ->where($db->quoteName('name') . ' = ' . $db->quote('schema_version'))
                )->execute();
            }
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                'The database schema version of Alfa Commerce was not recorded: ' . $e->getMessage()
                . ' A reinstall over the kept data will be refused until it is.',
                'error'
            );
        }
    }

    // Default-params helper

    /**
     * Merges $defaultParams with currently stored params and persists the result.
     *
     * Strategy: existing DB values win (array_merge puts $currentParams last).
     * New keys from $defaultParams are inserted; nothing is ever overwritten.
     *
     * @param   string  $type
     *
     * @return  void
     * @since  1.0.0
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

        // Array values nest as REAL JSON arrays via the single json_encode
        // below, the same shape com_config writes on save. They must NOT be
        // pre-encoded into strings: Registry->get() would then return the
        // string, and e.g. MediaHelper::resolveAllowedMimes() casts it to one
        // bogus one-element list that matches no MIME: every upload rejected
        // on a fresh install until Options was saved once.

        $query
            ->clear()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($mergedParams)))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
            ->where($db->quoteName('type')    . ' = ' . $db->quote('component'));

        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Map the shop's delivery destination to the country field the install
     * SQL seeded, by the id the database actually gave it.
     *
     * WHY THIS IS WRITTEN AT ALL, when an unconfigured shop with exactly one
     * place field already infers the same answer: an explicit mapping is a
     * decision the merchant can SEE in Options and change, and it stays a
     * decision when they later add a second place field; inference would go
     * ambiguous at that moment and stop offering place-restricted methods on a
     * shop that had been working. Seeding it means adding a field never
     * silently changes where existing orders are priced to.
     *
     * NEVER A HARDCODED ID. The seed's literal `4` is a fresh AUTO_INCREMENT,
     * not a promise: the same install re-run against a table that already holds
     * rows, or a merchant who deletes and rebuilds the field, gets a different
     * one. The row is found by what it IS (a published place field shown in
     * the delivery section), which is the same question the runtime resolver
     * asks.
     *
     * FRESH INSTALLS ONLY. On an update the mapping is whatever the merchant
     * has: a value they chose is theirs to keep, and an empty one is a shop
     * that has been inferring correctly and must go on doing so. Writing here
     * would silently convert an inferring shop into a mapped one, so that
     * deleting the field would later report "mapped to a field that is gone"
     * instead of "no field": a different diagnosis of the same shop.
     *
     * A DELETED MAPPING STAYS BROKEN, which is the point. Nothing repairs this
     * value afterwards; FieldsHelper answers UNMAPPED and the checkout says so,
     * because quietly re-pointing the destination at another field would price
     * orders against an address the merchant never chose.
     *
     * @param   string  $type  The installer's operation: only 'install' seeds.
     *
     * @return  void
     * @since  1.10.0
     */
    private function seedDeliveryPlaceMapping(string $type): void
    {
        if ($type !== 'install')
        {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $stored = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        )->loadResult();

        $params = (!empty($stored)) ? json_decode($stored, true) : [];

        if (!is_array($params))
        {
            $params = [];
        }

        // Already decided, by a merchant, or by a previous run of this.
        if ((int) ($params['delivery_place_field'] ?? 0) > 0)
        {
            return;
        }

        // The same eligibility the runtime resolver applies: the field type
        // that carries a place, published, and shown in the delivery section.
        $candidates = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_form_fields'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('country'))
                ->where($db->quoteName('state') . ' = 1')
                ->where($db->quoteName('shipping') . ' > 0')
                ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC')
        )->loadColumn() ?: [];

        // Exactly one is the only case that can be answered without guessing.
        // None, or several, is left unmapped on purpose: the resolver reports
        // MISSING or AMBIGUOUS and the checkout asks the merchant to choose,
        // which is the whole reason this setting exists.
        if (\count($candidates) !== 1)
        {
            return;
        }

        $params['delivery_place_field'] = (int) $candidates[0];

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        )->execute();
    }

    /**
     * Settle the download store and show its path in the Options.
     *
     * The store lives outside every extension folder and its path is recorded
     * among the Alfa tables, so an uninstall leaves the purchased files with
     * the rows that point at them, and a reinstall finds them again
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Download\Storage::provisionRoot()}).
     * Joomla resets the Options on a reinstall; the recorded path is written
     * back into `downloads_storage_path` here, and a path the shop saved
     * there is recorded. Idempotent: it creates what is missing only. Then
     * the store's direct-download protection is probed (install and update
     * are two of the four occasions).
     *
     * A failure is said, never a reason to fail the install: the shop works,
     * and only its downloadable files wait for the store.
     *
     * @return  void
     * @since   2.0.0
     */
    private function provisionDownloadStore(): void
    {
        // An install request does not boot the component, so its log categories are not
        // registered yet: the messages below point to the downloads log, which must exist.
        \Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog::register();

        $db = Factory::getContainer()->get('DatabaseDriver');

        try
        {
            $stored = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            )->loadResult();

            $params = (!empty($stored)) ? json_decode($stored, true) : [];

            if (!is_array($params))
            {
                $params = [];
            }

            $configured = (string) ($params['downloads_storage_path'] ?? '');
            $root       = \Alfa\Component\Alfa\Administrator\Service\Download\Storage::provisionRoot($configured);

            if ($configured !== $root)
            {
                $params['downloads_storage_path'] = $root;

                $db->setQuery(
                    $db->createQuery()
                        ->update($db->quoteName('#__extensions'))
                        ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                )->execute();
            }

        }
        catch (\Throwable $e)
        {
            // The reason's own words go to the log; the page says which step failed.
            \Joomla\CMS\Log\Log::add('Download store setup failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'com_alfa.downloads');
            Factory::getApplication()->enqueueMessage(
                'The download store of Alfa Commerce was not set up: its folder or its recorded path could not be made'
                . ' (the reason is in the downloads log, com_alfa_downloads.php in the Joomla log folder). Downloadable products cannot be uploaded or downloaded until it'
                . ' is: set the path under Options > Downloads, or install again.',
                'error'
            );

            return;
        }

        // Install and update are two of the four occasions the store's direct-download protection
        // is probed. A result that is not verified, or that could not be saved, is said here and
        // on every Alfa administrator page until a check verifies it.
        try
        {
            $app = Factory::getApplication();
            $app->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
            $protection = new \Alfa\Component\Alfa\Administrator\Service\Download\StoreProtection(knownRoot: $root);
            $result     = $protection->check();
            $warning    = $protection->warning($result);

            if ($warning !== null)
            {
                $app->enqueueMessage($warning, $result->state === $protection::STATE_EXPOSED ? 'error' : 'warning');
            }
        }
        catch (\Throwable $e)
        {
            \Joomla\CMS\Log\Log::add('Download store protection check failed at install: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'com_alfa.downloads');
            Factory::getApplication()->enqueueMessage(
                'The download store of Alfa Commerce is set up, but its protection against direct downloads could not be'
                . ' checked (the reason is in the downloads log, com_alfa_downloads.php in the Joomla log folder): treat purchased files as downloadable without buying'
                . ' them until Check again succeeds (Options > Downloads).',
                'error'
            );
        }
    }

    // Library helpers

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
     * clears caches, leading to a "class not found" on first use.
     *
     * @param   object  $parent
     *
     * @return  void
     * @since  1.0.0
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
                    sprintf('Library source missing at %s; skipped.', $path),
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
     * @since  1.0.0
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
     * @since  1.0.0
     */
    private function flushNamespaceCache(): void
    {
        $cacheFile = JPATH_CACHE . '/autoload_psr4.php';

        if (is_file($cacheFile))
        {
            @unlink($cacheFile);
        }
    }

    // Plugin helpers

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

    /**
     * Uninstall every plugin declared in the package manifest by looking up its
     * extension id and running the Joomla installer for each.
     *
     * @param   mixed  $parent  The installer adapter providing the package manifest
     *
     * @return  void
     * @since  1.0.0
     */
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

    // Module helpers

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

    /**
     * Uninstall every module declared in the package manifest by looking up its
     * extension id and running the Joomla installer for each.
     *
     * @param   mixed  $parent  The installer adapter providing the package manifest
     *
     * @return  void
     * @since  1.0.0
     */
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

    // Utility

    /**
     * Cheap filesystem check for "is this extension already installed?".
     *
     * For libraries, $name is the full `libraryname` (may contain a slash,
     * e.g. "giggsey/locale") - mirrors the path produced by LibraryAdapter.
     *
     * @param   string       $type    One of: plugin, module, template, library
     * @param   string       $name    Extension element name (plugin/module/template name, or libraryname)
     * @param   string|null  $folder  Plugin group (only used for plugins)
     *
     * @return  bool
     * @since  1.0.0
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
