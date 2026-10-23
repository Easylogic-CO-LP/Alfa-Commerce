<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * Tells the administrators how much of the shop a content language added later still lacks.
 *
 * A content language published after the shop already has products, categories and the rest
 * reads every untranslated text in the default content language, so nothing is broken: this is a
 * warning, never an error. One notice per language says how many records of each translatable
 * kind have no text of their own in it yet, and links to Tools: Backfill empty translations. It
 * never runs Backfill.
 *
 * NEW TO ALFA. A language is new when it is published while Alfa has no language tables for it
 * ({@see self::newLanguages()}, read before the language sync makes them). The languages published
 * when Alfa was installed got their tables from the installer, so they never count as new, and a
 * language published again keeps the tables it had, so a notice once dismissed stays dismissed
 * until another language is added.
 *
 * WHAT IS COUNTED. The tables Backfill fills ({@see SyncHelper::getMultilingualSchema()}), and in
 * each the records Backfill would fill: no row in the language, or an empty name (title for a form
 * field group), while another language has one.
 *
 * KEPT CURRENT. Recounted after a save writes the language for a counted table, and after Backfill
 * finishes, only while the notice stands: a dismissed or cleared notice is never touched again.
 * Cleared when nothing is left. Never on a storefront request.
 *
 * @since  2.1.1
 */
final class ContentLanguageAddedNotice
{
    /**
     * The dedup key prefix: one notice per language ({@see self::key()}).
     *
     * @var string
     * @since  2.1.1
     */
    public const KEY_PREFIX = 'alfa.translations.';

    /**
     * Where the notice leads: Tools, Database Sync, where Backfill empty translations is.
     *
     * @var string
     * @since  2.1.1
     */
    public const BACKFILL_URL = 'index.php?option=com_alfa&view=tools#dbsync';

    /**
     * The tables the notice names first, as a merchant counts the shop; the others follow in Backfill's order.
     *
     * @var string[]
     * @since  2.1.1
     */
    private const LEAD_TABLES = ['#__alfa_items', '#__alfa_categories', '#__alfa_manufacturers'];

    /**
     * The languages whose notice stands, as this request found them (null until read).
     *
     * @var string[]|null
     * @since  2.1.1
     */
    private static ?array $standing = null;

    /**
     * The dedup key of one language's notice.
     *
     * @param string $langCode The language, lower-snake ("el_gr") or Joomla's form ("el-GR").
     *
     * @return string e.g. "alfa.translations.el-GR".
     *
     * @since  2.1.1
     */
    public static function key(string $langCode): string
    {
        return self::KEY_PREFIX . self::display(code: $langCode);
    }

    /**
     * The published content languages Alfa has no language tables for yet. Asked before the
     * language sync makes them; read from the database, not from the request's cached list.
     *
     * @return string[] Lower-snake codes.
     *
     * @since  2.1.1
     */
    public static function newLanguages(): array
    {
        try {
            $db = self::db();
            $new = [];

            foreach ((array) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('lang_code'))
                    ->from($db->quoteName('#__languages'))
                    ->where($db->quoteName('published') . ' = 1'),
            )->loadColumn() as $tag) {
                $code = MultilingualHelper::normaliseTag(tag: (string) $tag);

                if (!MultilingualHelper::hasLanguageTable(tableName: '#__alfa_items', langTag: $code)) {
                    $new[] = $code;
                }
            }

            return $new;
        } catch (Throwable $e) {
            self::warn(message: 'The new content languages could not be read: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Raise the notice of each language just added, when the shop has records it lacks.
     *
     * @param string[] $codes The languages added ({@see self::newLanguages()}).
     *
     * @since  2.1.1
     */
    public static function languagesAdded(array $codes): void
    {
        foreach ($codes as $code) {
            try {
                $counts = self::counts(langCode: (string) $code);

                if (array_sum($counts) > 0) {
                    self::write(langCode: (string) $code, counts: $counts);

                    // The rest of this request sees it standing.
                    if (self::$standing !== null) {
                        self::$standing = array_values(array_unique([...self::$standing, MultilingualHelper::normaliseTag(tag: (string) $code)]));
                    }
                }
            } catch (Throwable $e) {
                self::warn(message: 'The notice of the added language ' . $code . ' failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * After a save that wrote some languages of one table: recount each of those languages whose
     * notice stands, when the table is one that is counted. Nothing at all while no notice stands.
     *
     * @param string $tableName The base table saved (e.g. "#__alfa_items").
     * @param string[] $langCodes The languages the save wrote.
     *
     * @since  2.1.1
     */
    public static function afterSave(string $tableName, array $langCodes): void
    {
        try {
            $standing = array_intersect(
                self::standing(),
                array_map(static fn ($code): string => MultilingualHelper::normaliseTag(tag: (string) $code), $langCodes),
            );

            if ($standing === [] || !\array_key_exists($tableName, self::countedTables())) {
                return;
            }

            foreach ($standing as $code) {
                self::refresh(langCode: $code);
            }
        } catch (Throwable $e) {
            self::warn(message: 'The translation counts were not brought up to date after a save: ' . $e->getMessage());
        }
    }

    /**
     * After Backfill finished: recount every language whose notice stands.
     *
     * @since  2.1.1
     */
    public static function afterBackfill(): void
    {
        try {
            foreach (self::standing() as $code) {
                self::refresh(langCode: $code);
            }
        } catch (Throwable $e) {
            self::warn(message: 'The translation counts were not brought up to date after Backfill: ' . $e->getMessage());
        }
    }

    /**
     * The records each counted table still lacks in a language: no row there, or an empty name
     * (title), while another language has one, which is what Backfill would fill.
     *
     * @param string $langCode The language.
     *
     * @return array<string, int> Base table => records, in Backfill's order.
     *
     * @since  2.1.1
     */
    public static function counts(string $langCode): array
    {
        $db = self::db();
        $code = MultilingualHelper::normaliseTag(tag: $langCode);
        $sources = array_values(array_diff(
            array_unique(array_merge([MultilingualHelper::getDefaultLanguageTag()], MultilingualHelper::getLanguageTags())),
            [$code],
        ));
        $counts = [];

        foreach (self::countedTables() as $table => $definition) {
            $field = \in_array('name', $definition['fields'], true) ? 'name' : (\in_array('title', $definition['fields'], true) ? 'title' : '');

            if ($field === '') {
                continue;
            }

            $filled = [];
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName($table, 'm'));

            foreach ($sources as $i => $source) {
                if (!MultilingualHelper::hasLanguageTable(tableName: $table, langTag: $source)) {
                    continue;
                }

                $query->join(
                    'LEFT',
                    $db->quoteName($table . '_' . $source, 's' . $i),
                    $db->quoteName('s' . $i . '.' . $definition['pk']) . ' = ' . $db->quoteName('m.id'),
                );
                $filled[] = 'COALESCE(' . $db->quoteName('s' . $i . '.' . $field) . ", '') <> ''";
            }

            if ($filled === []) {
                $counts[$table] = 0;

                continue;
            }

            $query->where('(' . implode(' OR ', $filled) . ')');

            if (MultilingualHelper::hasLanguageTable(tableName: $table, langTag: $code)) {
                $query->join(
                    'LEFT',
                    $db->quoteName($table . '_' . $code, 'l'),
                    $db->quoteName('l.' . $definition['pk']) . ' = ' . $db->quoteName('m.id'),
                )->where('COALESCE(' . $db->quoteName('l.' . $field) . ", '') = ''");
            }

            $counts[$table] = (int) $db->setQuery($query)->loadResult();
        }

        return $counts;
    }

    /**
     * Recount one language whose notice stands: cleared when nothing is left, else rewritten with
     * the counts as they are now (never raised again as unread).
     *
     * @param string $langCode The language.
     *
     * @since  2.1.1
     */
    private static function refresh(string $langCode): void
    {
        $counts = self::counts(langCode: $langCode);

        if (array_sum($counts) === 0) {
            NotificationHelper::clear(dedupKey: self::key(langCode: $langCode));
            self::$standing = array_values(array_diff(self::standing(), [MultilingualHelper::normaliseTag(tag: $langCode)]));

            return;
        }

        self::write(langCode: $langCode, counts: $counts);
    }

    /**
     * Write one language's notice with its counts: a warning, dismissible, leading to Backfill.
     *
     * @param string $langCode The language.
     * @param array<string, int> $counts What {@see self::counts()} returned.
     *
     * @since  2.1.1
     */
    private static function write(string $langCode, array $counts): void
    {
        Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
        $label = MultilingualHelper::languageLabel(langCode: $langCode);
        $parts = [];
        $lead = array_flip(self::LEAD_TABLES);
        uksort($counts, static fn (string $a, string $b): int => ($lead[$a] ?? PHP_INT_MAX) <=> ($lead[$b] ?? PHP_INT_MAX));

        foreach ($counts as $table => $count) {
            if ($count > 0) {
                $parts[] = self::countText(table: $table, count: $count);
            }
        }

        $last = array_pop($parts);
        $list = $parts === [] ? (string) $last : implode(', ', $parts) . ' ' . Text::_('COM_ALFA_TRANSLATIONS_AND') . ' ' . $last;

        NotificationHelper::push(
            dedupKey: self::key(langCode: $langCode),
            title:    Text::sprintf('COM_ALFA_NOTIFY_TRANSLATIONS_TITLE', $label),
            options:  [
                'group' => Text::_('COM_ALFA_NOTIFY_GROUP_SETUP'),
                'severity' => 'warning',
                'message' => Text::sprintf('COM_ALFA_NOTIFY_TRANSLATIONS_MSG', $label, $list),
                'url' => self::BACKFILL_URL,
            ],
        );
    }

    /**
     * "2,503 products": a count of one table's records in words, the number grouped as the
     * administrator's language groups it.
     *
     * @param string $table The base table (e.g. "#__alfa_items").
     * @param int $count The records.
     *
     * @since  2.1.1
     */
    private static function countText(string $table, int $count): string
    {
        $key = 'COM_ALFA_TRANSLATIONS_N_' . strtoupper(substr($table, \strlen('#__alfa_')));
        $key = Factory::getApplication()->getLanguage()->hasKey($key) ? $key : 'COM_ALFA_TRANSLATIONS_N_OTHER';
        $separator = Text::_('THOUSANDS_SEPARATOR');

        return Text::plural($key, $count, number_format($count, 0, '', $separator === 'THOUSANDS_SEPARATOR' ? ',' : $separator));
    }

    /**
     * The languages whose notice stands: one read of the notice keys per request, then one
     * check per key found (usually none).
     *
     * @return string[] Lower-snake codes.
     *
     * @since  2.1.1
     */
    private static function standing(): array
    {
        if (self::$standing !== null) {
            return self::$standing;
        }

        self::$standing = [];

        foreach (NotificationHelper::keys(prefix: self::KEY_PREFIX) as $key) {
            if (NotificationHelper::isOpen(dedupKey: (string) $key)) {
                self::$standing[] = MultilingualHelper::normaliseTag(tag: substr((string) $key, \strlen(self::KEY_PREFIX)));
            }
        }

        return self::$standing;
    }

    /**
     * The tables Backfill fills, read once per request.
     *
     * @return array<string, array{pk: string, fields: string[]}>
     *
     * @since  2.1.1
     */
    private static function countedTables(): array
    {
        static $tables = null;

        return $tables ??= SyncHelper::getMultilingualSchema();
    }

    /**
     * Joomla's form of a language code: "el_gr" -> "el-GR".
     *
     * @param string $code The language, either form.
     *
     * @since  2.1.1
     */
    private static function display(string $code): string
    {
        $parts = explode('_', MultilingualHelper::normaliseTag(tag: $code));

        foreach ($parts as $i => $part) {
            if ($i > 0 && \strlen($part) === 2) {
                $parts[$i] = strtoupper($part);
            }
        }

        return implode('-', $parts);
    }

    /**
     * Say a failure in the setup log. A Content Languages request has not booted the component, so the log is
     * registered here first.
     *
     * @param string $message The line.
     *
     * @since  2.1.1
     */
    private static function warn(string $message): void
    {
        AlfaLog::register();
        Log::add($message, Log::WARNING, 'com_alfa.setup');
    }

    /**
     * The site's database connection.
     *
     * @since  2.1.1
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
