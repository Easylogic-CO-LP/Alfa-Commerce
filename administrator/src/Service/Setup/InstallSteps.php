<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Service\Download\Storage;
use Alfa\Component\Alfa\Administrator\Service\Download\StoreProtection;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

/**
 * The steps that follow the creation of the Alfa tables, shared by the installer's postflight and
 * by Clear everything, which recreates the tables with the same install SQL and then takes the
 * same steps a fresh install takes.
 *
 * Each step does its work or throws; the caller decides how a failure is said (the installer as a
 * message on the installation page, Clear everything as the step it stopped at). None of them
 * touches a row it did not create, except where its own description says so.
 *
 * @since  2.1.0
 */
final class InstallSteps
{
    /**
     * @param DatabaseInterface $db The connection the steps write through.
     *
     * @since  2.1.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Record a schema version as the one the Alfa tables are at (`schema_version` in
     * `#__alfa_meta`), once an install, an update or a recreation has brought them there.
     *
     * The install SQL writes it already on a fresh install; an update raises it after Joomla has
     * run the update files. It is never lowered: an older package installed over newer tables does
     * not make them older, and a later reinstall must still be told what they are.
     *
     * @param string $version The schema the tables are at now.
     *
     * @since  2.1.0
     */
    public function recordSchemaVersion(string $version): void
    {
        $db = $this->db;
        $recorded = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote('schema_version')),
        )->loadResult();

        if ($recorded === null) {
            $db->setQuery(
                $db->createQuery()
                    ->insert($db->quoteName('#__alfa_meta'))
                    ->columns($db->quoteName(['name', 'value']))
                    ->values($db->quote('schema_version') . ', ' . $db->quote($version)),
            )->execute();
        } elseif (version_compare((string) $recorded, $version, '<')) {
            $db->setQuery(
                $db->createQuery()
                    ->update($db->quoteName('#__alfa_meta'))
                    ->set($db->quoteName('value') . ' = ' . $db->quote($version))
                    ->where($db->quoteName('name') . ' = ' . $db->quote('schema_version')),
            )->execute();
        }
    }

    /**
     * Write the installed Options ({@see ComponentOptions::DEFAULTS}).
     *
     * An install or update adds only the keys the stored Options lack: existing values win and
     * nothing is ever overwritten. Clear everything replaces them: the Options are then exactly
     * what a fresh install writes, with the records a reset carries over
     * ({@see ComponentOptions::CARRIED}).
     *
     * @param bool $replace True to replace the stored Options, false to add what they lack.
     *
     * @since  2.1.0
     */
    public function applyDefaultOptions(bool $replace = false): void
    {
        $stored = ComponentOptions::stored(db: $this->db);

        ComponentOptions::write(
            params: $replace
                ? ComponentOptions::DEFAULTS + ComponentOptions::carried(stored: $stored)
                : ComponentOptions::withDefaults(stored: $stored),
            db: $this->db,
        );
    }

    /**
     * Map the shop's delivery destination to the country field the install SQL seeded, by the id
     * the database actually gave it. Taken on a fresh install only, and by Clear everything, which
     * leaves the shop as a fresh install.
     *
     * WHY THIS IS WRITTEN AT ALL, when an unconfigured shop with exactly one place field already
     * infers the same answer: an explicit mapping is a decision the merchant can SEE in Options and
     * change, and it stays a decision when they later add a second place field; inference would go
     * ambiguous at that moment and stop offering place-restricted methods on a shop that had been
     * working. Seeding it means adding a field never silently changes where existing orders are
     * priced to.
     *
     * NEVER A HARDCODED ID. The seed's literal `4` is a fresh AUTO_INCREMENT, not a promise: the
     * same install re-run against a table that already holds rows, or a merchant who deletes and
     * rebuilds the field, gets a different one. The row is found by what it IS (a published place
     * field shown in the delivery section), which is the same question the runtime resolver asks.
     *
     * NOT ON AN UPDATE. On an update the mapping is whatever the merchant has: a value they chose
     * is theirs to keep, and an empty one is a shop that has been inferring correctly and must go
     * on doing so. Writing then would silently convert an inferring shop into a mapped one, so that
     * deleting the field would later report "mapped to a field that is gone" instead of "no field":
     * a different diagnosis of the same shop. The caller takes this step only where it belongs.
     *
     * A DELETED MAPPING STAYS BROKEN, which is the point. Nothing repairs this value afterwards;
     * FieldsHelper answers UNMAPPED and the checkout says so, because quietly re-pointing the
     * destination at another field would price orders against an address the merchant never chose.
     *
     * @since  2.1.0
     */
    public function seedDeliveryPlaceMapping(): void
    {
        $params = ComponentOptions::stored(db: $this->db);

        // Already decided, by a merchant, or by a previous run of this.
        if ((int) ($params['delivery_place_field'] ?? 0) > 0) {
            return;
        }

        $candidate = $this->deliveryPlaceCandidate();

        // Exactly one is the only case that can be answered without guessing. None, or several,
        // is left unmapped on purpose: the resolver reports MISSING or AMBIGUOUS and the checkout
        // asks the merchant to choose, which is the whole reason this setting exists.
        if ($candidate === null) {
            return;
        }

        $params['delivery_place_field'] = $candidate;

        ComponentOptions::write(params: $params, db: $this->db);
    }

    /**
     * The field a fresh install maps the delivery destination to: the one published place field
     * shown in the delivery section, or null when there is none or more than one.
     *
     * The same eligibility the runtime resolver applies.
     *
     * @since  2.1.0
     */
    public function deliveryPlaceCandidate(): ?int
    {
        $db = $this->db;
        $candidates = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_form_fields'))
                ->where($db->quoteName('type') . ' = ' . $db->quote(FieldsHelper::PLACE_FIELD_TYPE))
                ->where($db->quoteName('state') . ' = 1')
                ->where($db->quoteName('shipping') . ' > 0')
                ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC'),
        )->loadColumn() ?: [];

        return \count($candidates) === 1 ? (int) $candidates[0] : null;
    }

    /**
     * Settle the download store and show its path in the Options.
     *
     * The store lives outside every extension folder and its path is recorded among the Alfa
     * tables, so an uninstall leaves the purchased files with the rows that point at them, and a
     * reinstall finds them again ({@see Storage::provisionRoot()}). Joomla resets the Options on a
     * reinstall; the recorded path is written back into `downloads_storage_path` here, and a path
     * the shop saved there is recorded. Idempotent: it creates what is missing only.
     *
     * @return string The store's root.
     *
     * @throws RuntimeException When the folder or its recorded path cannot be made (the reason is
     *                          also written to the downloads log).
     *
     * @since  2.1.0
     */
    public function provisionDownloadStore(): string
    {
        // An install request does not boot the component, so its log categories are not
        // registered yet: the reason of a failure goes to the downloads log, which must exist.
        AlfaLog::register();

        $params = ComponentOptions::stored(db: $this->db);
        $configured = (string) ($params['downloads_storage_path'] ?? '');
        $root = Storage::provisionRoot(configured: $configured);

        if ($configured !== $root) {
            $params['downloads_storage_path'] = $root;

            ComponentOptions::write(params: $params, db: $this->db);
        }

        return $root;
    }

    /**
     * Probe the store's direct-download protection (install, update and Clear everything are three
     * of the occasions it is probed), and say what a person must know about the result.
     *
     * @param string $root The store's root, as {@see self::provisionDownloadStore()} settled it.
     *
     * @return array{message: string, type: string}|null The warning, or null when the store is protected.
     *
     * @since  2.1.0
     */
    public function checkDownloadStore(string $root): ?array
    {
        Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

        $protection = new StoreProtection(knownRoot: $root);
        $result = $protection->check();
        $warning = $protection->warning(result: $result);

        if ($warning === null) {
            return null;
        }

        return ['message' => $warning, 'type' => $result->state === StoreProtection::STATE_EXPOSED ? 'error' : 'warning'];
    }

    /**
     * Give every Joomla user missing from the Alfa users table its row.
     *
     * @return int The rows added.
     *
     * @since  2.1.0
     */
    public function syncUsers(): int
    {
        return SyncHelper::bulkSyncUsers(db: $this->db);
    }

    /**
     * Give every Joomla user group missing from the Alfa groups table its row, with the price
     * display an installation gives a group: built from {@see ComponentOptions::DEFAULTS}.
     *
     * @return int The rows added.
     *
     * @since  2.1.0
     */
    public function syncUsergroups(): int
    {
        return SyncHelper::bulkSyncUsergroups(db: $this->db, sourceParams: ComponentOptions::DEFAULTS);
    }

    /**
     * Create or complete the per-language tables of every translatable entity, for every content
     * language this site has.
     *
     * @return array{tables: array<string, mixed>, errors: array<string, string>}
     *
     * @since  2.1.0
     */
    public function syncLanguageSchema(): array
    {
        return SyncHelper::syncLanguageSchema();
    }

    /**
     * The schema the installed package's install SQL creates: the newest file in its
     * sql/updates/mysql, the version Joomla records for the component at install, and the one the
     * installer script names SCHEMA_VERSION.
     *
     * @throws RuntimeException When the installed package holds no update file.
     *
     * @since  2.1.0
     */
    public static function installedSchemaVersion(): string
    {
        $versions = array_map(
            static fn (string $file): string => basename($file, '.sql'),
            glob(JPATH_ADMINISTRATOR . '/components/com_alfa/sql/updates/mysql/*.sql') ?: [],
        );

        if ($versions === []) {
            throw new RuntimeException('The installed Alfa Commerce holds no schema update file.');
        }

        usort($versions, 'version_compare');

        return (string) end($versions);
    }
}
