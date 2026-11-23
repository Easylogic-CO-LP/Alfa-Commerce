<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use InvalidArgumentException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\IpHelper;
use RuntimeException;
use Throwable;

/**
 * One row in Joomla's User Actions Log, written the way core's own rows are, and the answer whether it is there.
 *
 * The one place an Alfa Commerce extension writes Joomla's action log through: a payment or shipping plugin calls it
 * with recordActionLog() on its base class. The row is inserted directly rather than through
 * {@see \Joomla\Component\Actionlogs\Administrator\Model\ActionlogModel::addLog()}, which swallows a failed insert and
 * so cannot tell its caller that the record does not exist. The columns, the message's JSON shape and the IP rule
 * are Joomla's own, so the User Actions Log screen renders the row like any other, in the language of the extension
 * the row names.
 *
 * There is no update, and one delete only: a row written as pending (writePending()) may be withdrawn by the
 * extension that wrote it, in the same request, when the act it records did not stand (withdraw()). Every other row
 * is an audit trail that nothing in Alfa Commerce deletes.
 *
 * @since  2.2.1
 */
final class ActionLogHelper
{
    /**
     * The rows written as pending in this request, by id, with the extension that wrote each: the only rows
     * withdraw() may take back.
     *
     * @var array<int, string>
     *
     * @since  2.3.0
     */
    private static array $pending = [];

    /**
     * Write one row of Joomla's action log.
     *
     * @api ANSWERS: true when the row is written, false when not; every failure is caught, logged and answered false.
     *
     * @param string $extension The extension the row is filed under, whose language file holds $languageKey: a
     *                          component (com_x), or a plugin as plg_<group>_<element>.
     * @param string $languageKey The message's language key, as Joomla's action log renders it with the facts.
     * @param array<string, string|int|float|bool> $facts The sentence's facts, by name; scalar values only. The
     *                                                    acting user's userid, username and accountlink are applied
     *                                                    last: a fact of one of those names never replaces who acted.
     * @param int $itemId The id of the thing acted on (an order, a payment), 0 when none.
     * @param User $actor Who acted: the request's user, or a user of id 0 when none did (a provider's notification).
     *
     * @return bool True when the row is written; false when it is not (nothing written, the reason logged).
     *
     * @since  2.2.1
     */
    public static function write(string $extension, string $languageKey, array $facts, int $itemId, User $actor): bool
    {
        return self::insert(extension: $extension, languageKey: $languageKey, facts: $facts, itemId: $itemId, actor: $actor) !== null;
    }

    /**
     * Write one row of Joomla's action log for an act about to be made, and answer its id, so the extension can
     * withdraw it ({@see self::withdraw()}) if the act does not stand. Written as write() writes it; the row is an
     * ordinary row once the request ends.
     *
     * @api ANSWERS: the row's id when it is written, null when not; every failure is caught, logged and answered null.
     *
     * @param string $extension The extension the row is filed under (as write()).
     * @param string $languageKey The message's language key.
     * @param array<string, string|int|float|bool> $facts The sentence's facts, by name; scalar values only.
     * @param int $itemId The id of the thing acted on, 0 when none.
     * @param User $actor Who acted.
     *
     * @return int|null The row's id; null when it is not written (nothing written, the reason logged).
     *
     * @since  2.3.0
     */
    public static function writePending(string $extension, string $languageKey, array $facts, int $itemId, User $actor): ?int
    {
        $id = self::insert(extension: $extension, languageKey: $languageKey, facts: $facts, itemId: $itemId, actor: $actor);

        if ($id !== null) {
            self::$pending[$id] = $extension;
        }

        return $id;
    }

    /**
     * Take back a row the same extension wrote as pending in this request (writePending()), because the act it
     * records did not stand. Only that row: a row of another extension, a row written by write(), a row of an earlier
     * request, or one already withdrawn is refused, and nothing is deleted.
     *
     * @api ANSWERS: true when the row is gone; false when it is not this extension's pending row of this request, or
     *      the delete failed (logged); never throws.
     *
     * @param string $extension The extension that wrote it.
     * @param int $rowId The id writePending() answered.
     *
     * @return bool True when the row was deleted.
     *
     * @since  2.3.0
     */
    public static function withdraw(string $extension, int $rowId): bool
    {
        if (($pending = self::$pending[$rowId] ?? null) === null || $pending !== $extension) {
            return false;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__action_logs'))
                    ->where($db->quoteName('id') . ' = ' . $rowId)
                    ->where($db->quoteName('extension') . ' = ' . $db->quote($extension)),
            )->execute();

            if ($db->getAffectedRows() !== 1) {
                throw new RuntimeException('the row was not found to delete');
            }

            unset(self::$pending[$rowId]);

            return true;
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add('The action log row #' . $rowId . ' of ' . $extension . ' was not withdrawn: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, 'com_alfa.orders');

            return false;
        }
    }

    /**
     * Insert one row of Joomla's action log (write() and writePending()).
     *
     * @param string $extension The extension.
     * @param string $languageKey The language key.
     * @param array<string, string|int|float|bool> $facts The facts.
     * @param int $itemId The item.
     * @param User $actor Who acted.
     *
     * @return int|null The row's id, null when it is not written (the reason logged).
     *
     * @since  2.3.0
     */
    private static function insert(string $extension, string $languageKey, array $facts, int $itemId, User $actor): ?int
    {
        try {
            $refusal = self::refusal(extension: $extension, languageKey: $languageKey, facts: $facts);

            if ($refusal !== null) {
                throw new InvalidArgumentException($refusal);
            }

            // Who acted is the request's user, never a fact: the actor's three names are applied last.
            $message = array_replace($facts, [
                'userid' => (int) $actor->id,
                'username' => (string) $actor->username,
                'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . (int) $actor->id,
            ]);

            $row = (object) [
                'message_language_key' => $languageKey,
                'message' => json_encode($message, JSON_THROW_ON_ERROR),
                'log_date' => (string) Factory::getDate(),
                'extension' => $extension,
                'user_id' => (int) $actor->id,
                'ip_address' => self::ip(),
                'item_id' => $itemId,
            ];

            Factory::getContainer()->get(DatabaseInterface::class)->insertObject('#__action_logs', $row, 'id');

            if ((int) ($row->id ?? 0) > 0) {
                return (int) $row->id;
            }

            throw new RuntimeException('the database gave the row no id');
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add(
                'The action log row "' . $languageKey . '" of ' . $extension . ' was not written: ' . $e::class . ': ' . ErrorText::of($e),
                Log::ERROR,
                'com_alfa.orders',
            );

            return null;
        }
    }

    /**
     * Why a row cannot be written as asked, or null.
     *
     * @param string $extension The extension.
     * @param string $languageKey The language key.
     * @param array $facts The facts.
     *
     * @since  2.2.1
     */
    private static function refusal(string $extension, string $languageKey, array $facts): ?string
    {
        if (preg_match('/^[a-z0-9_.-]+$/', $extension) !== 1) {
            return 'the extension is an extension\'s name (a to z, 0 to 9, _ . -)';
        }

        if (preg_match('/^[A-Z0-9_]+$/', $languageKey) !== 1) {
            return 'the message is a language key (A to Z, 0 to 9 and _)';
        }

        foreach ($facts as $name => $value) {
            if (!\is_string($name) || $name === '') {
                return 'every fact is named';
            }

            if (!\is_scalar($value)) {
                return sprintf('the fact "%s" is %s, not a scalar value', $name, get_debug_type($value));
            }
        }

        return null;
    }

    /**
     * The caller's address under com_actionlogs' own ip_logging option, so a row never records more than the shop
     * asked Joomla to record.
     *
     * @since  2.2.1
     */
    private static function ip(): string
    {
        if (!ComponentHelper::getComponent('com_actionlogs')->getParams()->get('ip_logging', 0)) {
            return 'COM_ACTIONLOGS_DISABLED';
        }

        $ip = IpHelper::getIp();

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'COM_ACTIONLOGS_IP_INVALID';
    }
}
