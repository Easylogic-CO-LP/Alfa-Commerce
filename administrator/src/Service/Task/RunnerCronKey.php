<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * The web cron's secret: 32 random bytes from a cryptographically secure generator, encoded for a URL. Only its
 * SHA-256 is kept (#__alfa_meta runner.cron_key); a key is checked against it in constant time. A new key replaces
 * the old one, and its plain value is answered once, to the one who asked for it: it is never written to a log, a
 * message or a notification. The address carries the key in its query string, so the web server's access log records
 * it.
 *
 * @since  2.2.0
 */
final class RunnerCronKey
{
    /**
     * The #__alfa_meta row of the key's hash.
     *
     * @since  2.2.0
     */
    public const META = 'runner.cron_key';

    /**
     * The longest value read as a key: a key is 43 characters, and anything longer is refused unread.
     *
     * @since  2.2.0
     */
    public const MAX_LENGTH = 128;

    /**
     * Make a new key, keep its hash in place of the old one's, and answer the key: the only time it is seen.
     *
     * @since  2.2.0
     */
    public static function generate(): string
    {
        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db = Factory::getContainer()->get('DatabaseDriver');
        $name = self::META;
        $hash = hash('sha256', $key);
        $again = $hash;
        $db->setQuery(
            $db->getQuery(true)->setQuery(
                'INSERT INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ') VALUES (:name, :value)'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('value') . ' = :again',
            )
                ->bind(':name', $name)
                ->bind(':value', $hash)
                ->bind(':again', $again),
        )->execute();

        return $key;
    }

    /**
     * Make a key when none is kept (install and update): nobody sees it; staff make the one they copy in Tools > Tasks.
     *
     * @since  2.2.0
     */
    public static function ensure(): void
    {
        if (self::stored() === '') {
            self::generate();
        }
    }

    /**
     * Whether a key is the one kept: its hash compared with the stored hash in constant time. No key kept, or none
     * given, never matches.
     *
     * @param string $key The key a request carries.
     *
     * @since  2.2.0
     */
    public static function matches(string $key): bool
    {
        $stored = self::stored();
        $given = hash('sha256', $key);

        return $stored !== '' && $key !== '' && hash_equals($stored, $given);
    }

    /**
     * Whether a key is kept: Tools > Tasks offers to make the first one when none is.
     *
     * @since  2.2.0
     */
    public static function kept(): bool
    {
        return self::stored() !== '';
    }

    /**
     * The hash kept, or '' when there is none.
     *
     * @since  2.2.0
     */
    private static function stored(): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return (string) $db->setQuery(
            'SELECT ' . $db->quoteName('value') . ' FROM ' . $db->quoteName('#__alfa_meta') . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote(self::META),
        )->loadResult();
    }
}
