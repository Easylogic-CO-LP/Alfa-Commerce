<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\ClearData;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Setup\AlfaInstallerGate;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

/**
 * The last Clear Data of each extension, whichever action ran it (Tools > Extensions or Clear everything), and the
 * two marks core writes, always under the lock of Clear everything: the status `clearing`, just before it boots and
 * calls an extension's hook; and the field `loading`, just before it boots a plugin only to see whether it has the hook
 * (a page, a preview). A request that finds a mark while it holds the lock knows the request that wrote it died there:
 * a `clearing` extension is recorded as cleanup failed, and a `loading` one is shown as "could not be loaded" and not
 * booted again until someone asks to try loading it again.
 *
 * Kept in the `clear_status` column of the durable state table (`#__alfa_maintenance`), which Clear everything never
 * drops and an uninstall keeps. One entry per extension key ({@see AlfaInstallerGate::key()}): status (`clearing`,
 * `cleared`, `failed`, `unsupported`, `unavailable`), when (UTC), the source ({@see \Alfa\Component\Alfa\Administrator\Event\Tools\ClearDataEvent}),
 * and the message. Written by core only.
 *
 * @since  2.1.0
 */
final class ClearDataStatus
{
    /**
     * The longest message kept per extension, in characters.
     *
     * @var int
     *
     * @since  2.1.0
     */
    public const MESSAGE_LENGTH = 2000;

    /**
     * How many times a write is tried again when another request changed the record in between.
     *
     * @var int
     *
     * @since  2.1.0
     */
    private const ATTEMPTS = 5;

    /**
     * Record an extension's status. The message is cleaned of invalid UTF-8 and cut to {@see self::MESSAGE_LENGTH}
     * characters, so what an extension says can never make the record unwritable or erase another one's.
     *
     * @param string $key The extension's key ({@see AlfaInstallerGate::key()}).
     * @param string $status clearing, cleared, failed, unsupported or unavailable.
     * @param string $message Why, when there is something to say.
     * @param string $source The action that ran it.
     *
     * @throws RuntimeException When the record cannot be written.
     *
     * @since  2.1.0
     */
    public static function mark(string $key, string $status, string $message = '', string $source = ''): void
    {
        $entry = [
            'status' => $status,
            'at' => gmdate('Y-m-d H:i:s'),
            'source' => $source,
            'message' => self::clean(text: $message),
        ];

        // A `loading` mark stays: only a request that loads the plugin, or someone who asks, takes it away.
        self::write(key: $key, change: static fn (?array $before): array => $entry + (isset($before['loading']) ? ['loading' => $before['loading']] : []));
    }

    /**
     * Set or take away the `loading` mark of an extension, keeping its last Clear Data as it is.
     *
     * @param string $key The extension's key.
     * @param bool $loading True just before core boots it to see whether it has the hook; false once it is booted.
     *
     * @throws RuntimeException When the record cannot be written.
     *
     * @since  2.1.0
     */
    public static function loading(string $key, bool $loading): void
    {
        self::write(key: $key, change: static function (?array $before) use ($loading): ?array {
            $entry = $before ?? [];
            unset($entry['loading']);

            if ($loading) {
                $entry['loading'] = gmdate('Y-m-d H:i:s');
            }

            return $entry === [] ? null : $entry;
        });
    }

    /**
     * Change one extension's entry, compare-and-set on the row's `seq`.
     *
     * @param string $key The extension's key.
     * @param callable(array<string, mixed>|null): (array<string, mixed>|null) $change The entry as it is to what it
     *                                                                                 becomes (null: no entry).
     *
     * @throws RuntimeException When the record cannot be written.
     *
     * @since  2.1.0
     */
    private static function write(string $key, callable $change): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $stored = AlfaInstallerGate::stored(db: $db);

            if ($stored === null) {
                throw new RuntimeException('The Clear Data record of ' . $key . ' cannot be written: the table ' . AlfaInstallerGate::TABLE . ' does not exist.');
            }

            $all = json_decode((string) $stored['clear_status'], true);
            $all = \is_array($all) ? $all : [];
            $entry = $change(\is_array($all[$key] ?? null) ? $all[$key] : null);

            if ($entry === null) {
                unset($all[$key]);
            } else {
                $all[$key] = $entry;
            }

            ksort($all);
            $json = $all === [] ? '{}' : json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($json === false) {
                throw new RuntimeException('The Clear Data record of ' . $key . ' could not be encoded: ' . json_last_error_msg());
            }

            $db->setQuery(
                $db->createQuery()
                    ->update($db->quoteName(AlfaInstallerGate::TABLE))
                    ->set($db->quoteName('clear_status') . ' = ' . $db->quote($json))
                    ->set($db->quoteName('seq') . ' = ' . $db->quoteName('seq') . ' + 1')
                    ->where($db->quoteName('id') . ' = 1')
                    ->where($db->quoteName('seq') . ' = ' . (int) $stored['seq']),
            )->execute();

            if ($db->getAffectedRows() === 1) {
                return;
            }

            self::ensureRow(db: $db);
        }

        throw new RuntimeException('The Clear Data record of ' . $key . ' could not be written: the record kept changing under it.');
    }

    /**
     * The last recorded status of one extension, or null when none is recorded.
     *
     * @param string $key The extension's key.
     *
     * @return array{status?: string, at?: string, source?: string, message?: string, loading?: string}|null
     *
     * @since  2.1.0
     */
    public static function get(string $key): ?array
    {
        $entry = self::all()[$key] ?? null;

        return \is_array($entry) ? $entry : null;
    }

    /**
     * Every recorded status, by extension key.
     *
     * @return array<string, array<string, mixed>>
     *
     * @since  2.1.0
     */
    public static function all(): array
    {
        $stored = AlfaInstallerGate::stored();
        $all = json_decode((string) ($stored['clear_status'] ?? ''), true);

        return \is_array($all) ? $all : [];
    }

    /**
     * A text an extension or the system gave, made safe to keep and to show: valid UTF-8, at most
     * {@see self::MESSAGE_LENGTH} characters.
     *
     * @param string $text The text.
     *
     * @since  2.1.0
     */
    public static function clean(string $text): string
    {
        return mb_substr((string) mb_scrub($text, 'UTF-8'), 0, self::MESSAGE_LENGTH);
    }

    /**
     * Make the state's one row when the table has none (the install SQL seeds it; a table emptied by hand is given it
     * back).
     *
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.1.0
     */
    public static function ensureRow(DatabaseInterface $db): void
    {
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName(AlfaInstallerGate::TABLE) . ' (' . $db->quoteName('id') . ') SELECT 1 FROM DUAL'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $db->quoteName(AlfaInstallerGate::TABLE) . ' WHERE ' . $db->quoteName('id') . ' = 1)',
        )->execute();
    }
}
