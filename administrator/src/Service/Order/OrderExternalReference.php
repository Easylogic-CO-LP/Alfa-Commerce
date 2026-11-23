<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The other system's id of an order: external_source names the system (a marketplace, an ERP, a connector) and
 * external_reference its id of the order there. Both are written together, when the order is created or once later,
 * and never changed; one pair names one order. A connector that sends the same order again after a timeout or a
 * lost answer is answered with the order the pair already names, never with a second order.
 *
 * The pair is unique in the database (UNIQUE KEY idx_external_reference on the two columns; a row with neither holds
 * no place in it). The create reads it first, in its own transaction before the order row is written, so the answer
 * names the order that holds it; a create made at the same moment with the same pair waits on the first one's row in
 * that key and is refused by the database once the first commits, and is answered the same way
 * ({@see self::takenBy()}).
 *
 * @api READ ONLY: the shape of an order's external reference (external_source a lower-case name of up to 32 characters,
 *      external_reference the other system's id, up to 64 characters) and the order a pair names.
 *
 * @since  2.3.0
 */
final class OrderExternalReference
{
    /**
     * external_source: lower-case letters, digits, underscore and hyphen, 1 to 32 characters, as an order's source.
     *
     * @since  2.3.0
     */
    public const SOURCE_PATTERN = '/^[a-z0-9_-]{1,32}$/';

    /**
     * The longest external_reference, in characters (the column's width).
     *
     * @since  2.3.0
     */
    public const REFERENCE_MAX = 64;

    /**
     * The pair a write's data carries, checked: null when it carries neither key (or both empty), else both, each in
     * its shape. One without the other is refused: the pair names an order only whole.
     *
     * @param array $data The write's data (external_source, external_reference).
     *
     * @return array{source: string, reference: string}|null
     *
     * @throws OrderPartRefusedException When one is sent without the other, or one is not in its shape (MALFORMED).
     *
     * @since  2.3.0
     */
    public static function read(array $data): ?array
    {
        $source = $data['external_source'] ?? null;
        $reference = $data['external_reference'] ?? null;

        if (($source === null || $source === '') && ($reference === null || $reference === '')) {
            return null;
        }

        if ($source === null || $source === '') {
            throw new OrderPartRefusedException('/external_source', 'send it with external_reference: the name of the system the external_reference belongs to, lower-case letters, digits, _ and -, up to 32 characters.', OrderPartRefusedException::MALFORMED);
        }

        if ($reference === null || $reference === '') {
            throw new OrderPartRefusedException('/external_reference', 'send it with external_source: the order\'s id in the system external_source names, up to ' . self::REFERENCE_MAX . ' characters.', OrderPartRefusedException::MALFORMED);
        }

        return ['source' => self::source(value: $source), 'reference' => self::reference(value: $reference)];
    }

    /**
     * external_source in its shape.
     *
     * @param mixed $value The value sent.
     *
     * @throws OrderPartRefusedException When it is not lower-case letters, digits, _ and -, 1 to 32 characters.
     *
     * @since  2.3.0
     */
    public static function source(mixed $value): string
    {
        if (!\is_string($value) || preg_match(self::SOURCE_PATTERN, $value) !== 1) {
            throw new OrderPartRefusedException('/external_source', 'must be lower-case letters, digits, _ and -, 1 to 32 characters (the name of the other system, for example base).', OrderPartRefusedException::MALFORMED);
        }

        return $value;
    }

    /**
     * external_reference in its shape: text of 1 to 64 characters with no control character and no space at either
     * end, compared exactly (letter case counts).
     *
     * @param mixed $value The value sent: text, or a whole number, which is read as its digits.
     *
     * @throws OrderPartRefusedException When it is not such a text.
     *
     * @since  2.3.0
     */
    public static function reference(mixed $value): string
    {
        $text = \is_int($value) ? (string) $value : $value;

        if (!\is_string($text) || $text === '' || mb_strlen($text, 'UTF-8') > self::REFERENCE_MAX || trim($text) !== $text
            || preg_match('/^[^\p{C}]+$/u', $text) !== 1) {
            throw new OrderPartRefusedException('/external_reference', 'must be text of 1 to ' . self::REFERENCE_MAX . ' characters, without a control character or a space at either end (the order\'s id in the other system).', OrderPartRefusedException::MALFORMED);
        }

        return $text;
    }

    /**
     * The order a pair names, or null.
     *
     * @param DatabaseInterface $db The database.
     * @param string $source external_source.
     * @param string $reference external_reference.
     * @param int $except An order not to count (the order a later write gives the pair to), 0 for none.
     *
     * @since  2.3.0
     */
    public static function orderWith(DatabaseInterface $db, string $source, string $reference, int $except = 0): ?int
    {
        $id = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('external_source') . ' = :source')
                ->where($db->quoteName('external_reference') . ' = :reference')
                ->where($db->quoteName('id') . ' <> :except')
                ->bind(':source', $source)
                ->bind(':reference', $reference)
                ->bind(':except', $except, ParameterType::INTEGER),
        )->loadResult();

        return $id === null ? null : (int) $id;
    }

    /**
     * The refusal of a write the database refused because the pair is already an order's (a duplicate key), with that
     * order; null for any other failure, which the caller raises as it is. Read after the write's transaction has
     * ended, so the order that holds the pair is committed and found.
     *
     * @param Throwable $failure What ended the write; the database's own failure may be wrapped in it.
     * @param array{source: string, reference: string} $pair The pair the write carried.
     *
     * @since  2.3.0
     */
    public static function takenBy(Throwable $failure, array $pair): ?OrderExternalReferenceTakenException
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if (MysqlError::number(error: $cause) === 1062) {
                $existing = self::orderWith(db: Factory::getContainer()->get(DatabaseInterface::class), source: $pair['source'], reference: $pair['reference']);

                return $existing === null ? null : new OrderExternalReferenceTakenException(existingId: $existing, pair: $pair);
            }
        }

        return null;
    }
}
