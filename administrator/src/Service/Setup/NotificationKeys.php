<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use ReflectionClass;
use Throwable;

\defined('_JEXEC') or die;

/**
 * The notifications written before 2.1.4 given their language keys and parameters, once, by the install script after
 * the SQL (2.1.4).
 *
 * A row is identified by its dedup key family, never by its text. Its stored title and message stay exactly as they are
 * and become its snapshot: they are never rendered again. The mapping only adds the keys, their parameters and the
 * group key; a parameter is read from the dedup key, or from the stored text by the key's own pattern in each installed
 * administrator language. A row whose text matches no pattern keeps its text and no key, and is shown as written (its
 * group is still mapped when its family is known, or when it is exactly the text of a core group key in an installed
 * administrator language). Only rows with no key yet are read, so a second run changes nothing.
 *
 * @since  2.1.4
 */
final class NotificationKeys
{
    /**
     * The languages read so far, by tag and domain, each with the domain's file loaded.
     *
     * @var array<string, Language>
     *
     * @since  2.1.4
     */
    private static array $languages = [];

    /**
     * The core group keys by their text in each installed administrator language, once read ({@see self::coreGroup()}).
     *
     * @var array<string, string>|null
     *
     * @since  2.1.4
     */
    private static ?array $groups = null;

    /**
     * Map every row that has no key yet.
     *
     * @param DatabaseDriver|null $db The connection; the container's when null.
     *
     * @return array{rows: int, keyed: int, grouped: int, unmatched: int} The rows read, those given a key, those given only
     *                                                                    a group key, and those left as they were.
     *
     * @since  2.1.4
     */
    public static function map(?DatabaseDriver $db = null): array
    {
        $db ??= Factory::getContainer()->get('DatabaseDriver');
        $table = $db->quoteName('#__alfa_notifications');
        $rows = $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'dedup_key', 'notify_group', 'title', 'message'])) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteName('title_key') . " = '' AND " . $db->quoteName('message_key') . " = ''",
        )->loadObjectList() ?: [];
        $counts = ['rows' => \count($rows), 'keyed' => 0, 'grouped' => 0, 'unmatched' => 0];

        foreach ($rows as $row) {
            $mapping = self::mapping(row: $row);
            $set = [];
            $args = [];

            foreach (['title', 'message'] as $field) {
                if ($mapping[$field] !== null) {
                    [$key, $values] = $mapping[$field];
                    $set[] = $db->quoteName($field . '_key') . ' = ' . $db->quote($key);
                    $args[$field] = $values;
                }
            }

            if ($set !== []) {
                $set[] = $db->quoteName('text_domain') . ' = ' . $db->quote($mapping['domain']);
                $set[] = $db->quoteName('text_args') . ' = ' . $db->quote((string) json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            }

            // A family's own group key; else a group stored as a core group's text, matched exactly (a third party's row
            // that took core's default group, translated, before 2.1.4).
            $group = $mapping['group'] ?? self::coreGroup(stored: (string) $row->notify_group);
            $grouped = $group !== null && $group !== (string) $row->notify_group;

            if ($grouped) {
                $set[] = $db->quoteName('notify_group') . ' = ' . $db->quote($group);
            }

            if ($set === []) {
                $counts['unmatched']++;

                continue;
            }

            // Only while the row still has no key: a producer that wrote it meanwhile wrote its own.
            $db->setQuery(
                'UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $row->id
                . ' AND ' . $db->quoteName('title_key') . " = '' AND " . $db->quoteName('message_key') . " = ''",
            )->execute();

            if ($args !== []) {
                $counts['keyed']++;
            } else {
                $counts['grouped']++;
            }
        }

        try {
            Log::add(
                entry: sprintf(
                    'Notifications given their language keys (2.1.4): %d rows without a key read, %d keyed, %d given a group key only, %d left as written.',
                    $counts['rows'],
                    $counts['keyed'],
                    $counts['grouped'],
                    $counts['unmatched'],
                ),
                priority: Log::INFO,
                category: 'com_alfa.setup',
            );
        } catch (Throwable) {
            // An unwritable log changes nothing that already happened.
        }

        return $counts;
    }

    /**
     * What a row maps to, by its dedup key family: its domain, its group key and, for its title and its message, a key
     * and its parameters, or null when the field keeps its text and no key.
     *
     * @param object $row The row: dedup_key, notify_group, title, message.
     *
     * @return array{domain: string, group: string|null, title: array{0: string, 1: list<mixed>}|null, message: array{0: string, 1: list<mixed>}|null}
     *
     * @since  2.1.4
     */
    public static function mapping(object $row): array
    {
        $key = (string) $row->dedup_key;
        $title = (string) $row->title;
        $message = (string) ($row->message ?? '');
        $none = ['domain' => NotificationText::DOMAIN, 'group' => null, 'title' => null, 'message' => null];
        $id = static fn (string $prefix): ?int => preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $key, $m) === 1 ? (int) $m[1] : null;
        $fixed = static fn (string $textKey, array $values = []): array => [$textKey, $values];

        if (($order = $id('order:')) !== null) {
            return ['group' => NotificationHelper::GROUP_ORDERS, 'title' => $fixed('COM_ALFA_NOTIFY_NEW_ORDER_TITLE', [$order])] + $none;
        }

        if (($order = $id('orders.activitylog.')) !== null) {
            return [
                'group' => NotificationHelper::GROUP_ORDERS,
                'title' => $fixed('COM_ALFA_NOTIFY_ACTIVITY_LOST_TITLE', [$order]),
                'message' => $fixed('COM_ALFA_NOTIFY_ACTIVITY_LOST_MSG'),
            ] + $none;
        }

        if (($status = $id('orders.creator.badstatus.')) !== null) {
            return [
                'group' => NotificationHelper::GROUP_ORDERS,
                'title' => $fixed('COM_ALFA_NOTIFY_BAD_STATUS_TITLE', [$status]),
                'message' => $fixed('COM_ALFA_NOTIFY_BAD_STATUS_MSG'),
            ] + $none;
        }

        if (preg_match('/^documents\.autoissue\.(\d+)\.(failed|vat|failed\.(.+)|noseries\.(.+)|charges\.(.+))$/', $key, $m) === 1) {
            $order = (int) $m[1];
            $type = (string) (($m[3] ?? '') . ($m[4] ?? '') . ($m[5] ?? ''));
            $charges = str_starts_with($m[2], 'charges.') ? self::parse(text: $title, key: 'COM_ALFA_NOTIFY_AUTOISSUE_CHARGES_TITLE') : null;

            return [
                'group' => NotificationHelper::GROUP_DOCUMENTS,
                'title' => match (true) {
                    $m[2] === 'failed' => $fixed('COM_ALFA_NOTIFY_AUTOISSUE_FAILED_TITLE', [$order]),
                    $m[2] === 'vat' => $fixed('COM_ALFA_NOTIFY_AUTOISSUE_VAT_TITLE', [$order]),
                    str_starts_with($m[2], 'failed.') => $fixed('COM_ALFA_NOTIFY_AUTOISSUE_FAILED_TYPE_TITLE', [$order, DocumentHelper::typeText($type)]),
                    str_starts_with($m[2], 'noseries.') => $fixed('COM_ALFA_NOTIFY_AUTOISSUE_NOSERIES_TITLE', [$order, DocumentHelper::typeText($type)]),
                    $charges !== null && (int) $charges[0] === $order => $fixed('COM_ALFA_NOTIFY_AUTOISSUE_CHARGES_TITLE', [$order, $charges[1]]),
                    default => null,
                },
                'message' => $fixed('COM_ALFA_NOTIFY_AUTOISSUE_MSG'),
            ] + $none;
        }

        if (($order = $id(InventoryEffectOutbox::ORDER_NOTICE_PREFIX)) !== null) {
            return [
                'group' => NotificationHelper::GROUP_INVENTORY,
                'title' => $fixed('COM_ALFA_NOTIFY_STOCK_ORDER_TITLE', [$order]),
                'message' => self::first(text: $message, keys: ['COM_ALFA_NOTIFY_STOCK_ORDER_MSG', 'COM_ALFA_NOTIFY_STOCK_ORDER_REFUSED_MSG', 'COM_ALFA_NOTIFY_STOCK_ORDER_RESERVED_MSG']),
            ] + $none;
        }

        if ($id(InventoryEffectOutbox::DOCUMENT_NOTICE_PREFIX) !== null) {
            return [
                'group' => NotificationHelper::GROUP_INVENTORY,
                'title' => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_STOCK_DOCUMENT_TITLE']),
                'message' => self::first(text: $message, keys: ['COM_ALFA_NOTIFY_STOCK_DOCUMENT_MSG', 'COM_ALFA_NOTIFY_STOCK_DOCUMENT_REFUSED_MSG', 'COM_ALFA_NOTIFY_STOCK_DOCUMENT_RESERVED_MSG']),
            ] + $none;
        }

        $families = [
            InventoryEffectOutbox::NOTICE_STRANDED => [NotificationHelper::GROUP_INVENTORY, ['COM_ALFA_NOTIFY_STOCK_STRANDED_TITLE'], ['COM_ALFA_NOTIFY_STOCK_STRANDED_MSG']],
            'alfa.integrity' => [NotificationHelper::GROUP_SECURITY, ['COM_ALFA_NOTIFY_INTEGRITY_TITLE'], ['COM_ALFA_NOTIFY_INTEGRITY_MODIFIED', 'COM_ALFA_NOTIFY_INTEGRITY_AHEAD', 'COM_ALFA_NOTIFY_INTEGRITY_UNVERIFIED', 'COM_ALFA_NOTIFY_INTEGRITY_BADSIG']],
            'alfa.setup' => [NotificationHelper::GROUP_SETUP, ['COM_ALFA_NOTIFY_SETUP_TITLE'], ['COM_ALFA_NOTIFY_SETUP_MESSAGE']],
            ContentLanguageNotice::KEY => [NotificationHelper::GROUP_SETUP, ['COM_ALFA_NOTIFY_CONTENT_LANGUAGE_TITLE'], ['COM_ALFA_NOTIFY_CONTENT_LANGUAGE_MSG', 'COM_ALFA_NOTIFY_CONTENT_LANGUAGE_MSG_UNSET', 'COM_ALFA_NOTIFY_CONTENT_LANGUAGE_MSG_NONE']],
            CollationCheck::KEY => [NotificationHelper::GROUP_SETUP, ['COM_ALFA_NOTIFY_COLLATION_TITLE'], []],
            'alfa.tasks.attention' => [NotificationHelper::GROUP_ORDERS, ['COM_ALFA_NOTIFY_TASKS_ATTENTION_TITLE'], ['COM_ALFA_NOTIFY_TASKS_ATTENTION_MSG']],
            'alfa.maintenance.cleared' => [NotificationHelper::GROUP_GENERAL, ['COM_ALFA_CLEAR_NOTIFY_TITLE'], []],
        ];

        if (isset($families[$key])) {
            [$group, $titleKeys, $messageKeys] = $families[$key];

            return [
                'group' => $group,
                'title' => self::first(text: $title, keys: $titleKeys),
                'message' => $message === '' ? null : self::first(text: $message, keys: $messageKeys),
            ] + $none;
        }

        if ($key === 'setup.shipping_options_move') {
            return [
                'domain' => 'com_alfa.sys',
                'group' => NotificationHelper::GROUP_SHIPPING,
                'title' => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_SHIPPING_MOVE_FAILED_TITLE'], domain: 'com_alfa.sys'),
                'message' => self::first(text: $message, keys: ['COM_ALFA_NOTIFY_SHIPPING_MOVE_FAILED_MSG'], domain: 'com_alfa.sys'),
            ] + $none;
        }

        if (str_starts_with($key, ContentLanguageAddedNotice::KEY_PREFIX)) {
            return [
                'group' => NotificationHelper::GROUP_SETUP,
                'title' => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_TRANSLATIONS_TITLE']),
                'message' => self::first(text: $message, keys: ['COM_ALFA_NOTIFY_TRANSLATIONS_MSG']),
            ] + $none;
        }

        foreach (['stock-low:' => 'LOW_STOCK', 'backorder:' => 'BACKORDER', 'stock-integrity:item:' => 'STOCK_INTEGRITY'] as $prefix => $name) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '((item|combination):)?\d+$/', $key) === 1) {
                return [
                    'group' => NotificationHelper::GROUP_INVENTORY,
                    'title' => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_' . $name . '_TITLE']),
                    'message' => self::first(text: $message, keys: ['COM_ALFA_NOTIFY_' . $name . '_DESC']),
                ] + $none;
            }
        }

        if (str_starts_with($key, 'payments.')) {
            // The stored summaries stay literal; the titles take their keys.
            $title = match (true) {
                preg_match('/^payments\.refund\.unreconciled\.(\d+)$/', $key, $m) === 1 => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_PAYMENT_UNRECONCILED_TITLE', 'COM_ALFA_NOTIFY_REFUND_UNRECONCILED_TITLE']),
                preg_match('/^payments\.refund\.beyond\.(\d+)\.\d+$/', $key, $m) === 1 => $fixed('COM_ALFA_NOTIFY_REFUND_BEYOND_TITLE', [(int) $m[1]]),
                preg_match('/^payments\.unbooked\.(\d+)\.[0-9a-f]+$/', $key, $m) === 1 => $fixed('COM_ALFA_NOTIFY_REFUND_UNBOOKED_TITLE', [(int) $m[1]]),
                preg_match('/^payments\.refund\.reversed\.\d+$/', $key) === 1 => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_REFUND_REVERSED_TITLE']),
                preg_match('/^payments\.chargeback\.reversed\.\d+$/', $key) === 1 => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_CHARGEBACK_REVERSED_TITLE']),
                preg_match('/^payments\.chargeback\.\d+$/', $key) === 1 => self::first(text: $title, keys: ['COM_ALFA_NOTIFY_CHARGEBACK_TITLE']),
                default => false,
            };

            return $title === false ? $none : ['group' => NotificationHelper::GROUP_PAYMENTS, 'title' => $title] + $none;
        }

        return $none;
    }

    /**
     * The first key whose text, in one of the installed administrator languages, the stored text is: the key and the
     * parameters read from the text; null when none is.
     *
     * @param string $text The stored text.
     * @param list<string> $keys The keys, in order.
     * @param string $domain Their domain.
     *
     * @return array{0: string, 1: list<mixed>}|null
     *
     * @since  2.1.4
     */
    private static function first(string $text, array $keys, string $domain = NotificationText::DOMAIN): ?array
    {
        foreach ($keys as $key) {
            $values = self::parse(text: $text, key: $key, domain: $domain);

            if ($values !== null) {
                return [$key, $values];
            }
        }

        return null;
    }

    /**
     * The parameters a stored text was written with by a key, read by the key's own pattern in each installed
     * administrator language (sprintf placeholders, positional or not); null when no language's pattern is the text.
     *
     * @param string $text The stored text.
     * @param string $key The key.
     * @param string $domain Its domain.
     *
     * @return list<mixed>|null
     *
     * @since  2.1.4
     */
    private static function parse(string $text, string $key, string $domain = NotificationText::DOMAIN): ?array
    {
        if ($text === '') {
            return null;
        }

        foreach (self::languages(domain: $domain) as $language) {
            if (!$language->hasKey($key)) {
                continue;
            }

            $parts = preg_split('/%(?:(\d+)\$)?([sd])/', $language->_($key), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $regex = '';
            $types = [];
            $next = 1;

            for ($i = 0, $n = \count($parts); $i < $n; $i += 3) {
                $regex .= preg_quote((string) $parts[$i], '/');

                if (!isset($parts[$i + 2])) {
                    break;
                }

                $position = $parts[$i + 1] !== '' ? (int) $parts[$i + 1] : $next++;

                if (isset($types[$position])) {
                    $regex .= '\k<a' . $position . '>';

                    continue;
                }

                $types[$position] = $parts[$i + 2];
                $regex .= '(?<a' . $position . '>' . ($parts[$i + 2] === 'd' ? '-?\d+' : '.*?') . ')';
            }

            if (preg_match('/^' . $regex . '$/su', $text, $match) !== 1) {
                continue;
            }

            $values = [];

            for ($position = 1, $last = $types === [] ? 0 : max(array_keys($types)); $position <= $last; $position++) {
                $value = (string) ($match['a' . $position] ?? '');
                $values[] = ($types[$position] ?? 's') === 'd' ? (int) $value : $value;
            }

            return $values;
        }

        return null;
    }

    /**
     * The core group key whose text, in one of the installed administrator languages, a stored group is exactly; null
     * when none is.
     *
     * @param string $stored The stored group.
     *
     * @since  2.1.4
     */
    private static function coreGroup(string $stored): ?string
    {
        if (self::$groups === null) {
            self::$groups = [];
            $keys = array_filter(
                (new ReflectionClass(NotificationHelper::class))->getConstants(),
                static fn (mixed $value, string $name): bool => str_starts_with($name, 'GROUP_') && \is_string($value),
                ARRAY_FILTER_USE_BOTH,
            );

            foreach (self::languages(domain: NotificationText::DOMAIN) as $language) {
                foreach ($keys as $key) {
                    if ($language->hasKey($key)) {
                        self::$groups[$language->_($key)] ??= $key;
                    }
                }
            }
        }

        return $stored === '' ? null : (self::$groups[$stored] ?? null);
    }

    /**
     * The installed administrator languages with a domain's file loaded, English first.
     *
     * @param string $domain The domain.
     *
     * @return list<Language>
     *
     * @since  2.1.4
     */
    private static function languages(string $domain): array
    {
        try {
            $tags = array_keys(LanguageHelper::getKnownLanguages(JPATH_ADMINISTRATOR));
        } catch (Throwable) {
            $tags = [];
        }

        $languages = [];

        foreach (array_unique(['en-GB', ...$tags]) as $tag) {
            $name = $tag . '|' . $domain;

            if (!isset(self::$languages[$name])) {
                // An instance of its own, its own file and override only: Joomla's container factory shares one per tag.
                $language = NotificationText::own(tag: $tag);
                $language->load(extension: $domain, basePath: JPATH_ADMINISTRATOR, lang: $tag, reload: false, default: false);
                self::$languages[$name] = $language;
            }

            $languages[] = self::$languages[$name];
        }

        return $languages;
    }
}
