<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * A dismissible notification that follows a condition of the site, told as a list of facts (the libraries that are
 * missing, the extensions not built for this version, the page cache's state): raised when a fact appears, withdrawn
 * when none is left. A notice the administrator dismissed stays dismissed while its facts hold; a new fact raises it
 * again, unread; a fact that goes away updates the notice in place, its read and dismissed state kept. A fact the
 * caller names calm (the better of two states, such as the page cache's note after its warning) is no news: when it
 * replaces another fact the notice is updated in place too, so only a worse state raises it again.
 *
 * The facts last told are kept in `#__alfa_meta` (`notice.<dedup key>`), so the notice is raised once for each change
 * and not again when its dismissed row has expired from the history.
 *
 * @since  2.2.2
 */
final class ConditionNotice
{
    /**
     * The prefix of the `#__alfa_meta` row that keeps a notice's facts.
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const META_PREFIX = 'notice.';

    /**
     * Follow the condition: compare the facts with those last told and raise, update or withdraw the notice.
     *
     * @param string $key The notice's dedup key, at most 57 characters (the meta row's name holds 64).
     * @param list<string> $facts What holds now; empty when there is nothing to say.
     * @param callable(): void $push Pushes the notice under $key (NotificationHelper::push(), not constant), with
     *                               the words of these facts.
     * @param list<string> $calm Facts that are no news once the notice has been raised: their arrival updates the
     *                           notice in place, its read and dismissed state kept. The first raise is always news.
     *
     * @throws InvalidArgumentException When the key is too long for its meta row.
     *
     * @since  2.2.2
     */
    public static function follow(string $key, array $facts, callable $push, array $calm = []): void
    {
        $facts = self::normalise(facts: $facts);
        $told = self::told(key: $key);

        if ($facts === []) {
            if ($told !== null) {
                if (NotificationHelper::isOpen(dedupKey: $key)) {
                    NotificationHelper::clear(dedupKey: $key);
                }

                self::forget(key: $key);
            }

            return;
        }

        if ($told === $facts) {
            return;
        }

        $news = array_diff($facts, $told ?? [], $told === null ? [] : self::normalise(facts: $calm));

        if ($news !== []) {
            // A new fact: a notice of its own, unread, even when the last one was dismissed.
            if (NotificationHelper::exists(dedupKey: $key)) {
                NotificationHelper::clear(dedupKey: $key, hard: true);
            }

            $push();
        } elseif (NotificationHelper::exists(dedupKey: $key)) {
            // Fewer facts, or a calm one in place of another: the words brought up to date, the notice's read and
            // dismissed state kept.
            $push();
        }

        self::remember(key: $key, facts: $facts);
    }

    /**
     * The facts last told for the notice; null when none are kept.
     *
     * @param string $key The notice's dedup key.
     *
     * @return list<string>|null
     *
     * @throws InvalidArgumentException When the key is too long for its meta row.
     *
     * @since  2.2.2
     */
    public static function told(string $key): ?array
    {
        $db = self::db();
        $name = self::name(key: $key);
        $value = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = :name')
                ->bind(':name', $name, ParameterType::STRING),
        )->loadResult();

        if ($value === null) {
            return null;
        }

        $facts = json_decode((string) $value, true);

        return \is_array($facts) ? self::normalise(facts: $facts) : [];
    }

    /**
     * The facts sorted, unique, as text.
     *
     * @param array<mixed> $facts The facts.
     *
     * @return list<string>
     *
     * @since  2.2.2
     */
    private static function normalise(array $facts): array
    {
        $facts = array_values(array_unique(array_map('strval', array_filter($facts, 'is_scalar'))));
        sort($facts, SORT_STRING);

        return $facts;
    }

    /**
     * Keep the facts told: one row, written whole (MariaDB 10.6 and MySQL 8.0 alike: a literal value, no VALUES()).
     *
     * @param string $key The notice's dedup key.
     * @param list<string> $facts The facts.
     *
     * @since  2.2.2
     */
    private static function remember(string $key, array $facts): void
    {
        $db = self::db();
        $value = $db->quote((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ')'
            . ' VALUES (' . $db->quote(self::name(key: $key)) . ', ' . $value . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('value') . ' = ' . $value,
        )->execute();
    }

    /**
     * Forget the facts told.
     *
     * @param string $key The notice's dedup key.
     *
     * @since  2.2.2
     */
    private static function forget(string $key): void
    {
        $db = self::db();
        $name = self::name(key: $key);
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = :name')
                ->bind(':name', $name, ParameterType::STRING),
        )->execute();
    }

    /**
     * The meta row's name of a notice.
     *
     * @param string $key The notice's dedup key.
     *
     * @throws InvalidArgumentException When the key is too long for the row's name.
     *
     * @since  2.2.2
     */
    private static function name(string $key): string
    {
        $name = self::META_PREFIX . $key;

        if ($key === '' || \strlen($name) > 64) {
            throw new InvalidArgumentException("The notice key '{$key}' is empty or longer than its meta row's name holds.");
        }

        return $name;
    }

    /**
     * The database driver.
     *
     * @since  2.2.2
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
