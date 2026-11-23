<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Api;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

/**
 * A write sent again with the same Idempotency-Key is applied once (#__alfa_api_keys). The key is claimed inside the
 * write's own transaction with the request's hash, and the write's result is stored with it before the commit:
 *
 *     $replay = IdempotencyKeys::claim(userId: 42, scope: 'inventory.adjustments', key: $key, request: $body);
 *     // null: the first time, do the write, then IdempotencyKeys::complete(...) in the same transaction;
 *     // an array: the result the first request stored, answered again, nothing applied.
 *
 * The same key with another body is refused (422). A request whose key's first request is still running waits for it
 * on the key's row, then answers its result; when the wait runs out it is refused 409, to be sent again later. A write
 * that is refused rolls its transaction back, the key with it, so its retry runs afresh. Keys are kept 30 days, each
 * user's own.
 *
 * @since  2.3.0
 */
final class IdempotencyKeys
{
    /**
     * The keys.
     *
     * @since  2.3.0
     */
    public const TABLE = '#__alfa_api_keys';

    /**
     * How long a key is kept.
     *
     * @since  2.3.0
     */
    public const KEEP_DAYS = 30;

    /**
     * The longest key a client sends.
     *
     * @since  2.3.0
     */
    public const MAX_LENGTH = 255;

    /**
     * The seconds a request waits for its key's first request.
     *
     * @since  2.3.0
     */
    public const WAIT_SECONDS = 10;

    /**
     * Claim a key inside the write's transaction.
     *
     * @param int $userId The API user whose key it is.
     * @param string $scope The write (the route), so one key may name a write of each kind.
     * @param string $key The Idempotency-Key the client sent.
     * @param array $request The request's body, hashed in its canonical form.
     *
     * @return array|null Null the first time (the caller writes, then completes the key); the stored result when the
     *                    same request was applied before.
     *
     * @throws IdempotencyRefusedException When the key was used for another request (422), or its first request is
     *                                     still running after the wait (409).
     *
     * @since  2.3.0
     */
    public static function claim(int $userId, string $scope, string $key, array $request): ?array
    {
        $db = self::db();
        $hash = hash('sha256', $key);
        $requestHash = self::requestHash(request: $request);
        $now = Factory::getDate()->toSql();

        self::prune(db: $db);

        // The insert comes first: a key a running request holds makes it wait on the row, never on a gap lock two
        // requests could both hold.
        $wait = (int) $db->setQuery('SELECT @@SESSION.innodb_lock_wait_timeout')->loadResult();
        $db->setQuery('SET SESSION innodb_lock_wait_timeout = ' . self::WAIT_SECONDS)->execute();

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->insert($db->quoteName(self::TABLE))
                    ->columns($db->quoteName(['created_by', 'scope', 'key_hash', 'request_hash', 'created']))
                    ->values(':user, :scope, :key, :request, :created')
                    ->bind(':user', $userId, ParameterType::INTEGER)
                    ->bind(':scope', $scope)
                    ->bind(':key', $hash)
                    ->bind(':request', $requestHash)
                    ->bind(':created', $now),
            )->execute();

            return null;
        } catch (ExecutionFailureException $e) {
            if ($e->getCode() === 1205) {
                throw new IdempotencyRefusedException(new Refusal(
                    code: 'key_busy',
                    sentence: 'Idempotency-Key ' . self::shown(key: $key) . ' is in use by a request that is still running. Send it again in a few seconds.',
                    status: Refusal::CONFLICT,
                ));
            }

            if ($e->getCode() !== 1062) {
                throw $e;
            }
        } finally {
            $db->setQuery('SET SESSION innodb_lock_wait_timeout = ' . max(1, $wait))->execute();
        }

        // The key was claimed and its request committed: read past this transaction's snapshot.
        $row = $db->setQuery(
            'SELECT ' . $db->quoteName('request_hash') . ', ' . $db->quoteName('result') . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('created_by') . ' = ' . $userId . ' AND ' . $db->quoteName('scope') . ' = ' . $db->quote($scope)
            . ' AND ' . $db->quoteName('key_hash') . ' = ' . $db->quote($hash) . ' FOR UPDATE',
        )->loadObject();

        if ($row === null || (string) $row->request_hash !== $requestHash) {
            throw new IdempotencyRefusedException(new Refusal(
                code: 'key_reused',
                sentence: 'Idempotency-Key ' . self::shown(key: $key) . ' was used for another request. Nothing was changed.',
                status: Refusal::INVALID,
            ));
        }

        $result = json_decode((string) $row->result, true);

        return \is_array($result) ? $result : [];
    }

    /**
     * Store the write's result with its key, in the write's transaction, so a replay answers it.
     *
     * @param int $userId The API user.
     * @param string $scope The write.
     * @param string $key The Idempotency-Key.
     * @param array $result What the write answered: the ids and operation ids it wrote.
     *
     * @since  2.3.0
     */
    public static function complete(int $userId, string $scope, string $key, array $result): void
    {
        $db = self::db();
        $hash = hash('sha256', $key);
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('result') . ' = :result')
                ->where($db->quoteName('created_by') . ' = :user')
                ->where($db->quoteName('scope') . ' = :scope')
                ->where($db->quoteName('key_hash') . ' = :key')
                ->bind(':result', $json)
                ->bind(':user', $userId, ParameterType::INTEGER)
                ->bind(':scope', $scope)
                ->bind(':key', $hash),
        )->execute();
    }

    /**
     * Whether a client's key can be claimed: 1 to 255 printable ASCII characters.
     *
     * @param string $key The key.
     *
     * @since  2.3.0
     */
    public static function wellFormed(string $key): bool
    {
        return $key !== '' && \strlen($key) <= self::MAX_LENGTH && preg_match('/^[\x21-\x7E]+$/', $key) === 1;
    }

    /**
     * A request's hash: its body with every object's keys in order, so the same request sent with its keys in another
     * order is the same request.
     *
     * @param array $request The body.
     *
     * @since  2.3.0
     */
    public static function requestHash(array $request): string
    {
        return hash('sha256', json_encode(self::canonical(value: $request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * A key as a sentence names it: printable, at most 64 characters shown.
     *
     * @param string $key The key.
     *
     * @since  2.3.0
     */
    private static function shown(string $key): string
    {
        $key = preg_replace('/[^\x21-\x7E]/', '?', $key) ?? '';

        return htmlspecialchars(\strlen($key) > 64 ? substr($key, 0, 64) . '...' : $key, ENT_QUOTES, 'UTF-8');
    }

    /**
     * A value with every object's keys sorted, lists kept in their order.
     *
     * @param mixed $value The value.
     *
     * @since  2.3.0
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(static fn (mixed $item): mixed => self::canonical(value: $item), $value);
    }

    /**
     * Remove a batch of the keys older than the days they are kept.
     *
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.3.0
     */
    private static function prune(DatabaseInterface $db): void
    {
        $before = Factory::getDate('-' . self::KEEP_DAYS . ' days')->toSql();
        $db->setQuery(
            'DELETE FROM ' . $db->quoteName(self::TABLE) . ' WHERE ' . $db->quoteName('created') . ' < ' . $db->quote($before) . ' LIMIT 100',
        )->execute();
    }

    /**
     * The connection.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
