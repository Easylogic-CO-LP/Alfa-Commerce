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
 *  - Clean up libraries, plugins, modules, guided tours and the language files earlier
 *    versions left when the component is uninstalled, keeping every Alfa table and its data.
 *
 * Sync logic lives entirely in SyncHelper so it is shared with the runtime
 * plugin (PlgSystemAlfasync) and any frontend registration code; there is no
 * duplicated INSERT/SELECT logic anywhere.
 *
 * The steps after the install SQL (the schema marker, the Options, the delivery
 * mapping, the download store, the users, groups and language tables) are the
 * component's own (Service\Setup\InstallSteps), shared with Clear everything,
 * which recreates a fresh installation with them. The installed Options are
 * listed once, in Service\Setup\ComponentOptions::DEFAULTS:
 *  - Fresh install  -> all of them are written.
 *  - Update         -> only keys absent from the current stored params are added;
 *                     existing admin choices are never touched.
 * This script says what each step did on the installation page.
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
    public const SCHEMA_VERSION = '2.1.4';

    /**
     * Whether this run completes an install that never finished: preflight ran
     * its install SQL on Joomla's update route, and postflight does what an
     * install does ({@see self::postflightType()}).
     *
     * @since 2.0.0
     */
    private bool $completingUnfinishedInstall = false;

    /**
     * The version of this package ({@see self::ownVersion()}), set in preflight. The
     * installer's messages name it; {@see self::CLEAN_INSTALL_FLOOR} is only the
     * oldest version an update may start from.
     *
     * @since 2.0.1
     */
    private string $packageVersion = self::CLEAN_INSTALL_FLOOR;

    /**
     * The Alfa Commerce version installed before this run, read in preflight from the manifest
     * still on disk ({@see self::installedVersion()}); null on a fresh install. The update removes
     * the files of {@see self::OBSOLETE_LANGUAGE_FILES} only when it starts below the release
     * that dropped them.
     *
     * @since 2.1.1
     */
    private ?string $versionBefore = null;

    /**
     * Whether a Clear everything did not finish on this site (it failed, or its process died), read
     * in preflight. Core's schema may then be half made: the update route runs no update file over
     * it, the install route does not judge the kept tables, and postflight leaves the steps that
     * write Alfa data to Clear everything's Run again ({@see self::postflight()}). A paused Clear
     * everything left core's schema whole, and the installer behaves normally.
     *
     * @since 2.1.0
     */
    private bool $resetUnfinished = false;

    /**
     * The link tables: two ids per row, each pair meaning one link. Schema 2.0.1
     * keys every one of them on its pair (sql/updates/mysql/2.0.1.sql), so a
     * repeated pair is reduced to one row before the key is added.
     *
     * @var array<string, array{0: string, 1: string}>
     * @since 2.0.1
     */
    private const LINK_TABLES = [
        '#__alfa_categories_usergroups' => ['category_id', 'usergroup_id'],
        '#__alfa_categories_users' => ['category_id', 'user_id'],
        '#__alfa_discount_categories' => ['discount_id', 'category_id'],
        '#__alfa_discount_manufacturers' => ['discount_id', 'manufacturer_id'],
        '#__alfa_discount_places' => ['discount_id', 'place_id'],
        '#__alfa_discount_usergroups' => ['discount_id', 'usergroup_id'],
        '#__alfa_discount_users' => ['discount_id', 'user_id'],
        '#__alfa_form_fields_usergroups' => ['field_id', 'usergroup_id'],
        '#__alfa_form_fields_users' => ['field_id', 'user_id'],
        '#__alfa_item_field_categories' => ['item_field_id', 'category_id'],
        '#__alfa_items_categories' => ['item_id', 'category_id'],
        '#__alfa_items_manufacturers' => ['item_id', 'manufacturer_id'],
        '#__alfa_items_usergroups' => ['item_id', 'usergroup_id'],
        '#__alfa_items_users' => ['item_id', 'user_id'],
        '#__alfa_payment_categories' => ['payment_id', 'category_id'],
        '#__alfa_payment_manufacturers' => ['payment_id', 'manufacturer_id'],
        '#__alfa_payment_places' => ['payment_id', 'place_id'],
        '#__alfa_payment_usergroups' => ['payment_id', 'usergroup_id'],
        '#__alfa_payment_users' => ['payment_id', 'user_id'],
        '#__alfa_shipment_categories' => ['shipment_id', 'category_id'],
        '#__alfa_shipment_manufacturers' => ['shipment_id', 'manufacturer_id'],
        '#__alfa_shipment_places' => ['shipment_id', 'place_id'],
        '#__alfa_shipment_usergroups' => ['shipment_id', 'usergroup_id'],
        '#__alfa_shipment_users' => ['shipment_id', 'user_id'],
        '#__alfa_tax_categories' => ['tax_id', 'category_id'],
        '#__alfa_tax_manufacturers' => ['tax_id', 'manufacturer_id'],
        '#__alfa_tax_places' => ['tax_id', 'place_id'],
        '#__alfa_tax_usergroups' => ['tax_id', 'usergroup_id'],
        '#__alfa_tax_users' => ['tax_id', 'user_id'],
        '#__alfa_cart_rule_condition_values' => ['id_condition', 'id_value'],
        '#__alfa_cart_rules_users' => ['id_cart_rule', 'user_id'],
        '#__alfa_cart_rules_usergroups' => ['id_cart_rule', 'usergroup_id'],
    ];

    /**
     * Plain keys of schema 2.0.0 that the 2.0.1 primary key makes redundant
     * (the same columns, or its first column alone).
     *
     * @var array<string, string>
     * @since 2.0.1
     */
    private const REDUNDANT_LINK_KEYS = [
        '#__alfa_categories_usergroups' => 'category_id',
        '#__alfa_categories_users' => 'category_id',
        '#__alfa_item_field_categories' => 'idx_item_field_id',
        '#__alfa_items_categories' => 'idx_item_id',
        '#__alfa_items_manufacturers' => 'idx_item_id',
        '#__alfa_items_usergroups' => 'item_id',
        '#__alfa_items_users' => 'item_id',
        '#__alfa_cart_rule_condition_values' => 'idx_id_condition',
        '#__alfa_cart_rules_users' => 'idx_rule_user',
        '#__alfa_cart_rules_usergroups' => 'idx_rule_group',
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
     * The state of Clear everything refuses nothing: running, paused or unfinished, Alfa Commerce
     * may be installed, updated or uninstalled (an install over an unfinished run leaves its data
     * steps and update files to Run again, below). The uninstall route returns at once. On the
     * other routes, six refusals, in order of how early they can be known:
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
     * Joomla takes the update route whenever the component's folder exists. A folder with no
     * extension row is a stray one, whether or not its manifest is still in it, and the run is
     * put on Joomla's install route: a fresh install, which runs the install SQL (over kept
     * tables only after the kept-data check) and records its schema, so no update file runs
     * from a schema Joomla has no record of.
     *
     * @param   string  $type    Install route: install, update, discover_install, uninstall.
     * @param   object  $parent  The installer adapter.
     *
     * @return  bool  False aborts the install before anything is modified.
     * @since  2.0.0
     */
    public function preflight($type, $parent): bool
    {
        $app = Factory::getApplication();

        // Never refused for Clear everything: running, paused or unfinished, Alfa Commerce may be
        // updated, uninstalled or installed again. Over an unfinished run the data steps wait for
        // Run again (below).
        if ($type === 'uninstall') {
            return true;
        }

        $this->packageVersion = $this->ownVersion();
        $this->resetUnfinished = \in_array($this->resetPhase(), ['failed', 'running'], true);

        if (version_compare($this->platformVersion(), $this->minimumJoomla, 'lt')) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_MINIMUM_JOOMLA',
                    fallback: 'Alfa Commerce %1$s requires Joomla %2$s or newer; this site runs Joomla %3$s.'
                        . ' Update Joomla first, then install Alfa Commerce again.'
                        . ' Nothing was installed and the site was not modified.',
                    values:   [$this->packageVersion, $this->minimumJoomla, $this->platformVersion()],
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
                    values:   [$this->packageVersion, $this->testedJoomla(), $this->platformVersion()],
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
                    values:   [$this->packageVersion, $this->minimumPhp, $this->phpVersion()],
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
                        $this->packageVersion,
                        implode(separator: ', ', array: $this->requiredExtensions),
                        implode(separator: ', ', array: $missing),
                    ],
                ),
                'error'
            );

            return false;
        }

        $installed = $this->installedVersion();
        $this->versionBefore = $installed;

        if ($installed !== null && !\Alfa\Component\Alfa\Administrator\Service\Setup\AlfaVersion::of(version: $installed)->atLeast(minimum: self::CLEAN_INSTALL_FLOOR)) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_CLEAN_INSTALL_ONLY',
                    fallback: 'Alfa Commerce %1$s requires a clean installation and cannot upgrade the'
                        . ' Alfa Commerce %2$s already on this site. Nothing was modified: no file was'
                        . ' replaced and no database change was made. Remove the existing installation'
                        . ' and install %1$s fresh.',
                    values:   [$this->packageVersion, $installed],
                ),
                'error'
            );

            return false;
        }

        // A stray component folder (a broken uninstall, a folder or a row removed by hand): Joomla takes the
        // update route because the folder exists, but it has no record of the component, so it would run
        // every update file from schema 0.0.0. Joomla's install route makes the fresh install it is.
        if ($type === 'update' && $this->componentExtensionId() === 0) {
            $parent->setRoute('install');
            $type = 'install';
        }

        // An install that never finished: Joomla takes the update route because the extension row
        // and the component folder exist, but no manifest is installed (it is copied last). That run
        // is the install it is: the install route's kept-data check, then the install SQL.
        $unfinished = $type === 'update' && $installed === null;

        // Over an unfinished Clear everything the kept tables are half made, and Run again makes them
        // whole from this package's install SQL: they are not judged here.
        if ((\in_array($type, ['install', 'discover_install'], true) || $unfinished) && !$this->resetUnfinished) {
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
                        values:   [$this->packageVersion],
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
                    values:   [$this->packageVersion],
                ),
            );
        }

        // Over an unfinished Clear everything Joomla runs no update file: the schema it would change may
        // be half made, and Run again makes it from this package's install SQL.
        if ($type === 'update' && !$unfinished && $this->resetUnfinished) {
            try {
                $this->skipUpdateFiles(installer: $parent->getParent());
            } catch (\Throwable $e) {
                $app->enqueueMessage(
                    $this->installerText(
                        key:      'COM_ALFA_INSTALL_ERROR_RESET_SCHEMA',
                        fallback: 'Alfa Commerce %1$s could not prepare its update over the unfinished Clear everything: %2$s'
                            . ' No file was replaced and no table was changed.',
                        values:   [$this->packageVersion, $e->getMessage()],
                    ),
                    'error',
                );

                return false;
            }
        }

        // An update keys the link tables on their pair of ids (2.0.1.sql), which Joomla runs after this
        // method. A pair written twice before then is reduced to one row first, or the key is refused.
        if ($type === 'update' && !$unfinished && !$this->resetUnfinished) {
            try {
                $this->reduceRepeatedLinks();
            } catch (\Throwable $e) {
                $app->enqueueMessage(
                    $this->installerText(
                        key:      'COM_ALFA_INSTALL_ERROR_LINK_REPEATS',
                        fallback: 'Alfa Commerce %1$s could not prepare its link tables for the update: %2$s'
                            . ' No file was replaced and no table was changed. Correct the cause, then update again.',
                        values:   [$this->packageVersion, $e->getMessage()],
                    ),
                    'error',
                );

                return false;
            }
        }

        return true;
    }

    /**
     * The version in the manifest beside this script: the package's own while Joomla installs it
     * (the script runs from the package), the installed one afterwards.
     *
     * @return  string
     * @since   2.0.1
     */
    private function ownVersion(): string
    {
        self::loadVersionReader();

        return \Alfa\Component\Alfa\Administrator\Service\Setup\AlfaVersion::ofManifest(path: __DIR__ . '/alfa.xml')?->toString()
            ?? self::CLEAN_INSTALL_FLOOR;
    }

    /**
     * Load the version reader this script ships with (Service\Setup\AlfaVersion).
     *
     * Preflight runs before Joomla copies this package's files, so an older installed component
     * has no such class and its autoloader is not asked. The class is taken from beside this
     * script: from the package while Joomla installs it (administrator/src), from the installed
     * component when Joomla runs the script there (src). A class this request has already loaded
     * is used as it is.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When neither place holds it: the package is incomplete.
     *
     * @since   2.1.0
     */
    private static function loadVersionReader(): void
    {
        self::loadFromPackage(
            class: \Alfa\Component\Alfa\Administrator\Service\Setup\AlfaVersion::class,
            file:  'Service/Setup/AlfaVersion.php',
            what:  'read versions',
        );
    }

    /**
     * Load the installer gate this script ships with (Service\Setup\AlfaInstallerGate), the one every
     * Alfa extension's installer uses, the same way as the version reader: from beside this script.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When neither place holds it: the package is incomplete.
     *
     * @since   2.1.0
     */
    private static function loadInstallerGate(): void
    {
        self::loadFromPackage(
            class: \Alfa\Component\Alfa\Administrator\Service\Setup\AlfaInstallerGate::class,
            file:  'Service/Setup/AlfaInstallerGate.php',
            what:  'read the state of Clear everything',
        );
    }

    /**
     * Load one of the plain classes preflight uses from beside this script: from the package while
     * Joomla installs it (administrator/src), from the installed component when Joomla runs the
     * script there (src). The installed tree's autoloader is never asked: an older installed
     * component has no such class. A class this request has already loaded is used as it is.
     *
     * @param   string  $class  The class.
     * @param   string  $file   Its file, relative to the component's src folder.
     * @param   string  $what   What the installer cannot do without it, for the message.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When neither place holds it: the package is incomplete.
     *
     * @since   2.1.0
     */
    private static function loadFromPackage(string $class, string $file, string $what): void
    {
        if (class_exists($class, false)) {
            return;
        }

        foreach (['/administrator/src/', '/src/'] as $folder) {
            if (is_file(__DIR__ . $folder . $file)) {
                require_once __DIR__ . $folder . $file;

                return;
            }
        }

        throw new \RuntimeException('The Alfa Commerce installer cannot ' . $what . ': ' . $file . ' is not beside ' . __FILE__ . '.');
    }

    /**
     * The phase of a Clear everything that has not finished on this site, or null when none (or when
     * the state table does not exist yet). Read through the installer gate's plain query.
     *
     * @return  string|null  running (at work, or stopped without a word), paused or failed.
     *
     * @since   2.1.0
     */
    protected function resetPhase(): ?string
    {
        self::loadInstallerGate();

        try {
            return \Alfa\Component\Alfa\Administrator\Service\Setup\AlfaInstallerGate::phase();
        } catch (\Throwable) {
            // No state can be read: no Clear everything is known to be unfinished.
            return null;
        }
    }

    /**
     * Let Joomla's update route run no update file over an unfinished Clear everything: its schema
     * record of the component is set to this package's newest update file, and Joomla runs only the
     * files newer than the record (Installer::parseSchemaUpdates()). Run again then makes the whole
     * schema from this package's install SQL.
     *
     * @param   Installer  $installer  The installer running this package.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the package holds no update file or the record cannot be written.
     *
     * @since   2.1.0
     */
    protected function skipUpdateFiles(Installer $installer): void
    {
        $versions = array_map(
            static fn (string $file): string => basename($file, '.sql'),
            glob(rtrim((string) $installer->getPath('source'), '/\\') . '/administrator/sql/updates/mysql/*.sql') ?: [],
        );

        if ($versions === []) {
            throw new \RuntimeException('the package holds no update file');
        }

        usort($versions, 'version_compare');
        $newest = (string) end($versions);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $id = $this->componentExtensionId();

        if ($id < 1) {
            throw new \RuntimeException('Joomla has no record of the installed component');
        }

        $db->setQuery(
            $db->createQuery()
                ->delete($db->quoteName('#__schemas'))
                ->where($db->quoteName('extension_id') . ' = ' . $id)
        )->execute();
        $db->setQuery(
            $db->createQuery()
                ->insert($db->quoteName('#__schemas'))
                ->columns($db->quoteName(['extension_id', 'version_id']))
                ->values($id . ', ' . $db->quote($newest))
        )->execute();
    }

    /**
     * Joomla's record of the component: its extension id, or 0 when #__extensions has no row for it.
     *
     * @return  int
     *
     * @since   2.1.4
     */
    protected function componentExtensionId(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return (int) $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'))
        )->loadResult();
    }

    /**
     * Reduce every pair of ids written more than once in a link table without its key to one row.
     *
     * Each table is cleaned in its own transaction: the repeated pairs are deleted and written back
     * once, so a failure leaves that table as it was. A table that already has its key holds no
     * repeats and is skipped, which makes this safe to run on every update.
     *
     * @return  int  The rows removed.
     * @since   2.0.1
     */
    protected function reduceRepeatedLinks(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $existing = array_map('strtolower', $db->getTableList());
        $removed = 0;

        foreach (self::LINK_TABLES as $table => [$owner, $value]) {
            if (!\in_array(strtolower($db->replacePrefix($table)), $existing, true) || $this->hasPrimaryKey(table: $table)) {
                continue;
            }

            $repeats = $db->setQuery(
                $db->createQuery()
                    ->select([$db->quoteName($owner, 'owner_id'), $db->quoteName($value, 'value_id'), 'COUNT(*) AS ' . $db->quoteName('copies')])
                    ->from($db->quoteName($table))
                    ->group([$db->quoteName($owner), $db->quoteName($value)])
                    ->having('COUNT(*) > 1')
            )->loadObjectList();

            if ($repeats === []) {
                continue;
            }

            $db->transactionStart();

            try {
                foreach ($repeats as $pair) {
                    $where = [
                        $db->quoteName($owner) . ' = ' . (int) $pair->owner_id,
                        $db->quoteName($value) . ' = ' . (int) $pair->value_id,
                    ];
                    $db->setQuery($db->createQuery()->delete($db->quoteName($table))->where($where))->execute();
                    $db->setQuery(
                        $db->createQuery()
                            ->insert($db->quoteName($table))
                            ->columns($db->quoteName([$owner, $value]))
                            ->values((int) $pair->owner_id . ', ' . (int) $pair->value_id)
                    )->execute();
                    $removed += (int) $pair->copies - 1;
                }

                $db->transactionCommit();
            } catch (\Throwable $e) {
                $db->transactionRollback();

                throw new \RuntimeException($db->replacePrefix($table) . ': ' . $e->getMessage(), 0, $e);
            }
        }

        if ($removed > 0) {
            Factory::getApplication()->enqueueMessage(
                sprintf('Alfa Commerce: %d repeated rows were removed from the link tables before they were keyed.', $removed)
            );
        }

        return $removed;
    }

    /**
     * Whether a table has its primary key.
     *
     * @param   string  $table  The table, with the #__ prefix.
     *
     * @return  bool
     * @since   2.0.1
     */
    private function hasPrimaryKey(string $table): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return $db->setQuery('SHOW KEYS FROM ' . $db->quoteName($table) . ' WHERE ' . $db->quoteName('Key_name') . ' = ' . $db->quote('PRIMARY'))
            ->loadAssocList() !== [];
    }

    /**
     * After the update files have run: every link table has its primary key and none keeps a plain
     * key the primary key makes redundant.
     *
     * 2.0.1.sql adds the keys with statements allowed to fail, so that the file can run again
     * after an interruption; this check completes whatever it could not, and names what is still
     * missing instead of passing over it. The repeated pairs are reduced once, before the first
     * key, in a step of their own (2.1.0).
     *
     * @return  void
     * @since   2.0.1
     */
    private function completeLinkKeys(): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $existing = array_map('strtolower', $db->getTableList());
        $failed = [];

        // Repeated pairs are reduced once, for every table, before any key is added. A failure
        // there is said on its own, and the keys are still added wherever they can be: a table
        // that still holds a repeated pair refuses its key and is named with the others below.
        try {
            $this->reduceRepeatedLinks();
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                'Alfa Commerce could not reduce the repeated rows of its link tables: ' . $e->getMessage()
                . ' The tables it did not reach keep their repeats and are keyed where they can be.',
                'error'
            );
        }

        foreach (self::LINK_TABLES as $table => [$owner, $value]) {
            if (!\in_array(strtolower($db->replacePrefix($table)), $existing, true)) {
                continue;
            }

            try {
                if (!$this->hasPrimaryKey(table: $table)) {
                    $db->setQuery(
                        'ALTER TABLE ' . $db->quoteName($table) . ' ADD PRIMARY KEY (' . $db->quoteName($owner) . ', ' . $db->quoteName($value) . ')'
                    )->execute();
                }

                $redundant = self::REDUNDANT_LINK_KEYS[$table] ?? null;

                if ($redundant !== null && $db->setQuery(
                    'SHOW KEYS FROM ' . $db->quoteName($table) . ' WHERE ' . $db->quoteName('Key_name') . ' = ' . $db->quote($redundant)
                )->loadAssocList() !== []) {
                    $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' DROP KEY ' . $db->quoteName($redundant))->execute();
                }
            } catch (\Throwable $e) {
                $failed[] = $db->replacePrefix($table) . ' (' . $e->getMessage() . ')';
            }
        }

        if ($failed !== []) {
            Factory::getApplication()->enqueueMessage(
                'Alfa Commerce could not key these link tables on their pair of ids: ' . implode('; ', $failed)
                . '. The shop works; a pair written twice in them stays possible until the key is added.',
                'error'
            );
        }
    }

    /**
     * Run this package's install SQL for an install that never finished, before Joomla copies a
     * file, so that nothing after it (the update files, postflight's language and user sync, the
     * tours, the task) builds on a schema the install SQL has not completed.
     *
     * Joomla's own runner (Installer::parseSQLFiles(), the one the install route uses) reads the
     * package's copy: the extension root is pointed at the package's administrator folder for the
     * call and put back after it. The install SQL is re-runnable: it creates what is missing and
     * changes nothing that exists. Joomla's record of the schema is then set as its install route
     * sets it (Installer::setSchemaVersion(), the package's newest update file), so the update route
     * runs no update file over the completed tables.
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
            if ($installer->parseSQLFiles($manifest->install->sql) === false) {
                return false;
            }

            // The schema the install SQL made is recorded as Joomla's install route records it
            // (InstallerAdapter::parseQueries()), so Joomla runs no update file over it.
            if ($manifest->update) {
                $installer->setSchemaVersion($manifest->update->schemas, $this->componentExtensionId());
            }

            return true;
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
                values:   [$this->packageVersion, $kept['prefix']],
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
                values:   [$this->packageVersion, $kept['schema'], self::SCHEMA_VERSION],
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
     * and the files are what this build is about to overwrite. The one reader of
     * it is Service\Setup\AlfaVersion, taken from this package.
     *
     * @return  string|null
     * @since  2.0.0
     */
    protected function installedVersion(): ?string
    {
        self::loadVersionReader();

        return \Alfa\Component\Alfa\Administrator\Service\Setup\AlfaVersion::installed()?->toString();
    }

    /**
     * The Joomla version this refusal is measured against.
     *
     * @return  string
     * @since  2.0.0
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
     * @since  2.0.0
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
     * The Alfa Commerce language packs go first, through Joomla's installer
     * ({@see self::uninstallLanguagePacks()}): each translates Alfa Commerce and
     * does not outlive it. Then the reverse of install order: remove plugins and
     * modules (they may reference classes from our libraries), then the libraries
     * themselves, then the guided tours, which Joomla does not remove with a
     * component, then the language files earlier versions installed and this one
     * no longer ships ({@see self::removeObsoleteLanguageFilesOnUninstall()}).
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
        $this->uninstallLanguagePacks();
        $this->uninstallPlugins($parent);
        $this->uninstallModules($parent);
        $this->uninstallLibraries($parent);
        $this->removeGuidedTours();
        $this->removeObsoleteLanguageFilesOnUninstall();
    }

    /**
     * Uninstall every Alfa Commerce language pack through Joomla's installer
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\LanguagePacks::uninstallAll()}).
     * Joomla removes each package with its file extensions and every file they list; its own
     * languages and every other extension stay. Each pack is named on the installation page and
     * in the maintenance log, removed or not; a pack Joomla removed but whose files stayed is named
     * as uninstalled with files left, a pack Joomla did not uninstall as not uninstalled, and a pack
     * with no package manifest as not uninstalled for that reason; the log says why, with the files.
     *
     * @return  void
     * @since   2.1.4
     */
    private function uninstallLanguagePacks(): void
    {
        $app = Factory::getApplication();
        $this->packageVersion = $this->ownVersion();

        try {
            $result = \Alfa\Component\Alfa\Administrator\Service\Setup\LanguagePacks::uninstallAll();
        } catch (\Throwable $e) {
            $app->enqueueMessage(
                $this->installerText(
                    key: 'COM_ALFA_UNINSTALL_LANGUAGE_PACKS_ERROR',
                    fallback: 'Alfa Commerce %1$s did not uninstall its language packs, because looking for them failed: %2$s.'
                        . ' Uninstall them in System > Manage > Extensions.',
                    values: [$this->packageVersion, $e->getMessage()],
                ),
                'warning',
            );
            self::maintenanceLog(
                line: sprintf('Uninstall of %s: the language packs were not uninstalled, because looking for them failed: %s', $this->packageVersion, $e->getMessage()),
                priority: \Joomla\CMS\Log\Log::WARNING,
            );

            return;
        }

        $outcomes = [
            'removed' => ['COM_ALFA_UNINSTALL_LANGUAGE_PACKS_REMOVED', 'Alfa Commerce %1$s also uninstalled these language packs,'
                . ' which only work with it: %2$s.', 'message', 'uninstalled the language pack %s', \Joomla\CMS\Log\Log::INFO],
            'files_left' => ['COM_ALFA_UNINSTALL_LANGUAGE_PACKS_FILES_LEFT', 'Alfa Commerce %1$s uninstalled these language packs, but some'
                . ' files could not be removed: %2$s. Remove the remaining files manually. The maintenance log (com_alfa_maintenance.php)'
                . ' lists the files that could not be deleted.', 'warning', 'uninstalled the language pack %s, but files of it stayed',
                \Joomla\CMS\Log\Log::WARNING],
            'failed' => ['COM_ALFA_UNINSTALL_LANGUAGE_PACKS_FAILED', 'Alfa Commerce %1$s could not uninstall these language packs:'
                . ' %2$s. Uninstall them in System > Manage > Extensions.', 'warning', 'could not uninstall the language pack %s',
                \Joomla\CMS\Log\Log::WARNING],
            'no_manifest' => ['COM_ALFA_UNINSTALL_LANGUAGE_PACKS_NO_MANIFEST', 'Alfa Commerce %1$s could not uninstall these language packs,'
                . ' because their package manifest is missing and Joomla cannot uninstall them without it either: %2$s.'
                . ' The maintenance log (com_alfa_maintenance.php) has the details.', 'warning',
                'could not uninstall the language pack %s', \Joomla\CMS\Log\Log::WARNING],
        ];

        foreach ($outcomes as $outcome => [$key, $fallback, $type, $line, $priority]) {
            if (($result[$outcome] ?? []) === []) {
                continue;
            }

            $app->enqueueMessage(
                $this->installerText(key: $key, fallback: $fallback, values: [$this->packageVersion, implode(separator: ', ', array: $result[$outcome])]),
                $type,
            );

            foreach ($result[$outcome] as $element) {
                $why = implode('; ', $result['reasons'][$element] ?? []);
                self::maintenanceLog(line: sprintf('Uninstall of %s: ' . $line . '%s.', $this->packageVersion, $element, $why === '' ? '' : ': ' . $why), priority: $priority);
            }
        }
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
     * Execution order (the work of each step is InstallSteps', shared with
     * Clear everything; this script says what each did):
     *  0. recordSchemaVersion()             - the schema the Alfa tables are at.
     *     moveShippingOptions()             - the former shipping Options, onto each method.
     *     repairTemplateLayouts()           - layout choices saved as "html:<name>", onto their template.
     *  1. applyDefaultParams()              - seeds / merges component config.
     *     seedDeliveryPlaceMapping()        - fresh installs only.
     *     provisionDownloadStore()          - the download store and its Options path.
     *  2. InstallSteps::syncUsers()         - batch-inserts missing users.
     *  3. InstallSteps::syncUsergroups()    - batch-inserts missing groups.
     *  4. InstallSteps::syncLanguageSchema() - the per-language tables.
     *
     * An install that never finished, completed by preflight, runs as an
     * install ({@see self::postflightType()}).
     *
     * Over a Clear everything that did not finish ({@see self::$resetUnfinished})
     * only the shipped records are refreshed (the integrity verdict, the guided
     * tours); every step that writes Alfa data is left to its Run again, which
     * makes the whole schema first.
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

        // Every language file belongs in Joomla's language folders: a language folder inside the
        // component's own folders goes whole, whatever it holds, on install and on update.
        $this->reportLanguageFolders(result: $this->removeComponentLanguageFolders(root: JPATH_ROOT), route: $type);

        // On update, the files earlier versions left in Alfa's own folders and this version no
        // longer ships are removed first, so the integrity check below starts from the result.
        if ($type === 'update')
        {
            $this->removeObsoleteFilesOnUpdate(parent: $parent);
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
        // the lazy scheduler runs it with no cron. The task plugin is installed by now. Joomla's
        // own record, so it is made over an unfinished Clear everything too.
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

        // A Clear everything that has not finished (failed, stopped, or still at work: the installer is
        // never refused) may have left core's schema half made: the new files are in place, and Run again
        // makes the whole schema from this package's install SQL and takes the steps below then.
        if ($this->resetUnfinished)
        {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_RESET_UNFINISHED',
                    fallback: 'Alfa Commerce is in maintenance after an unfinished Clear everything: run it again in Tools > Reset and clear.'
                        . ' The installation steps that write Alfa data were left to it.',
                    values:   [],
                ),
                'warning',
            );

            return true;
        }

        // 0. The link tables have their keys (2.0.1), then the Alfa tables are at this
        //    package's schema: say so among them.
        $this->completeLinkKeys();
        $this->recordSchemaVersion();

        // 0b. Each shipping method owns its shipping taxes and the entry mode of its
        //     prices (2.1.0): the two former Options are moved onto every method once.
        $this->moveShippingOptions();

        // 0c. A layout picked from a template was saved as "html:<name>" up to 2.1.0:
        //     each such choice names its template, where exactly one template has it.
        $this->repairTemplateLayouts();

        // 0d. Notifications keep a language key and its parameters (2.1.4): the rows written
        //     before are given theirs once, their stored text kept as their snapshot.
        $this->mapNotificationKeys();

        // 1. The installed Options (ComponentOptions::DEFAULTS), missing keys only.
        $this->applyDefaultParams(type: $type);

        // 1b. Point the shop's delivery destination at the country field the
        //     install SQL just seeded. Fresh installs only; see the method.
        $this->seedDeliveryPlaceMapping(type: $type);

        // 1c. The download store: its recorded location, the folder, and the
        //     Options path that shows it (a reinstall resets the Options).
        $this->provisionDownloadStore();

        $steps = new \Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps(db: $db);

        // 2. Bulk-sync users
        try
        {
            $count = $steps->syncUsers();
            $app->enqueueMessage(
                sprintf('Users synced successfully (%d new records).', $count)
            );
        }
        catch (\Throwable $e)
        {
            $app->enqueueMessage('Failed to sync users: ' . $e->getMessage(), 'error');
        }

        // 3. Bulk-sync usergroups: each new group's price display is built from the
        //    installed Options (ComponentOptions::DEFAULTS).
        try
        {
            $count = $steps->syncUsergroups();
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
                $result = $steps->syncLanguageSchema();
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

        // 5. One collation for every Alfa table (2.1.1): what the update files could not convert
        //    is converted here, once per table, and what is still left is named.
        if ($type === 'update')
        {
            $this->convergeCollation();
        }

        return true;
    }

    /**
     * Convert every Alfa table still in another collation than utf8mb4_unicode_ci, once, and
     * name each one left with the database's error (CollationCheck). A statement of
     * sql/updates/mysql/2.1.1.sql may fail without stopping the update, and the database view
     * of Joomla cannot see a collation, so this step is what makes the conversion complete:
     * every later installation runs it again. It never fails the installation.
     *
     * @return  void
     * @since   2.1.1
     */
    private function convergeCollation(): void
    {
        try
        {
            $check = \Alfa\Component\Alfa\Administrator\Service\Setup\CollationCheck::class;
            $check::reportOutcome(outcome: $check::converge());
        }
        catch (\Throwable $e)
        {
            Factory::getApplication()->enqueueMessage(
                'The collation of the Alfa tables was not checked: ' . $e->getMessage(),
                'warning'
            );
        }
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
     * Files that earlier versions left inside Alfa Commerce's own folders and that this version
     * does not ship, keyed by the release that first removes them. Joomla copies a release's
     * folders over the old ones but never deletes a file a newer release dropped, so the update
     * removes these ({@see self::removeObsoleteFiles()}). Each path is exact and relative to the
     * site root; each lists the content hashes (PackageHelper::contentHash(), sha256-ws) of the copies
     * a release installed, so a file whose content changed since is kept for the site to check.
     * A release that stops shipping a file adds it under its own version.
     *
     * @var array<string, array<string, string[]>>
     * @since 2.1.1
     */
    public const OBSOLETE_FILES = [
        '2.1.1' => [
            // Moved to Field/Traits/HasFailClosedOptionsTrait.php.
            'administrator/components/com_alfa/src/Field/FailClosedOptionsTrait.php' => ['a8a53876e8486d949288d9390dcc0fca1172f38cdbe3eb82fdcff167281d5f9b'],
            // Copies of the package layout inside module and plugin folders: Joomla installs these files
            // into the language and media folders, never there.
            'modules/mod_alfa_filters/language/en-GB/mod_alfa_filters.ini' => ['f20ff33c28ae0f424555dcdd6afe59bff40901d8c346dc1b23199cb8a7e18a9f'],
            'modules/mod_alfa_filters/language/en-GB/mod_alfa_filters.sys.ini' => ['3e706480213d7a07b2d96fa83fae2baf615f2bb3b0cb06d700f3df297e1b5398'],
            'modules/mod_alfa_items/language/en-GB/mod_alfa_items.ini' => ['22a19b248aac1a047a3e9bd50417684f732fcb3ba4f158719bf0f4ba1d0dd1c5'],
            'modules/mod_alfa_items/language/en-GB/mod_alfa_items.sys.ini' => ['6205bfa314c03dadbf5df2f58d01127306d781b5b2e4c9ceee83eefd90378e17'],
            'modules/mod_easy_articles/language/en-GB/mod_easy_articles.ini' => ['28240eeac276013c992a5098bcab7895e92b0568afd00cca24482f746a15e3d6'],
            'modules/mod_easy_articles/language/en-GB/mod_easy_articles.sys.ini' => ['774c5ea856908b86f68f2943461f57b550e53e59b3aafa01256d99cd834d64a2'],
            'plugins/alfa-payments/standard/media/joomla.asset.json' => ['12378910d1a1bf81fc13b066ea3b5b7af6e7c459466e261f287c2a28cd13a1d7'],
            'plugins/alfa-payments/standard/media/js/refund-partial.js' => [
                '80d5742932fe0952fd19dbf10e155c9e14a1d50c816fd4c587289e7e41d1749b',
                '0eebbb828b0f97ded189b9593d371d0e82ff1cb9ef676062927077f6fbefbca9',
            ],
        ],
    ];

    /**
     * The folders those copies made inside the module and plugin folders, deepest first. One is
     * removed only when this update emptied it ({@see self::removeObsoleteFiles()}).
     *
     * @var array<string, string[]>
     * @since 2.1.1
     */
    public const OBSOLETE_FOLDERS = [
        '2.1.1' => [
            'modules/mod_alfa_filters/language/en-GB',
            'modules/mod_alfa_filters/language',
            'modules/mod_alfa_items/language/en-GB',
            'modules/mod_alfa_items/language',
            'modules/mod_easy_articles/language/en-GB',
            'modules/mod_easy_articles/language',
            'plugins/alfa-payments/standard/media/js',
            'plugins/alfa-payments/standard/media',
        ],
    ];

    /**
     * The element of a language pack's file extension, up to its language tag: each pack installs
     * its files through one, files_alfacommerce_<tag>, inside the package pkg_alfacommerce_<tag>.
     *
     * @var string
     * @since 2.1.1
     */
    private const LANGUAGE_PACK_FILES = 'files_alfacommerce_';

    /**
     * The language folders inside Alfa Commerce's component folders, relative to the site root. Every
     * language file belongs in Joomla's language folders (administrator/language and language), so on
     * install and on update each of these that exists is removed whole, with everything in it
     * ({@see self::removeComponentLanguageFolders()}). That step touches nothing else.
     *
     * @var string[]
     * @since 2.1.2
     */
    public const COMPONENT_LANGUAGE_FOLDERS = [
        'administrator/components/com_alfa/language',
        'components/com_alfa/language',
    ];

    /**
     * Remove each folder of {@see self::COMPONENT_LANGUAGE_FOLDERS} that exists, whole: its files, its
     * folders and the folder itself. A link is removed as a link and never followed, so nothing outside
     * the folder is touched. Each folder is named with the number of files it held.
     *
     * @param string $root The site root, no trailing slash.
     *
     * @return array{removed: string[], failed: string[]} Each entry as "<folder>/ (<n> files)".
     *
     * @since 2.1.2
     */
    protected function removeComponentLanguageFolders(string $root): array
    {
        $result = ['removed' => [], 'failed' => []];

        foreach (self::COMPONENT_LANGUAGE_FOLDERS as $rel) {
            $abs = $root . '/' . $rel;

            if (!is_link($abs) && !is_dir($abs)) {
                continue;
            }

            $files = 0;
            $removed = self::removeTree(path: $abs, files: $files);
            $result[$removed ? 'removed' : 'failed'][] = sprintf('%s/ (%d %s)', $rel, $files, $files === 1 ? 'file' : 'files');
        }

        return $result;
    }

    /**
     * Remove a file, a link or a folder with everything in it, never following a link.
     *
     * @param string $path  The absolute path.
     * @param int    $files Counts every file and link removed (by reference).
     *
     * @return bool True when the path is gone.
     *
     * @since 2.1.2
     */
    private static function removeTree(string $path, int &$files): bool
    {
        if (is_link($path) || !is_dir($path)) {
            $gone = @unlink($path);
            $files += $gone ? 1 : 0;

            return $gone;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return false;
        }

        $all = true;

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $all = self::removeTree(path: $path . '/' . $entry, files: $files) && $all;
        }

        return $all && @rmdir($path);
    }

    /**
     * Language files a release stopped shipping, keyed by that release. From 2.1.1 on Alfa Commerce
     * ships English only, and every other language is a separate language pack. Each exact path,
     * relative to the site root, maps to the sha256 of the file's exact bytes as the last release
     * that shipped it installed it. They are removed on the update from a version below that release,
     * where no language pack can be installed yet, and on uninstall; a path an installed language
     * pack lists and a file whose bytes differ are never removed, and no language folder is
     * ({@see self::removeObsoleteFiles()}).
     *
     * @var array<string, array<string, string>>
     * @since 2.1.1
     */
    public const OBSOLETE_LANGUAGE_FILES = [
        '2.1.1' => [
            // The Greek texts of 2.1.0 in Joomla's language folders. Its closing tour steps were in the
            // component's own language folder, which goes whole (COMPONENT_LANGUAGE_FOLDERS).
            'administrator/language/el-GR/com_alfa.ini' => '138643c82e70d430ba3c587a11e563ec6d55122f921335ecef6be6521aca8740',
            'administrator/language/el-GR/com_alfa.sys.ini' => '3e028f3e0d21edd6aa7a941604f69d7f51527041877b2619b599a95274e66628',
            'language/el-GR/com_alfa.ini' => '1535bd9a3155d63fa6a8cb198b09207c5c79802105a2fc1cc8347ce26f98f942',
        ],
    ];

    /**
     * Remove the files of {@see self::OBSOLETE_FILES} that are on this site, then the folders of
     * {@see self::OBSOLETE_FOLDERS} that this leaves empty.
     *
     * A path is used only when it is exact ({@see self::obsoletePathAllowed()}) and this package
     * does not install it. A file is removed only when it is a regular file, not a link, and its
     * content is one a release installed; a file with other content is kept and named. A folder is
     * removed only when this run removed something inside it and it is now empty, and never when
     * it is a link. Nothing else is touched.
     *
     * Language files ({@see self::OBSOLETE_LANGUAGE_FILES}) follow the same steps with their own
     * guard ({@see self::obsoleteLanguagePathAllowed()}): a path an installed language pack lists is
     * never touched, and a file is removed only when its exact bytes are the ones listed. Their
     * folders are Joomla's and stay.
     *
     * @param string $root The site root, no trailing slash.
     * @param string[] $shipped The site-relative paths this package installs.
     * @param array<string, array<string, string[]>> $files The obsolete files, as {@see self::OBSOLETE_FILES}.
     * @param array<string, string[]> $folders The obsolete folders, as {@see self::OBSOLETE_FOLDERS}.
     * @param array<string, string> $languageFiles The obsolete language files of this run: path => sha256 of the exact bytes.
     * @param string[] $owned The paths installed language packs list (a folder with a trailing slash), never touched.
     *
     * @return array{removed: string[], folders: string[], kept: string[], failed: string[], shipped: string[], languageKept: string[], languageFailed: string[], owned: string[]}
     *
     * @since 2.1.1
     */
    protected function removeObsoleteFiles(
        string $root,
        array $shipped,
        array $files = self::OBSOLETE_FILES,
        array $folders = self::OBSOLETE_FOLDERS,
        array $languageFiles = [],
        array $owned = [],
    ): array {
        $shipped = array_flip($shipped);
        $result = ['removed' => [], 'folders' => [], 'kept' => [], 'failed' => [], 'shipped' => [], 'languageKept' => [], 'languageFailed' => [], 'owned' => []];
        $emptied = [];

        foreach ($files as $release) {
            foreach ($release as $rel => $hashes) {
                $rel = (string) $rel;
                $abs = $root . '/' . $rel;

                if (!self::obsoletePathAllowed(rel: $rel) || is_link($abs) || !is_file($abs)) {
                    continue;
                }

                if (isset($shipped[$rel])) {
                    $result['shipped'][] = $rel;

                    continue;
                }

                $hash = \Alfa\Component\Alfa\Administrator\Helper\PackageHelper::contentHash(path: $abs);

                if ($hash === false || !\in_array($hash, (array) $hashes, true)) {
                    $result['kept'][] = $rel;

                    continue;
                }

                if (@unlink($abs)) {
                    $result['removed'][] = $rel;
                    $emptied[\dirname($rel)] = true;
                } else {
                    $result['failed'][] = $rel;
                }
            }
        }

        foreach ($languageFiles as $rel => $sha256) {
            $rel = (string) $rel;
            $abs = $root . '/' . $rel;

            if (!self::obsoleteLanguagePathAllowed(rel: $rel) || is_link($abs) || !is_file($abs)) {
                continue;
            }

            if (self::listedByLanguagePack(rel: $rel, owned: $owned)) {
                $result['owned'][] = $rel;

                continue;
            }

            $hash = @hash_file('sha256', $abs);

            if ($hash === false || !hash_equals((string) $sha256, $hash)) {
                $result['languageKept'][] = $rel;

                continue;
            }

            if (@unlink($abs)) {
                $result['removed'][] = $rel;
            } else {
                $result['languageFailed'][] = $rel;
            }
        }

        foreach ($folders as $release) {
            foreach ($release as $rel) {
                $rel = (string) $rel;
                $abs = $root . '/' . $rel;

                if (
                    !isset($emptied[$rel])
                    || !self::obsoletePathAllowed(rel: $rel)
                    || is_link($abs)
                    || !is_dir($abs)
                    || array_diff((array) scandir($abs), ['.', '..']) !== []
                ) {
                    continue;
                }

                if (@rmdir($abs)) {
                    $result['folders'][] = $rel;
                    $emptied[\dirname($rel)] = true;
                } else {
                    $result['failed'][] = $rel . '/';
                }
            }
        }

        return $result;
    }

    /**
     * Whether an obsolete-file entry may be used: an exact path relative to the site root (no
     * wildcard, no "." or ".." segment), inside an extension folder, and not a language pack's
     * file or folder (language/<tag>/ for a tag other than en-GB), which belongs to the site.
     *
     * @param string $rel The entry.
     *
     * @since 2.1.1
     */
    private static function obsoletePathAllowed(string $rel): bool
    {
        return strpbrk($rel, '*?[]{}\\') === false
            && preg_match('#(^|/)\.{1,2}(/|$)#', $rel) !== 1
            && preg_match('#(^|/)language/(?!en-GB(/|$))[^/]+(/|$)#', $rel) !== 1
            && preg_match('#^(?:(?:administrator/|api/)?components/[^/]+|media/[^/]+|modules/[^/]+|plugins/[^/]+/[^/]+)/.+#', $rel) === 1;
    }

    /**
     * Whether a language entry may be used: it is one of {@see self::OBSOLETE_LANGUAGE_FILES}, the
     * only language paths the cleanup may ever touch.
     *
     * @param string $rel The entry.
     *
     * @since 2.1.1
     */
    private static function obsoleteLanguagePathAllowed(string $rel): bool
    {
        foreach (self::OBSOLETE_LANGUAGE_FILES as $entries) {
            if (\array_key_exists($rel, $entries)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entries of the releases newer than a version, merged: on an update, what the releases
     * after the version it starts from dropped; on uninstall, every release's.
     *
     * @param array<string, array> $byRelease Entries keyed by release, as {@see self::OBSOLETE_LANGUAGE_FILES}.
     * @param string|null $from The version installed before, or null for every release.
     *
     * @since 2.1.1
     */
    private static function obsoleteSince(array $byRelease, ?string $from): array
    {
        $entries = [];

        foreach ($byRelease as $release => $listed) {
            if ($from === null || \Alfa\Component\Alfa\Administrator\Service\Setup\AlfaVersion::of(version: (string) $release)->isNewerThan(other: $from)) {
                $entries = [...$entries, ...$listed];
            }
        }

        return $entries;
    }

    /**
     * Whether an installed language pack lists a path: the path itself, or a folder holding it.
     *
     * @param string $rel A site-relative path.
     * @param string[] $owned The paths installed language packs list, as {@see self::languagePackFiles()}.
     *
     * @since 2.1.1
     */
    private static function listedByLanguagePack(string $rel, array $owned): bool
    {
        foreach ($owned as $listed) {
            if ($listed === $rel || (str_ends_with($listed, '/') && str_starts_with($rel, $listed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site-relative paths the installed language packs list ({@see self::LANGUAGE_PACK_FILES}):
     * each file as it is, each folder with a trailing slash. Null when one of their manifests
     * cannot be read: the cleanup then removes no language file.
     *
     * @return string[]|null
     *
     * @since 2.1.1
     */
    private function languagePackFiles(): ?array
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $elements = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('element'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('file'))
                    ->where($db->quoteName('element') . ' LIKE ' . $db->quote($db->escape(self::LANGUAGE_PACK_FILES, true) . '%')),
            )->loadColumn();
        } catch (\Throwable) {
            return null;
        }

        $owned = [];

        foreach ($elements as $element) {
            $listed = self::manifestFiles(path: JPATH_MANIFESTS . '/files/' . $element . '.xml');

            if ($listed === null) {
                return null;
            }

            $owned = [...$owned, ...$listed];
        }

        return $owned;
    }

    /**
     * The site-relative paths a file extension's manifest lists, as Joomla installs them: each
     * filename and each folder of its fileset under the target of its files element.
     *
     * @param string $path The manifest.
     *
     * @return string[]|null Null when the manifest cannot be read.
     *
     * @since 2.1.1
     */
    private static function manifestFiles(string $path): ?array
    {
        $xml = is_file($path) ? @simplexml_load_file($path) : false;

        if ($xml === false) {
            return null;
        }

        $listed = [];

        foreach ($xml->fileset->files ?? [] as $files) {
            $target = trim((string) $files['target'], '/');
            $prefix = $target === '' ? '' : $target . '/';

            foreach ($files->filename ?? [] as $name) {
                $listed[] = $prefix . trim((string) $name, '/');
            }

            foreach ($files->folder ?? [] as $name) {
                $listed[] = $prefix . trim((string) $name, '/') . '/';
            }
        }

        return $listed;
    }

    /**
     * On update: remove what earlier versions left in Alfa Commerce's folders
     * ({@see self::removeObsoleteFiles()}) and name every removal on the installation page and
     * in the maintenance log (com_alfa_maintenance.php). When the package's own file list cannot
     * be read, nothing is removed. The language files of {@see self::OBSOLETE_LANGUAGE_FILES} go
     * only on the update from below the release that dropped them, where no language pack can be
     * installed yet, and only when the files installed language packs list could be read.
     *
     * @param object $parent The installer adapter.
     *
     * @since 2.1.1
     */
    private function removeObsoleteFilesOnUpdate($parent): void
    {
        try {
            $shipped = array_keys(\Alfa\Component\Alfa\Administrator\Helper\PackageHelper::enumerateFromPackage(
                packageRoot: $parent->getParent()->getPath('source'),
            ));
        } catch (\Throwable $e) {
            $this->reportObsoleteFiles(result: null, problem: $e->getMessage());

            return;
        }

        $owned = $this->versionBefore === null ? [] : $this->languagePackFiles();
        $language = $this->versionBefore !== null && $owned !== null;

        $this->reportObsoleteFiles(
            result: $this->removeObsoleteFiles(
                root:            JPATH_ROOT,
                shipped:         $shipped,
                languageFiles:   $language ? self::obsoleteSince(byRelease: self::OBSOLETE_LANGUAGE_FILES, from: $this->versionBefore) : [],
                owned:           $owned ?? [],
            ),
            languageProblem: $owned === null ? 'the files installed language packs list could not be read' : '',
        );
    }

    /**
     * On uninstall: remove the language files earlier versions installed and later ones stopped
     * shipping ({@see self::OBSOLETE_LANGUAGE_FILES}), so none stays behind as an orphan of Alfa
     * Commerce. A path an installed language pack lists and a file whose bytes changed are never
     * removed; every outcome is named, as on update.
     *
     * @since 2.1.1
     */
    private function removeObsoleteLanguageFilesOnUninstall(): void
    {
        $this->packageVersion = $this->ownVersion();
        $owned = $this->languagePackFiles();

        $this->reportObsoleteFiles(
            result: $this->removeObsoleteFiles(
                root:            JPATH_ROOT,
                shipped:         [],
                files:           [],
                folders:         [],
                languageFiles:   $owned === null ? [] : self::obsoleteSince(byRelease: self::OBSOLETE_LANGUAGE_FILES, from: null),
                owned:           $owned ?? [],
            ),
            route: 'uninstall',
            languageProblem: $owned === null ? 'the files installed language packs list could not be read' : '',
        );
    }

    /**
     * Say what {@see self::removeComponentLanguageFolders()} did: one message for the folders removed
     * and one for those left, each folder named with its number of files, and one maintenance log
     * line per folder.
     *
     * @param array{removed: string[], failed: string[]} $result Its result.
     * @param string $route The installer route it ran on: install or update.
     *
     * @since 2.1.4
     */
    protected function reportLanguageFolders(array $result, string $route): void
    {
        $app = Factory::getApplication();
        $prefix = sprintf($route === 'install' ? 'Install of %s' : 'Update to %s', $this->packageVersion);
        $outcomes = [
            'removed' => ['COM_ALFA_INSTALL_LANGUAGE_FOLDERS_REMOVED', 'Alfa Commerce %1$s removed these language folders from'
                . ' inside its own folders, with every file in them, because language files belong in Joomla\'s language folders:'
                . ' %2$s.', 'message', 'removed the language folder %s', \Joomla\CMS\Log\Log::INFO],
            'failed' => ['COM_ALFA_INSTALL_LANGUAGE_FOLDERS_FAILED', 'Alfa Commerce %1$s could not remove these language folders'
                . ' from inside its own folders: %2$s. Remove them by hand, because language files belong in Joomla\'s language'
                . ' folders.', 'warning', 'could not remove the language folder %s', \Joomla\CMS\Log\Log::WARNING],
        ];

        foreach ($outcomes as $outcome => [$key, $fallback, $type, $line, $priority]) {
            $folders = $result[$outcome] ?? [];

            if ($folders === []) {
                continue;
            }

            $app->enqueueMessage(
                $this->installerText(key: $key, fallback: $fallback, values: [$this->packageVersion, implode(separator: ', ', array: $folders)]),
                $type,
            );

            foreach ($folders as $folder) {
                self::maintenanceLog(line: $prefix . ': ' . sprintf($line, $folder) . '.', priority: $priority);
            }
        }
    }

    /**
     * Write one line to the maintenance log (com_alfa_maintenance.php). The installation page says the
     * same; the log is the second copy, so a failure to write it is not said.
     *
     * @param string $line The line.
     * @param int $priority Its Log priority.
     *
     * @since 2.1.4
     */
    private static function maintenanceLog(string $line, int $priority): void
    {
        try {
            \Joomla\CMS\Log\Log::addLogger(['text_file' => 'com_alfa_maintenance.php'], \Joomla\CMS\Log\Log::ALL, ['com_alfa.maintenance']);
            \Joomla\CMS\Log\Log::add($line, $priority, 'com_alfa.maintenance');
        } catch (\Throwable) {
            // The installation page says it; the log is the second copy.
        }
    }

    /**
     * Say what {@see self::removeObsoleteFiles()} did: one message per outcome, naming every path,
     * and one maintenance log line per path.
     *
     * @param array|null $result Its result, or null when nothing was attempted.
     * @param string $problem Why nothing was attempted.
     * @param string $route The installer route the removal ran on: install, update or uninstall.
     * @param string $languageProblem Why no language file was attempted, or ''.
     *
     * @since 2.1.1
     */
    protected function reportObsoleteFiles(?array $result, string $problem = '', string $route = 'update', string $languageProblem = ''): void
    {
        $app = Factory::getApplication();
        $log = static fn (string $line, int $priority) => self::maintenanceLog(line: $line, priority: $priority);
        $version = $this->packageVersion;
        $prefix = sprintf(['uninstall' => 'Uninstall of %s', 'install' => 'Install of %s'][$route] ?? 'Update to %s', $version);

        if ($result === null) {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_OBSOLETE_UNREAD',
                    fallback: 'Alfa Commerce %1$s removed none of the files earlier versions left in its folders: it could not read'
                        . ' which files this package installs (%2$s). Tools > Security lists any that remain.',
                    values:   [$version, $problem],
                ),
                'warning',
            );
            $log(sprintf('Update to %s: no obsolete file was removed: %s', $version, $problem), \Joomla\CMS\Log\Log::WARNING);

            return;
        }

        $outcomes = [
            'removed' => ['COM_ALFA_INSTALL_OBSOLETE_REMOVED', 'Alfa Commerce %1$s removed these files, which an earlier version'
                . ' left in its folders and this version no longer ships: %2$s.', 'message', 'removed %s: left by an earlier version,'
                . ' not shipped by this one', \Joomla\CMS\Log\Log::INFO],
            'folders' => ['COM_ALFA_INSTALL_OBSOLETE_FOLDERS', 'Alfa Commerce %1$s also removed the folders those files leave'
                . ' empty: %2$s.', 'message', 'removed the empty folder %s', \Joomla\CMS\Log\Log::INFO],
            'kept' => ['COM_ALFA_INSTALL_OBSOLETE_KEPT', 'Alfa Commerce %1$s kept these files, which an earlier version left in'
                . ' its folders and this version no longer ships: their content is not what a release installed, so they stay'
                . ' for you to check in Tools > Security: %2$s.', 'warning', 'kept %s: its content is not one a release installed',
                \Joomla\CMS\Log\Log::WARNING],
            'failed' => ['COM_ALFA_INSTALL_OBSOLETE_FAILED', 'Alfa Commerce %1$s could not remove these files, which an earlier'
                . ' version left in its folders and this version no longer ships: %2$s. Remove them by hand; Tools > Security'
                . ' lists them until they are gone.', 'warning', 'could not remove %s', \Joomla\CMS\Log\Log::WARNING],
            // Language files: Tools > Security never lists them (a language pack's files are allowed there).
            'languageKept' => ['COM_ALFA_INSTALL_OBSOLETE_LANGUAGE_KEPT', 'Alfa Commerce %1$s kept these language files, which an'
                . ' earlier version installed and this version no longer ships: their content is not what that version installed,'
                . ' so they may be yours. Remove them by hand if you do not need them: %2$s.', 'warning',
                'kept %s: its bytes are not the ones the release that shipped it installed', \Joomla\CMS\Log\Log::WARNING],
            'languageFailed' => ['COM_ALFA_INSTALL_OBSOLETE_LANGUAGE_FAILED', 'Alfa Commerce %1$s could not remove these language'
                . ' files, which an earlier version installed and this version no longer ships: %2$s. Remove them by hand.',
                'warning', 'could not remove %s', \Joomla\CMS\Log\Log::WARNING],
        ];

        foreach ($outcomes as $outcome => [$key, $fallback, $type, $line, $priority]) {
            $paths = $result[$outcome] ?? [];

            if ($paths === []) {
                continue;
            }

            $app->enqueueMessage(
                $this->installerText(key: $key, fallback: $fallback, values: [$version, implode(separator: ', ', array: $paths)]),
                $type,
            );

            foreach ($paths as $rel) {
                $log($prefix . ': ' . sprintf($line, $rel) . '.', $priority);
            }
        }

        foreach ($result['shipped'] ?? [] as $rel) {
            $log(sprintf('%s: %s is listed as obsolete, but this package installs it: left in place.', $prefix, $rel), \Joomla\CMS\Log\Log::WARNING);
        }

        foreach ($result['owned'] ?? [] as $rel) {
            $log(sprintf('%s: %s is a file of an installed language pack: left in place.', $prefix, $rel), \Joomla\CMS\Log\Log::INFO);
        }

        if ($languageProblem !== '') {
            $log(sprintf('%s: no obsolete language file was removed: %s.', $prefix, $languageProblem), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * Record {@see self::SCHEMA_VERSION} as the schema the Alfa tables are at,
     * once an install or update has brought them there (InstallSteps, which
     * never lowers a recorded version).
     *
     * A failure is said, never a reason to fail the install: the shop works,
     * and only a later reinstall over the kept data would be refused.
     *
     * @return  void
     * @since   2.0.0
     */
    private function recordSchemaVersion(): void
    {
        try {
            (new \Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps(db: Factory::getContainer()->get('DatabaseDriver')))
                ->recordSchemaVersion(version: self::SCHEMA_VERSION);
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                'The database schema version of Alfa Commerce was not recorded: ' . $e->getMessage()
                . ' A reinstall over the kept data will be refused until it is.',
                'error'
            );
        }
    }

    /**
     * Move the two former shop-level shipping Options, Default shipping taxes and Shipping prices
     * entered, onto every shipping method
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\ShippingOptionsMigration}): once, on
     * the update that finds them stored, and never again. The method column of the entry mode is
     * added first when the update files did not run (a site already at this schema).
     *
     * A failure is said, never a reason to fail the install: both Options stay stored, and the move
     * runs again when this package is installed again.
     *
     * @return  void
     * @since   2.1.0
     */
    private function moveShippingOptions(): void
    {
        $migration = new \Alfa\Component\Alfa\Administrator\Service\Setup\ShippingOptionsMigration(
            db: Factory::getContainer()->get('DatabaseDriver'),
        );

        try {
            $migration->completeColumn();
            $moved = $migration->run();
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_SHIPPING_OPTIONS',
                    fallback: 'Alfa Commerce could not move Default shipping taxes and Shipping prices entered onto the'
                        . ' shipping methods, and changed none of them: %1$s. Both Options were kept, and the move runs'
                        . ' again when Alfa Commerce is installed again. Until then, check the Shipping taxes and'
                        . ' Shipping prices entered of every shipping method before taking orders.',
                    values:   [$e->getMessage()],
                ),
                'error',
            );
            $this->noticeShippingMove(failed: true);

            return;
        }

        $this->noticeShippingMove(failed: false);

        if ($moved === null) {
            return;
        }

        // The rules given and the entry mode are texts of the language files too, so the whole sentence is said in
        // the installing user's language.
        $taxes = $moved['rules'] === []
            ? $this->installerText(
                key:      'COM_ALFA_INSTALL_SHIPPING_TAXES_NONE',
                fallback: 'none, so they charge no shipping tax',
                values:   [],
            )
            : $this->installerText(
                key:      'COM_ALFA_INSTALL_SHIPPING_TAXES_RULES',
                fallback: 'tax rules %s',
                values:   ['#' . implode(separator: ', #', array: $moved['rules'])],
            );
        $entered = $moved['mode'] === 'tax_excluded'
            ? $this->installerText(key: 'COM_ALFA_INSTALL_SHIPPING_PRICES_TAX_EXCLUDED', fallback: 'tax excluded', values: [])
            : $this->installerText(key: 'COM_ALFA_INSTALL_SHIPPING_PRICES_TAX_INCLUDED', fallback: 'tax included', values: []);

        Factory::getApplication()->enqueueMessage(
            $this->installerText(
                key:      'COM_ALFA_INSTALL_SHIPPING_OPTIONS_MOVED',
                fallback: 'Alfa Commerce: each shipping method now holds its own Shipping taxes and Shipping prices'
                    . ' entered. %1$d of the %2$d shipping methods named no tax rules of their own and now hold the'
                    . ' former Default shipping taxes: %3$s. Every method enters its prices %4$s, as the former'
                    . ' Options did. Both Options were removed.',
                values:   [
                    \count($moved['given']),
                    $moved['methods'],
                    $taxes,
                    $entered,
                ],
            ),
        );
    }

    /**
     * A failed move of the shipping Options is a notification for staff until an install completes it.
     *
     * While the move has not completed, the Options still hold the shop's former shipping settings, but checkout reads
     * only each shipping method's own: shipping tax is not applied as the shop configured it. The notification says so
     * where staff look (the bell and the Notifications list), cannot be dismissed, and goes when an install completes
     * the move or finds nothing left to move (the Options' two keys gone are the move's done-marker). It keeps its keys
     * (2.1.4), so each administrator reads it in their language. Writing or clearing it never stops the install.
     *
     * @param   bool  $failed  True when the move failed now; false when it completed or had nothing to move.
     *
     * @return  void
     * @since  2.1.0
     */
    private function noticeShippingMove(bool $failed): void
    {
        $key = 'setup.shipping_options_move';
        $notifications = \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::class;

        try
        {
            if (!$failed)
            {
                if ($notifications::exists(dedupKey: $key))
                {
                    $notifications::clear(dedupKey: $key);
                }

                return;
            }

            // The notice keeps its keys (com_alfa.sys): each administrator reads it in their language, and its snapshot is
            // written in the shop's default administrator language from this version's language files.
            $text = \Alfa\Component\Alfa\Administrator\Helper\NotificationText::class;

            $notifications::push(
                dedupKey: $key,
                title:    $text::from('com_alfa.sys', 'COM_ALFA_NOTIFY_SHIPPING_MOVE_FAILED_TITLE'),
                options:  [
                    'severity'    => 'danger',
                    'group'       => $notifications::GROUP_SHIPPING,
                    'message'     => $text::from('com_alfa.sys', 'COM_ALFA_NOTIFY_SHIPPING_MOVE_FAILED_MSG'),
                    'url'         => 'index.php?option=com_alfa&view=shipments',
                    'dismissible' => false,
                    'constant'    => true,
                    'view'        => ['action' => 'alfa.shipments.view', 'asset' => 'com_alfa'],
                    'link'        => ['action' => 'alfa.shipments.view', 'asset' => 'com_alfa'],
                ],
            );
        }
        catch (\Throwable)
        {
            // The installer's own message (above) already says what failed and what to check.
        }
    }

    /**
     * Give the notifications written before 2.1.4 their language keys and parameters, once
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\NotificationKeys}): their stored text stays as it
     * is, as their snapshot. The counts are said here and in the setup log. A failure never stops the install: a row
     * left without a key is shown as written, and the next install maps it.
     *
     * @return  void
     * @since  2.1.4
     */
    private function mapNotificationKeys(): void
    {
        try
        {
            $counts = \Alfa\Component\Alfa\Administrator\Service\Setup\NotificationKeys::map();

            if ($counts['keyed'] + $counts['grouped'] > 0)
            {
                Factory::getApplication()->enqueueMessage($this->installerText(
                    key:      'COM_ALFA_INSTALL_NOTIFICATIONS_KEYED',
                    fallback: 'Notifications written before this version: %1$d now show in each administrator\'s language; %2$d keep their text as written.',
                    values:   [$counts['keyed'], $counts['rows'] - $counts['keyed']],
                ));
            }
        }
        catch (\Throwable $e)
        {
            Factory::getApplication()->enqueueMessage('The notifications written before this version could not be given their language keys: '
                . $e->getMessage() . ' They are shown as written; the next install tries again.', 'warning');
        }
    }

    /**
     * Give every layout choice that the layout picker of 2.0 saved as "html:<name>" its template
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\TemplateLayoutMigration}), and say
     * which choices changed and which were left, so the shop can pick those again.
     *
     * A failure is said, never a reason to fail the install: nothing was changed, and the repair
     * runs again when this package is installed again.
     *
     * @return  void
     * @since   2.1.0
     */
    private function repairTemplateLayouts(): void
    {
        $app = Factory::getApplication();

        try
        {
            $result = (new \Alfa\Component\Alfa\Administrator\Service\Setup\TemplateLayoutMigration(
                db: Factory::getContainer()->get('DatabaseDriver'),
            ))->run();
        }
        catch (\Throwable $e)
        {
            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_ERROR_LAYOUTS',
                    fallback: 'Alfa Commerce could not repair the layout choices saved with the template name html, and'
                        . ' changed none of them: %1$s. They are repaired when Alfa Commerce is installed again; until'
                        . ' then their pages show the component\'s own layout.',
                    values:   [$e->getMessage()],
                ),
                'error',
            );

            return;
        }

        if ($result['mapped'] === [] && $result['left'] === [])
        {
            return;
        }

        // The installer read this extension's texts before it copied this version's language files: read them
        // again, so the messages and the layouts' labels have their own texts in the installing user's language.
        $language = Factory::getLanguage();

        foreach (['com_alfa.sys', 'com_alfa'] as $extension)
        {
            $language->load(extension: $extension, basePath: JPATH_ADMINISTRATOR, lang: $language->getTag(), reload: true);
        }

        if ($result['mapped'] !== [])
        {
            $groups = [];

            foreach ($result['mapped'] as $entry)
            {
                $groups[$entry['from'] . ' -> ' . $entry['to']][] = $this->layoutPlace(entry: $entry);
            }

            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_LAYOUTS_MAPPED',
                    fallback: 'Alfa Commerce: %1$d layout choice(s) saved with the template name html now name their'
                        . ' template, so their pages show that template\'s layout: %2$s.',
                    values:   [\count($result['mapped']), $this->layoutGroups(groups: $groups)],
                ),
            );
        }

        if ($result['left'] !== [])
        {
            $groups = [];

            foreach ($result['left'] as $entry)
            {
                $file = 'html/com_alfa/' . $entry['view'] . '/' . substr($entry['value'], \strlen('html:')) . '.php';
                $reason = match ($entry['reason'])
                {
                    'several' => $this->installerText(
                        key:      'COM_ALFA_INSTALL_LAYOUT_LEFT_SEVERAL',
                        fallback: 'the templates %1$s all have %2$s',
                        values:   [implode(', ', $entry['templates']), $file],
                    ),
                    'html-folder' => $this->installerText(
                        key:      'COM_ALFA_INSTALL_LAYOUT_LEFT_HTML_FOLDER',
                        fallback: 'this site has a template folder named html',
                        values:   [],
                    ),
                    default => $this->installerText(
                        key:      'COM_ALFA_INSTALL_LAYOUT_LEFT_NONE',
                        fallback: 'no template has %1$s',
                        values:   [$file],
                    ),
                };
                $groups[$entry['value'] . ' (' . $reason . ')'][] = $this->layoutPlace(entry: $entry);
            }

            $app->enqueueMessage(
                $this->installerText(
                    key:      'COM_ALFA_INSTALL_LAYOUTS_LEFT',
                    fallback: 'Alfa Commerce left %1$d layout choice(s) saved with the template name html as they are,'
                        . ' because it could not tell which template holds the layout: %2$s. Pick each again in its'
                        . ' form; until then its page shows the component\'s own layout.',
                    values:   [\count($result['left']), $this->layoutGroups(groups: $groups)],
                ),
                'warning',
            );
        }
    }

    /**
     * Where a repaired or left layout choice is, in the words of its form: "Item Main of item #2531".
     *
     * @param   array  $entry  An entry of TemplateLayoutMigration::run() (kind, id, view).
     *
     * @return  string
     * @since   2.1.0
     */
    private function layoutPlace(array $entry): string
    {
        $labels = [
            'item'          => ['COM_ALFA_LAYOUT_ITEM_LABEL', 'Item Main'],
            'items'         => ['COM_ALFA_LAYOUT_ITEMS_LABEL', 'Items List Main'],
            'manufacturer'  => ['COM_ALFA_LAYOUT_MANUFACTURER_LABEL', 'Manufacturer Main'],
            'manufacturers' => ['COM_ALFA_LAYOUT_MANUFACTURERS_LABEL', 'Manufacturers List Main'],
            'registration'  => ['COM_ALFA_LAYOUT_REGISTRATION_LABEL', 'Registration Main'],
            'userinfo'      => ['COM_ALFA_LAYOUT_USERINFO_LABEL', 'User Info Main'],
        ];
        [$labelKey, $labelFallback] = $labels[$entry['view']] ?? ['', $entry['key']];
        $label = $labelKey === '' ? $labelFallback : $this->installerText(key: $labelKey, fallback: $labelFallback, values: []);

        return match ($entry['kind'])
        {
            'item'     => $this->installerText(key: 'COM_ALFA_INSTALL_LAYOUT_AT_ITEM', fallback: '%1$s of item #%2$d', values: [$label, $entry['id']]),
            'category' => $this->installerText(key: 'COM_ALFA_INSTALL_LAYOUT_AT_CATEGORY', fallback: '%1$s of category #%2$d', values: [$label, $entry['id']]),
            'menu'     => $this->installerText(key: 'COM_ALFA_INSTALL_LAYOUT_AT_MENU', fallback: '%1$s of menu item #%2$d', values: [$label, $entry['id']]),
            default    => $this->installerText(key: 'COM_ALFA_INSTALL_LAYOUT_AT_OPTIONS', fallback: '%1$s in the Options', values: [$label]),
        };
    }

    /**
     * The layout choices of one message, grouped by value: "html:blabla -> chameleon:blabla: <place>, <place>; ...".
     *
     * @param   array<string, string[]>  $groups  The places, by value.
     *
     * @return  string
     * @since   2.1.0
     */
    private function layoutGroups(array $groups): string
    {
        $parts = [];

        foreach ($groups as $value => $places)
        {
            $parts[] = $value . ': ' . implode(', ', $places);
        }

        return implode('; ', $parts);
    }

    // Default-params helper

    /**
     * Add every installed Option the stored Options lack
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\ComponentOptions::DEFAULTS}):
     * existing values win and nothing is ever overwritten, on an install and on an update alike.
     *
     * @param   string  $type  The installer's operation.
     *
     * @return  void
     * @since  1.0.0
     */
    private function applyDefaultParams(string $type): void
    {
        (new \Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps(db: Factory::getContainer()->get('DatabaseDriver')))
            ->applyDefaultOptions(replace: false);
    }

    /**
     * Map the shop's delivery destination to the country field the install
     * SQL seeded, on a fresh install only: on an update the mapping is
     * whatever the merchant has
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps::seedDeliveryPlaceMapping()}
     * says why, and why never by a hardcoded id).
     *
     * @param   string  $type  The installer's operation: only 'install' seeds.
     *
     * @return  void
     * @since  2.0.0
     */
    private function seedDeliveryPlaceMapping(string $type): void
    {
        if ($type !== 'install')
        {
            return;
        }

        (new \Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps(db: Factory::getContainer()->get('DatabaseDriver')))
            ->seedDeliveryPlaceMapping();
    }

    /**
     * Settle the download store and show its path in the Options
     * ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps::provisionDownloadStore()}),
     * then probe its direct-download protection.
     *
     * A failure is said, never a reason to fail the install: the shop works,
     * and only its downloadable files wait for the store.
     *
     * @return  void
     * @since   2.0.0
     */
    private function provisionDownloadStore(): void
    {
        $steps = new \Alfa\Component\Alfa\Administrator\Service\Setup\InstallSteps(db: Factory::getContainer()->get('DatabaseDriver'));

        try
        {
            $root = $steps->provisionDownloadStore();
        }
        catch (\Throwable $e)
        {
            // The reason's own words go to the log; the page says which step failed.
            \Joomla\CMS\Log\Log::add('Download store setup failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'com_alfa.downloads');
            Factory::getApplication()->enqueueMessage(
                'The download store of Alfa Commerce was not set up: its folder or its recorded path could not be made'
                . ' (the reason is in the downloads log, com_alfa_downloads.php in the Joomla log folder). Downloadable items cannot be uploaded or downloaded until it'
                . ' is: set the path under Options > Downloads, or install again.',
                'error'
            );

            return;
        }

        // Install and update are two of the occasions the store's direct-download protection
        // is probed. A result that is not verified, or that could not be saved, is said here and
        // on every Alfa administrator page until a check verifies it.
        try
        {
            $warning = $steps->checkDownloadStore(root: $root);

            if ($warning !== null)
            {
                Factory::getApplication()->enqueueMessage($warning['message'], $warning['type']);
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
