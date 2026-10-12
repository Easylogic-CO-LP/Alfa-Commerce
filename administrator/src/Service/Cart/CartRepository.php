<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use stdClass;
use Throwable;

/**
 * Every write to the cart tables, and the one read that decides whose they are.
 *
 * Cart storage used to be reached by hand from six places (the storefront
 * helper and four catalogue-admin paths), each with its own copy of the same
 * statement pair and its own idea of whether a transaction was involved. The
 * copies had drifted: one ran its deletes after its transaction had already
 * committed, another ran none at all, and the activity stamp existed in three
 * separate implementations. This class is the single owner.
 *
 * TWO DELETION SEMANTICS, KEPT APART ON PURPOSE.
 *
 *   REMOVING A LINE   takes the line and the field selections that describe it.
 *                     The shopper no longer has that product.
 *   REMOVING A
 *   SELECTION         takes the selection rows and LEAVES THE LINE STANDING.
 *                     The shopper still has the product; one of the choices
 *                     that configured it has been withdrawn by the merchant.
 *
 * These look similar in SQL and are not the same event. Collapsing them into a
 * single "delete cart rows" call would silently turn a merchant deleting one
 * field value into a shopper losing the whole product, so they are separate
 * methods and must stay separate.
 *
 * IT ALSO OWNS THE OWNER LOOKUP. Reads generally belong to the models, but
 * "which cart belongs to this shopper" is not a query, it is the identity rule
 * the writes depend on: adoption deletes a cart, and a lookup that disagreed
 * with the one that found it would delete the wrong one.
 *
 * NO REQUEST STATE. The constructor takes a database connection and nothing
 * else: no application, no session, no input, no user. That is what keeps
 * driver messages away from shoppers: this class cannot enqueue a message even
 * if it wanted to. It throws CartPersistenceException; deciding what a shopper
 * is told is the caller's job, one layer up.
 *
 * EVERY WRITE IS SAVEPOINT-AWARE. Order placement calls into these paths with a
 * transaction already open, so `transactionStart(true)` is used throughout: an
 * outer transaction gets a savepoint, a bare call gets a real transaction.
 *
 * @since  1.10.0
 */
class CartRepository
{
    /**
     * @var DatabaseInterface The connection every statement runs on.
     * @since  1.10.0
     */
    private DatabaseInterface $db;

    /**
     * @param DatabaseInterface|null $db The connection; the container's when omitted.
     *
     * @since  1.10.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    //  WRITING LINES: the line and the selections that describe it

    /**
     * Add a line and the selections that configure it.
     *
     * @param int $cartId The cart.
     * @param int $itemId The product.
     * @param int|null $combinationId The resolved combination, or null.
     * @param int $quantity The already-clamped quantity.
     * @param array $selectionRows fieldId => ['value_id' => ?int, 'payload' => ?string].
     *
     * @return int The new line id.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function insertLine(int $cartId, int $itemId, ?int $combinationId, int $quantity, array $selectionRows): int
    {
        $db = $this->db;

        try {
            $line = new stdClass();
            $line->id_cart = (int) $cartId;
            $line->id_item = (int) $itemId;
            $line->id_combination = $combinationId;
            $line->quantity = (int) $quantity;

            $db->insertObject('#__alfa_cart_items', $line, 'id');

            $this->writeSelections((int) $line->id, $selectionRows);

            return (int) $line->id;
        } catch (Throwable $e) {
            throw new CartPersistenceException('insertLine', $cartId, $e);
        }
    }

    /**
     * Re-configure a line in place: its combination, its quantity, and the whole
     * of its selection, replaced.
     *
     * The quantity is SET, not summed: this is the shopper editing what they
     * already chose, not adding more of it.
     *
     * NOT ATOMIC, deliberately unchanged. The statements run as they did before
     * they moved here: a failure between dropping the old selection and writing
     * the new one leaves a line with none. Wrapping it is a behaviour change and
     * belongs to whoever decides it, not to the move.
     *
     * @param int $lineId The line being re-configured.
     * @param int|null $combinationId The newly resolved combination, or null.
     * @param int $quantity The already-clamped quantity.
     * @param array $selectionRows fieldId => ['value_id' => ?int, 'payload' => ?string].
     *
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function replaceLineConfiguration(int $lineId, ?int $combinationId, int $quantity, array $selectionRows): void
    {
        $db = $this->db;

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_cart_items'))
                    ->set($db->quoteName('quantity') . ' = ' . (int) $quantity)
                    ->set(
                        $db->quoteName('id_combination') . ' = '
                        . ($combinationId === null ? 'NULL' : (int) $combinationId),
                    )
                    ->where($db->quoteName('id') . ' = ' . (int) $lineId),
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_item_fields'))
                    ->where($db->quoteName('id_cart_item') . ' = ' . (int) $lineId),
            )->execute();

            $this->writeSelections($lineId, $selectionRows);
        } catch (Throwable $e) {
            throw new CartPersistenceException('replaceLineConfiguration', 0, $e);
        }
    }

    /**
     * Set or clear a line's staff-negotiated unit price.
     *
     * @param int $lineId The line.
     * @param float|null $amount The negotiated unit price, tax included; null clears it.
     * @param int $cartId The owning cart; 0 to skip the scope.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.11.0
     */
    public function setLineCustomPriceValue(int $lineId, ?float $amount, int $cartId = 0): void
    {
        $db = $this->db;

        try {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__alfa_cart_items'))
                ->where($db->quoteName('id') . ' = ' . (int) $lineId);

            // SQL NULL, never PHP 0, and never the reverse. Writing 0 for a
            // clear would price the line free; writing NULL for an explicit
            // 0.00 would return it to catalogue pricing. They are the two
            // halves of "zero is money, never a control sentinel", and this
            // ternary is the one place they are told apart.
            if ($amount === null) {
                $query->set($db->quoteName('custom_unit_price_tax_incl') . ' = NULL');
            } else {
                // The canonical rate's exact decimal, never the float's own print.
                $query->set($db->quoteName('custom_unit_price_tax_incl') . ' = ' . $db->quote(StoredDecimal::of(ExactAmount::of(ExplicitPriceInstruction::canonicalRate(value: $amount)))));
            }

            if ($cartId > 0) {
                $query->where($db->quoteName('id_cart') . ' = ' . (int) $cartId);
            }

            $db->setQuery($query)->execute();
        } catch (Throwable $e) {
            throw new CartPersistenceException('setLineCustomPriceValue', $cartId, $e);
        }
    }

    /**
     * A line's stored custom price as it stands, before a write changes it.
     *
     * Read separately from loadLine() because the audit needs the PREVIOUS
     * state and the writer needs it inside the same transaction as the write;
     * the audit asks for both states, and reconstructing the old one afterwards is
     * exactly what cannot be done.
     *
     * @param int $lineId The line.
     * @param int $cartId The owning cart; 0 to skip the scope.
     *
     * @return float|null The stored amount, or null when the line carries no override.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.11.0
     */
    public function loadLineCustomPrice(int $lineId, int $cartId = 0): ?float
    {
        $db = $this->db;

        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName('custom_unit_price_tax_incl'))
                ->from($db->quoteName('#__alfa_cart_items'))
                ->where($db->quoteName('id') . ' = ' . (int) $lineId);

            if ($cartId > 0) {
                $query->where($db->quoteName('id_cart') . ' = ' . (int) $cartId);
            }

            $stored = $db->setQuery($query)->loadResult();

            return $stored === null ? null : (float) $stored;
        } catch (Throwable $e) {
            throw new CartPersistenceException('loadLineCustomPrice', $cartId, $e);
        }
    }

    /**
     * Set a line's quantity. The caller has already decided what is allowed.
     *
     * @param int $lineId The line.
     * @param int $quantity The already-clamped quantity.
     * @param int $cartId The owning cart, when the caller has it; 0 to skip
     *                    the scope. Passing it is always better: it is what
     *                    stops a line id from another cart being written.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function setLineQuantityValue(int $lineId, int $quantity, int $cartId = 0): void
    {
        $db = $this->db;

        try {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__alfa_cart_items'))
                ->set($db->quoteName('quantity') . ' = ' . (int) $quantity)
                ->where($db->quoteName('id') . ' = ' . (int) $lineId);

            if ($cartId > 0) {
                $query->where($db->quoteName('id_cart') . ' = ' . (int) $cartId);
            }

            $db->setQuery($query)->execute();
        } catch (Throwable $e) {
            throw new CartPersistenceException('setLineQuantityValue', $cartId, $e);
        }
    }

    /**
     * What a line currently holds.
     *
     * @param int $cartId The cart that must own it.
     * @param int $lineId The line.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function lineQuantity(int $cartId, int $lineId): int
    {
        $db = $this->db;

        try {
            return (int) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('quantity'))
                    ->from($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id') . ' = ' . (int) $lineId)
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $cartId),
                0,
                1,
            )->loadResult();
        } catch (Throwable $e) {
            throw new CartPersistenceException('lineQuantity', $cartId, $e);
        }
    }

    //  Codes the cart holds

    /**
     * Hold a code against the cart.
     *
     * A code the cart already holds is NOT a failure: two tabs entering the same
     * code is an ordinary race, the row the shopper wanted exists, and the
     * primary key did its job. It reports false rather than throwing.
     *
     * A DUPLICATE KEY IS MYSQL ERROR 1062, read by MysqlError::number(): mysqli
     * hands the number as the code, the PDO driver hands the SQLSTATE there
     * (23000 for every integrity violation) and the number in its message.
     *
     * @param int $cartId The cart.
     * @param string $code The code, already normalised by the caller.
     *
     * @return bool True when this call added it; false when it was already held.
     *
     * @throws CartPersistenceException When the database refuses for any other reason.
     *
     * @since  1.10.0
     */
    public function holdCoupon(int $cartId, string $code): bool
    {
        $db = $this->db;

        try {
            $row = new stdClass();
            $row->id_cart = (int) $cartId;
            $row->code = $code;

            $db->insertObject('#__alfa_cart_coupons', $row);

            return true;
        } catch (Throwable $e) {
            if (MysqlError::number($e) === 1062) {
                return false;
            }

            throw new CartPersistenceException('holdCoupon', $cartId, $e);
        }
    }

    /**
     * Drop a code the cart holds.
     *
     * @param int $cartId The cart.
     * @param string $code The code, already normalised by the caller.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function dropCoupon(int $cartId, string $code): void
    {
        $db = $this->db;

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_coupons'))
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $cartId)
                    ->where($db->quoteName('code') . ' = ' . $db->quote($code)),
            )->execute();
        } catch (Throwable $e) {
            throw new CartPersistenceException('dropCoupon', $cartId, $e);
        }
    }

    //  REMOVING LINES: the line and the selections that describe it

    /**
     * Remove one line from one cart.
     *
     * Scoped by the OWNING CART on both statements. The version this replaces
     * scoped only the line delete, so a line id belonging to another cart had
     * its selection rows removed while the line itself survived; the caller
     * checked ownership separately, which made the guarantee a property of the
     * caller rather than of the write.
     *
     * @param int $cartId The cart that must own the line.
     * @param int $lineId The line to remove.
     *
     * @return bool True when the line existed and was removed.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function removeLine(int $cartId, int $lineId): bool
    {
        if ($cartId <= 0 || $lineId <= 0) {
            return false;
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            // The selection rows of this line, reached through the line so the
            // cart scope applies to them too.
            $db->setQuery(
                'DELETE f FROM ' . $db->quoteName('#__alfa_cart_item_fields', 'f')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_items', 'ci')
                . ' ON ' . $db->quoteName('ci.id') . ' = ' . $db->quoteName('f.id_cart_item')
                . ' WHERE ' . $db->quoteName('ci.id') . ' = ' . (int) $lineId
                . ' AND ' . $db->quoteName('ci.id_cart') . ' = ' . (int) $cartId,
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id') . ' = ' . (int) $lineId)
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $cartId),
            )->execute();

            $removed = (int) $db->getAffectedRows() > 0;

            $db->transactionCommit(true);

            return $removed;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new CartPersistenceException('removeLine', $cartId, $e);
        }
    }

    /**
     * Empty a cart: its lines, their selections and the codes it holds. The
     * cart row itself survives.
     *
     * The deletes must agree, so they share one transaction. The selection
     * delete is a JOIN driven from the cart's own lines rather than an
     * `IN (SELECT ...)`, which keeps the same predicate as the line delete below
     * it: reading the line ids first and deleting by id would open a gap for a
     * line inserted in between, whose selection rows would then survive the
     * very clear this method exists to make complete.
     *
     * The held codes go too. The cart row survives, so the coupon table's
     * foreign key does not fire and the delete has to be explicit.
     *
     * @param int $cartId The cart to empty.
     *
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function emptyCart(int $cartId): void
    {
        if ($cartId <= 0) {
            return;
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            $db->setQuery(
                'DELETE f FROM ' . $db->quoteName('#__alfa_cart_item_fields', 'f')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_items', 'ci')
                . ' ON ' . $db->quoteName('ci.id') . ' = ' . $db->quoteName('f.id_cart_item')
                . ' WHERE ' . $db->quoteName('ci.id_cart') . ' = ' . (int) $cartId,
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $cartId),
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_coupons'))
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $cartId),
            )->execute();

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new CartPersistenceException('emptyCart', $cartId, $e);
        }
    }

    /**
     * Remove the cart row itself.
     *
     * Call after emptyCart(). The coupon rows would go with it either way (the
     * foreign key cascades), but the lines and their selections would not, so
     * the order is not optional.
     *
     * @param int $cartId The cart to delete.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function deleteCart(int $cartId): void
    {
        // NOT SELF-SUFFICIENT, and its one caller knows it: discard() empties
        // the cart first, so by the time this runs there is only the row left.
        // Anything that deletes a cart WITHOUT emptying it first must use
        // purgeCart() instead, or it leaves the lines and their selections
        // behind with nothing pointing at them.
        if ($cartId <= 0) {
            return;
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart'))
                    ->where($db->quoteName('id') . ' = ' . (int) $cartId),
            )->execute();

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new CartPersistenceException('deleteCart', $cartId, $e);
        }
    }

    /**
     * Remove every live cart line holding any of these catalogue items, and
     * stamp the carts that lost one.
     *
     * Called when the merchant deletes a product. The carts have to be read
     * before the lines are deleted (afterwards there is nothing left to join
     * back through), and the whole thing is one transaction, which the
     * catalogue-side copy of this code did not manage: it ran its deletes after
     * its own transaction had already committed.
     *
     * @param int[] $itemIds The catalogue items being removed.
     *
     * @return int[] The carts that lost a line.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function removeLinesForItems(array $itemIds): array
    {
        return $this->removeLinesMatching('id_item', $itemIds, 'removeLinesForItems');
    }

    /**
     * Remove every live cart line resolved to any of these variants, and stamp
     * the carts that lost one.
     *
     * Called when the merchant deletes a combination. A line whose variant no
     * longer exists cannot be priced or fulfilled, so the line goes rather than
     * the reference being blanked.
     *
     * @param int[] $combinationIds The variants being removed.
     *
     * @return int[] The carts that lost a line.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function removeLinesForCombinations(array $combinationIds): array
    {
        return $this->removeLinesMatching('id_combination', $combinationIds, 'removeLinesForCombinations');
    }

    //  REMOVING SELECTIONS: the line survives

    /**
     * Remove the selection rows that point at these field VALUES, leaving the
     * lines standing, and stamp the carts affected.
     *
     * A merchant withdrawing one option value has not withdrawn the product.
     * The shopper keeps the line; what changes is that the line is now
     * configured differently from the way they configured it.
     *
     * @param int[] $valueIds The field values being removed.
     *
     * @return int[] The carts whose lines lost a selection.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function removeSelectionsForFieldValues(array $valueIds): array
    {
        return $this->removeSelectionsMatching('id_item_field_value', $valueIds, 'removeSelectionsForFieldValues');
    }

    /**
     * Remove the selection rows that point at these FIELDS, leaving the lines
     * standing, and stamp the carts affected.
     *
     * @param int[] $fieldIds The item fields being removed.
     *
     * @return int[] The carts whose lines lost a selection.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function removeSelectionsForFields(array $fieldIds): array
    {
        return $this->removeSelectionsMatching('id_item_field', $fieldIds, 'removeSelectionsForFields');
    }

    //  REMEDIATION: rows that outlived what they described

    /**
     * Remove selection rows whose line no longer exists.
     *
     * A SELECTION ROW CARRIES NO CART ID. It says only which LINE it configured,
     * so once that line is gone the row is not merely unused; it is unreachable:
     * nothing can join to it, no screen can render it, and no code can find it
     * except by looking for exactly this absence.
     *
     * THE ABSENCE IS RE-ASSERTED IN THE STATEMENT. The caller passes ids it
     * measured a moment ago, and this deletes only those that are STILL
     * orphaned. A row that somehow gained a line in between is left alone, which
     * makes the operation safe to repeat and safe to run against a live shop.
     *
     * Ids only, never a bare "delete everything orphaned": a remediation that
     * cannot say in advance exactly which rows it will take is not a plan.
     *
     * @param int[] $selectionIds The rows to consider.
     *
     * @return int How many were actually removed.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function deleteOrphanSelections(array $selectionIds): int
    {
        $selectionIds = $this->ids($selectionIds);

        if ($selectionIds === []) {
            return 0;
        }

        $db = $this->db;

        try {
            $db->setQuery(
                'DELETE f FROM ' . $db->quoteName('#__alfa_cart_item_fields', 'f')
                . ' WHERE ' . $db->quoteName('f.id') . ' IN (' . implode(',', $selectionIds) . ')'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_cart_items', 'i')
                . ' WHERE ' . $db->quoteName('i.id') . ' = ' . $db->quoteName('f.id_cart_item') . ')',
            )->execute();

            return (int) $db->getAffectedRows();
        } catch (Throwable $e) {
            throw new CartPersistenceException('deleteOrphanSelections', 0, $e);
        }
    }

    /**
     * Selection rows with no line, oldest first.
     *
     * @param int $limit The most to return.
     *
     * @return object[] id, id_cart_item, id_item_field, id_item_field_value, payload.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function findOrphanSelections(int $limit = 1000): array
    {
        $db = $this->db;

        try {
            return $db->setQuery(
                'SELECT ' . $db->quoteName('f.id') . ', ' . $db->quoteName('f.id_cart_item')
                . ', ' . $db->quoteName('f.id_item_field') . ', ' . $db->quoteName('f.id_item_field_value')
                . ', ' . $db->quoteName('f.payload')
                . ' FROM ' . $db->quoteName('#__alfa_cart_item_fields', 'f')
                . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_cart_items', 'i')
                . ' WHERE ' . $db->quoteName('i.id') . ' = ' . $db->quoteName('f.id_cart_item') . ')'
                . ' ORDER BY ' . $db->quoteName('f.id') . ' ASC',
                0,
                $limit,
            )->loadObjectList() ?: [];
        } catch (Throwable $e) {
            throw new CartPersistenceException('findOrphanSelections', 0, $e);
        }
    }

    /**
     * The next line id the table will hand out.
     *
     * The whole safety argument for deleting an orphan rests on this: InnoDB
     * never hands back an id it has issued, so a selection pointing at a line id
     * BELOW this number can never be re-attached to anything. One at or above it
     * could be, and must not be touched.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function nextLineId(): int
    {
        $db = $this->db;

        try {
            return (int) $db->setQuery(
                'SELECT AUTO_INCREMENT FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
                . $db->quote($db->getPrefix() . 'alfa_cart_items'),
            )->loadResult();
        } catch (Throwable $e) {
            throw new CartPersistenceException('nextLineId', 0, $e);
        }
    }

    //  RETENTION: the end of a cart's life

    /**
     * The carts whose silence has outlasted their retention window.
     *
     * TWO WINDOWS IN ONE PASS, because an empty cart and a cart with lines are
     * kept for different reasons and different lengths of time. Ordered by id
     * and bounded by a limit, so a run is deterministic and a big table is
     * worked through in bites rather than in one statement holding locks over
     * everything.
     *
     * @param string $emptyBefore Empty carts untouched since this are expired.
     * @param string $linesBefore Carts with lines untouched since this are expired.
     * @param int $limit The most ids to return.
     *
     * @return int[] Expired cart ids, ascending.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function findExpiredCartIds(string $emptyBefore, string $linesBefore, int $limit = 200): array
    {
        if ($limit <= 0) {
            return [];
        }

        $db = $this->db;
        $hasLines = CartActivity::hasLines($db);

        try {
            return $this->ids($db->setQuery(
                'SELECT ' . $db->quoteName('a.id')
                . ' FROM ' . $db->quoteName('#__alfa_cart', 'a')
                . ' WHERE ((NOT ' . $hasLines . ' AND ' . $db->quoteName('a.updated') . ' < ' . $db->quote($emptyBefore) . ')'
                . ' OR (' . $hasLines . ' AND ' . $db->quoteName('a.updated') . ' < ' . $db->quote($linesBefore) . '))'
                . ' ORDER BY ' . $db->quoteName('a.id') . ' ASC',
                0,
                $limit,
            )->loadColumn() ?: []);
        } catch (Throwable $e) {
            throw new CartPersistenceException('findExpiredCartIds', 0, $e);
        }
    }

    /**
     * Remove a cart and everything that belongs to it, but only while it is
     * still quiet.
     *
     * THE CONDITION IS THE POINT. A purge picks its victims, and then time
     * passes: the shopper whose cart was chosen a moment ago may be adding to it
     * right now. The cart row is deleted with the same silence test that
     * selected it, so a cart touched in between survives, and the whole
     * operation reports that it did nothing rather than pretending it worked.
     *
     * ORDER MATTERS AND IS THE OPPOSITE OF discard(). The cart row goes FIRST,
     * because that is what the condition is about and what the coupon foreign
     * key cascades from; the lines and their selections follow inside the same
     * transaction, where they are still reachable.
     *
     * @param int $cartId The cart.
     * @param string $quietSince The cart must not have been touched since this.
     *
     * @return bool True when the cart was removed; false when it had been
     *              touched, or was already gone.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function purgeCart(int $cartId, string $quietSince): bool
    {
        if ($cartId <= 0) {
            return false;
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart'))
                    ->where($db->quoteName('id') . ' = ' . (int) $cartId)
                    ->where($db->quoteName('updated') . ' < ' . $db->quote($quietSince)),
            )->execute();

            if ((int) $db->getAffectedRows() === 0) {
                // Touched since it was chosen, or already gone. Either way this
                // is not our cart to take.
                $db->transactionRollback(true);

                return false;
            }

            $db->setQuery(
                'DELETE f FROM ' . $db->quoteName('#__alfa_cart_item_fields', 'f')
                . ' INNER JOIN ' . $db->quoteName('#__alfa_cart_items', 'ci')
                . ' ON ' . $db->quoteName('ci.id') . ' = ' . $db->quoteName('f.id_cart_item')
                . ' WHERE ' . $db->quoteName('ci.id_cart') . ' = ' . (int) $cartId,
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $cartId),
            )->execute();

            // The codes went with the cart row: #__alfa_cart_coupons carries the
            // only foreign key on these tables, ON DELETE CASCADE.
            $db->transactionCommit(true);

            return true;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new CartPersistenceException('purgeCart', $cartId, $e);
        }
    }

    //  Finding a cart by its owner

    /**
     * The cart an owner is holding, or null.
     *
     * ONE PLACE. This predicate used to be written out three times (the cart
     * lookup and both halves of adoption), each with a comment saying it had to
     * agree with the others. Agreement by comment is not agreement; the merge
     * step deletes a cart, so a lookup that disagreed by one clause would have
     * deleted the wrong one.
     *
     * @param CartOwner $owner The shopper.
     *
     * @return object|null The cart row.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function loadCartFor(CartOwner $owner): ?object
    {
        if (!$owner->isIdentified()) {
            return null;
        }

        $db = $this->db;

        try {
            return $db->setQuery(
                $this->ownerQuery($owner)->select('*'),
                0,
                1,
            )->loadObject();
        } catch (Throwable $e) {
            throw new CartPersistenceException('loadCartFor', 0, $e);
        }
    }

    /**
     * The id of the cart an owner is holding, or 0.
     *
     * @param int $excludeCartId A cart to skip: the row being adopted, which a
     *                           concurrent request may already have claimed.
     *                           Seeing it here as "their own cart" would make
     *                           the merge delete the cart just handed over.
     *
     * @param CartOwner $owner The shopper.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function findCartIdFor(CartOwner $owner, int $excludeCartId = 0): int
    {
        if (!$owner->isIdentified()) {
            return 0;
        }

        $db = $this->db;

        try {
            $query = $this->ownerQuery($owner)->select($db->quoteName('id'));

            if ($excludeCartId > 0) {
                $query->where($db->quoteName('id') . ' <> ' . (int) $excludeCartId);
            }

            return (int) $db->setQuery($query, 0, 1)->loadResult();
        } catch (Throwable $e) {
            throw new CartPersistenceException('findCartIdFor', 0, $e);
        }
    }

    /**
     * One cart row by id.
     *
     * @param int $cartId The cart.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function loadCart(int $cartId): ?object
    {
        if ($cartId <= 0) {
            return null;
        }

        $db = $this->db;

        try {
            return $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__alfa_cart'))
                    ->where($db->quoteName('id') . ' = ' . (int) $cartId),
                0,
                1,
            )->loadObject();
        } catch (Throwable $e) {
            throw new CartPersistenceException('loadCart', $cartId, $e);
        }
    }

    /**
     * The owner predicate, with the tie-break that makes it deterministic.
     *
     * A visitor reaches UNOWNED carts only (`id_customer = 0` alongside the
     * key), because the cookie outlives the session and the next person at a
     * shared machine must not open the last one's cart.
     *
     * ORDERED BY ID. One cart per shopper is the rule, but a race can leave
     * two; ascending id means every path picks the same one, which is what lets
     * adoption merge INTO the cart the visitor was actually shown.
     *
     * @param CartOwner $owner The shopper.
     *
     *
     * @since  1.10.0
     */
    private function ownerQuery(CartOwner $owner): \Joomla\Database\QueryInterface
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->from($db->quoteName('#__alfa_cart'))
            ->order($db->quoteName('id') . ' ASC');

        if ($owner->isMember()) {
            return $query->where($db->quoteName('id_customer') . ' = ' . (int) $owner->customerId());
        }

        return $query->where($db->quoteName('recognize_key') . ' = ' . $db->quote($owner->recognizeKey()))
            ->where($db->quoteName('id_customer') . ' = 0');
    }

    //  THE CART ROW: its creation and its own columns

    /**
     * Create the cart row.
     *
     * The caller composes the row because deciding a new cart's currency,
     * owner and recognition key is a domain question, not a storage one. What
     * belongs here is only the statement and the identity it returns.
     *
     * @param object $cart The row to write; receives the new id.
     *
     * @return int The new cart id.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function insertCart(object $cart): int
    {
        $db = $this->db;

        try {
            $db->insertObject('#__alfa_cart', $cart, 'id');

            return (int) $db->insertid();
        } catch (Throwable $e) {
            throw new CartPersistenceException('insertCart', 0, $e);
        }
    }

    /**
     * Point the cart at the customer-details records the checkout resolved.
     *
     * These two columns existed from the first schema and were never written:
     * created as `0` and only ever assigned on the in-memory object at
     * placement, so the row said every cart had no address. Persisting them is
     * what makes the delivery-place fallback reachable instead of dead code and
     * what lets the backend read a cart's identity from the row.
     *
     * A null invoice pointer keeps whatever the column already holds: the two
     * pickers post together and only one of them may have been used.
     *
     * NO RETURN VALUE ON PURPOSE. Affected rows would be a misleading signal:
     * re-picking the address the cart already points at changes nothing, and
     * that is a success, not a miss.
     *
     * @param int $cartId The cart to point.
     * @param int $deliveryId The delivery details record.
     * @param int|null $invoiceId The invoice record; null keeps the current one.
     *
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function setUserInfoPointers(int $cartId, int $deliveryId, ?int $invoiceId = null): void
    {
        if ($cartId <= 0 || $deliveryId <= 0) {
            return;
        }

        $db = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_cart'))
            ->set($db->quoteName('id_user_info_delivery') . ' = ' . (int) $deliveryId)
            ->where($db->quoteName('id') . ' = ' . (int) $cartId);

        if ($invoiceId !== null && $invoiceId > 0) {
            $query->set($db->quoteName('id_user_info_invoice') . ' = ' . (int) $invoiceId);
        }

        try {
            $db->setQuery($query)->execute();
        } catch (Throwable $e) {
            throw new CartPersistenceException('setUserInfoPointers', $cartId, $e);
        }
    }

    /**
     * Record the shipment method the shopper chose.
     *
     * Choosing a method is activity, so the stamp rides along on the write
     * that is already going to the row rather than costing a second statement.
     *
     * @param int $cartId The cart.
     * @param int $shipmentId The chosen method.
     * @param string|null $at The instant to stamp; now() when omitted.
     *
     * @return bool True when the statement ran.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function setShipmentMethod(int $cartId, int $shipmentId, ?string $at = null): bool
    {
        return $this->setMethodColumn($cartId, 'id_shipment', $shipmentId, 'setShipmentMethod', $at);
    }

    /**
     * Record the payment method the shopper chose.
     *
     * @param int $cartId The cart.
     * @param int $paymentId The chosen method.
     *
     * @return bool True when the statement ran.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function setPaymentMethod(int $cartId, int $paymentId, ?string $at = null): bool
    {
        return $this->setMethodColumn($cartId, 'id_payment', $paymentId, 'setPaymentMethod', $at);
    }

    /**
     * Move carts off currencies that no longer exist.
     *
     * `updated` is deliberately NOT stamped. Nothing about the cart's contents
     * changed and this is not the shopper's activity; stamping would reset the
     * abandonment clock of every affected cart for a maintenance write.
     *
     * @param int[] $fromCurrencyIds The currencies being retired.
     * @param int $toCurrencyId The currency to fall back to.
     *
     * @return int The number of carts repointed.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function repointCurrency(array $fromCurrencyIds, int $toCurrencyId): int
    {
        $fromCurrencyIds = $this->ids($fromCurrencyIds);

        if ($fromCurrencyIds === [] || $toCurrencyId <= 0 || \in_array($toCurrencyId, $fromCurrencyIds, true)) {
            return 0;
        }

        $db = $this->db;

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_cart'))
                    ->set($db->quoteName('id_currency') . ' = ' . (int) $toCurrencyId)
                    ->whereIn($db->quoteName('id_currency'), $fromCurrencyIds),
            )->execute();

            return (int) $db->getAffectedRows();
        } catch (Throwable $e) {
            throw new CartPersistenceException('repointCurrency', 0, $e);
        }
    }

    //  Ownership

    /**
     * Hand an unowned cart to a member.
     *
     * Conditional on the cart still being unowned, so the loser of a race
     * updates nothing rather than taking a cart twice. The row survives with
     * its id, which is why the codes and lines attached to it need no merging
     * on this path.
     *
     * @param int $cartId The cart to claim.
     * @param int $userId The member claiming it.
     *
     * @return bool True when this call is the one that claimed it.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function claimCart(int $cartId, int $userId): bool
    {
        if ($cartId <= 0 || $userId <= 0) {
            return false;
        }

        $db = $this->db;

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_cart'))
                    ->set($db->quoteName('id_customer') . ' = ' . (int) $userId)
                    ->where($db->quoteName('id') . ' = ' . (int) $cartId)
                    ->where($db->quoteName('id_customer') . ' = 0'),
            )->execute();

            return (int) $db->getAffectedRows() > 0;
        } catch (Throwable $e) {
            throw new CartPersistenceException('claimCart', $cartId, $e);
        }
    }

    /**
     * Move a guest cart's contents into a member's own cart and delete the
     * guest row.
     *
     * Order matters and is the whole point of doing this in one place. The
     * codes move BEFORE the guest row is deleted, because deleting it fires the
     * coupon table's cascade: a code the visitor typed before signing in would
     * otherwise be destroyed by the operation that exists to stop them losing
     * their cart. `UPDATE IGNORE` handles the case where the member's cart
     * already holds the same code: the primary key decides, and the loser is
     * dropped a statement later by the cascade.
     *
     * Lines keep their identity, so their selection rows follow them without
     * being touched.
     *
     * @param int $guestCartId The cart being absorbed.
     * @param int $ownCartId The member's surviving cart.
     *
     * @return object[] The lines that moved, as they were before the move.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    public function mergeCartInto(int $guestCartId, int $ownCartId): array
    {
        if ($guestCartId <= 0 || $ownCartId <= 0 || $guestCartId === $ownCartId) {
            return [];
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            $moved = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'id_item', 'id_combination', 'quantity']))
                    ->from($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $guestCartId),
            )->loadObjectList() ?: [];

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_cart_items'))
                    ->set($db->quoteName('id_cart') . ' = ' . (int) $ownCartId)
                    ->where($db->quoteName('id_cart') . ' = ' . (int) $guestCartId),
            )->execute();

            $db->setQuery(
                'UPDATE IGNORE ' . $db->quoteName('#__alfa_cart_coupons')
                . ' SET ' . $db->quoteName('id_cart') . ' = ' . (int) $ownCartId
                . ' WHERE ' . $db->quoteName('id_cart') . ' = ' . (int) $guestCartId,
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart'))
                    ->where($db->quoteName('id') . ' = ' . (int) $guestCartId),
            )->execute();

            $db->transactionCommit(true);

            return $moved;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new CartPersistenceException('mergeCartInto', $guestCartId, $e);
        }
    }

    //  Activity

    /**
     * Stamp one cart's activity clock.
     *
     * A bare UPDATE rather than a table store: reads must never write, and the
     * mini-cart renders on every page.
     *
     * @param int $cartId The cart to stamp.
     * @param string|null $at The instant to record; now() when omitted.
     *
     * @return bool True when the stamp was written.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function touch(int $cartId, ?string $at = null): bool
    {
        return $this->stampCarts([$cartId], $at) > 0;
    }

    /**
     * Stamp the activity clock of several carts at once.
     *
     * `updated` is the abandonment clock, the column the Carts screen sorts and
     * filters on, and the predicate the retention policy will read. A cart
     * changed by any path must say so, or it drifts into "abandoned" with no
     * activity behind it.
     *
     * The instant may be supplied by the caller. A caller that also mirrors the
     * stamp onto an in-memory row needs the value it stored, and reading the
     * clock twice can straddle a second boundary, so the clock is read once
     * and passed in rather than read again here.
     *
     * @param int[] $cartIds The carts to stamp.
     * @param string|null $at The instant to record; now() when omitted.
     *
     * @return int The number of carts stamped.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    public function stampCarts(array $cartIds, ?string $at = null): int
    {
        $cartIds = $this->ids($cartIds);

        if ($cartIds === []) {
            return 0;
        }

        $db = $this->db;

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_cart'))
                    ->set($db->quoteName('updated') . ' = ' . $db->quote($at ?? $this->now()))
                    ->whereIn($db->quoteName('id'), $cartIds),
            )->execute();

            return (int) $db->getAffectedRows();
        } catch (Throwable $e) {
            throw new CartPersistenceException('stampCarts', \count($cartIds) === 1 ? $cartIds[0] : 0, $e);
        }
    }

    /**
     * The cart's line rows, newest first.
     *
     * Newest first is not decoration: hydration consumes them in this order, and
     * a cart holding two lines of the same item reads differently reversed.
     *
     * @param int $cartId The cart to read.
     *
     * @return object[] id, id_item, id_combination, quantity.
     *
     * @throws CartPersistenceException When the read fails.
     *
     * @since  1.10.0
     */
    public function loadLines(int $cartId): array
    {
        $db = $this->db;

        try {
            return $db->setQuery(
                $db->getQuery(true)
                    ->select([
                        $db->quoteName('id'),
                        $db->quoteName('id_item'),
                        $db->quoteName('id_combination'),
                        $db->quoteName('quantity'),
                        $db->quoteName('custom_unit_price_tax_incl'),
                    ])
                    ->from($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id_cart') . ' = ' . $cartId)
                    ->order($db->quoteName('id') . ' DESC'),
            )->loadObjectList() ?: [];
        } catch (Throwable $e) {
            throw new CartPersistenceException('loadLines', $cartId, $e);
        }
    }

    /**
     * One line of a known cart, or null when it is not that cart's.
     *
     * Both halves of the key are asked on purpose: a line id alone would answer
     * for somebody else's cart.
     *
     * @param int $lineId The line to read.
     * @param int $cartId The cart it must belong to.
     *
     * @return object|null id, id_item, id_combination.
     *
     * @throws CartPersistenceException When the read fails.
     *
     * @since  1.10.0
     */
    public function loadLine(int $lineId, int $cartId): ?object
    {
        $db = $this->db;

        try {
            return $db->setQuery(
                $db->getQuery(true)
                    ->select([
                        $db->quoteName('id'),
                        $db->quoteName('id_item'),
                        $db->quoteName('id_combination'),
                        $db->quoteName('custom_unit_price_tax_incl'),
                    ])
                    ->from($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id') . ' = ' . $lineId)
                    ->where($db->quoteName('id_cart') . ' = ' . $cartId),
            )->loadObject();
        } catch (Throwable $e) {
            throw new CartPersistenceException('loadLine', $cartId, $e);
        }
    }

    /**
     * Whether a line belongs to a cart.
     *
     * @param int $lineId The line in question.
     * @param int $cartId The cart it is claimed to be in.
     *
     *
     * @throws CartPersistenceException When the read fails.
     *
     * @since  1.10.0
     */
    public function lineBelongsTo(int $lineId, int $cartId): bool
    {
        $db = $this->db;

        try {
            return (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id') . ' = ' . $lineId)
                    ->where($db->quoteName('id_cart') . ' = ' . $cartId),
            )->loadResult() > 0;
        } catch (Throwable $e) {
            throw new CartPersistenceException('lineBelongsTo', $cartId, $e);
        }
    }

    /**
     * Every line of a cart holding one item, whatever its configuration.
     *
     * The caller compares configurations itself; this only narrows the rows it
     * has to compare.
     *
     * @param int $cartId The cart to read.
     * @param int $itemId The item those lines carry.
     *
     * @return object[] id, id_combination.
     *
     * @throws CartPersistenceException When the read fails.
     *
     * @since  1.10.0
     */
    public function findLinesForItem(int $cartId, int $itemId): array
    {
        $db = $this->db;

        try {
            return $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->quoteName('id'), $db->quoteName('id_combination')])
                    ->from($db->quoteName('#__alfa_cart_items'))
                    ->where($db->quoteName('id_cart') . ' = ' . $cartId)
                    ->where($db->quoteName('id_item') . ' = ' . $itemId),
            )->loadObjectList() ?: [];
        } catch (Throwable $e) {
            throw new CartPersistenceException('findLinesForItem', $cartId, $e);
        }
    }

    /**
     * The stored field selections of one line, as rows.
     *
     * Returned raw. What a selection MEANS (which field, which value, what the
     * payload decodes to) belongs to the caller, not to this table.
     *
     * @param int $lineId The line whose selections are wanted.
     *
     * @return object[] id_item_field, id_item_field_value, payload.
     *
     * @throws CartPersistenceException When the read fails.
     *
     * @since  1.10.0
     */
    public function loadSelectionRows(int $lineId): array
    {
        $db = $this->db;

        try {
            return $db->setQuery(
                $db->getQuery(true)
                    ->select([
                        $db->quoteName('id_item_field'),
                        $db->quoteName('id_item_field_value'),
                        $db->quoteName('payload'),
                    ])
                    ->from($db->quoteName('#__alfa_cart_item_fields'))
                    ->where($db->quoteName('id_cart_item') . ' = ' . $lineId),
            )->loadObjectList() ?: [];
        } catch (Throwable $e) {
            throw new CartPersistenceException('loadSelectionRows', 0, $e);
        }
    }

    /**
     * How much of one item a cart already holds against the same stock pool.
     *
     * The combination clause is the whole subtlety and is preserved exactly: a
     * named combination pools only with itself, while an unspecified one pools
     * with every line whose combination tracks no stock of its own, which is
     * why the join is a LEFT one and why a NULL stock counts as the same pool.
     *
     * @param int $cartId The cart to measure.
     * @param int $itemId The item to measure.
     * @param int|null $combinationId The combination, or null for the shared pool.
     * @param int $excludeLineId A line to leave out, when it is being replaced.
     *
     * @return float The quantity already committed.
     *
     * @throws CartPersistenceException When the read fails.
     *
     * @since  1.10.0
     */
    public function pooledQuantity(int $cartId, int $itemId, ?int $combinationId, int $excludeLineId = 0): float
    {
        $db = $this->db;

        try {
            $query = $db->getQuery(true)
                ->select('SUM(' . $db->quoteName('ci.quantity') . ')')
                ->from($db->quoteName('#__alfa_cart_items', 'ci'))
                ->join(
                    'LEFT',
                    $db->quoteName('#__alfa_item_combinations', 'c'),
                    $db->quoteName('c.id') . ' = ' . $db->quoteName('ci.id_combination'),
                )
                ->where($db->quoteName('ci.id_cart') . ' = ' . $cartId)
                ->where($db->quoteName('ci.id_item') . ' = ' . $itemId);

            if ($combinationId !== null) {
                $query->where($db->quoteName('ci.id_combination') . ' = ' . $combinationId);
            } else {
                $query->where(
                    '(' . $db->quoteName('ci.id_combination') . ' IS NULL OR '
                    . $db->quoteName('c.stock') . ' IS NULL)',
                );
            }

            if ($excludeLineId > 0) {
                $query->where($db->quoteName('ci.id') . ' != ' . $excludeLineId);
            }

            return (float) ($db->setQuery($query)->loadResult() ?: 0);
        } catch (Throwable $e) {
            throw new CartPersistenceException('pooledQuantity', $cartId, $e);
        }
    }

    /**
     * The coupon codes a cart holds.
     *
     * A read, not a judgement: whether a code still earns anything is the rules
     * engine's question, and this only says which ones the shopper entered.
     *
     * @param int $cartId The cart to read.
     *
     * @return string[] The codes, in insertion order.
     *
     * @throws CartPersistenceException When the read fails.
     *
     * @since  1.10.0
     */
    public function loadCouponCodes(int $cartId): array
    {
        if ($cartId <= 0) {
            return [];
        }

        $db = $this->db;

        try {
            return array_values(array_map('strval', $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('code'))
                    ->from($db->quoteName('#__alfa_cart_coupons'))
                    ->where($db->quoteName('id_cart') . ' = ' . $cartId),
            )->loadColumn() ?: []));
        } catch (Throwable $e) {
            throw new CartPersistenceException('loadCouponCodes', $cartId, $e);
        }
    }

    /**
     * The moment a stamp records. UTC, in the format the columns store.
     *
     *
     * @since  1.10.0
     */
    public function now(): string
    {
        return Factory::getDate('now', 'UTC')->toSql(false);
    }

    //  Internals

    /**
     * The rows that say how a line was configured.
     *
     * @param int $lineId The line they describe.
     * @param array $rows fieldId => ['value_id' => ?int, 'payload' => ?string].
     *
     *
     * @since  1.10.0
     */
    private function writeSelections(int $lineId, array $rows): void
    {
        $db = $this->db;

        foreach ($rows as $fieldId => $row) {
            $entry = new stdClass();
            $entry->id_cart_item = (int) $lineId;
            $entry->id_item_field = (int) $fieldId;
            $entry->id_item_field_value = $row['value_id'];
            $entry->payload = $row['payload'];

            $db->insertObject('#__alfa_cart_item_fields', $entry);
        }
    }

    /**
     * The write behind the two method columns.
     *
     * Both are one integer on the cart row plus the activity stamp, and the
     * only difference is which column it is and which operation name a failure
     * carries.
     *
     * @param int $cartId The cart.
     * @param string $column The cart column to set.
     * @param int $value The chosen method.
     * @param string $operation The public method name, for the failure.
     * @param string|null $at The instant to stamp; now() when omitted.
     *                        Reconciliation can write both columns in one
     *                        operation, and two clock reads there would
     *                        stamp two different instants for one act.
     *
     * @return bool True when the statement ran.
     *
     * @throws CartPersistenceException When the database refuses the statement.
     *
     * @since  1.10.0
     */
    private function setMethodColumn(int $cartId, string $column, int $value, string $operation, ?string $at = null): bool
    {
        $db = $this->db;

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_cart'))
                    ->set($db->quoteName($column) . ' = ' . (int) $value)
                    ->set($db->quoteName('updated') . ' = ' . $db->quote($at ?? $this->now()))
                    ->where($db->quoteName('id') . ' = ' . (int) $cartId),
            )->execute();

            return true;
        } catch (Throwable $e) {
            throw new CartPersistenceException($operation, $cartId, $e);
        }
    }

    /**
     * Delete cart lines matching a column, taking their selections with them,
     * and stamp the carts that lost one.
     *
     * The carts are read first: `#__alfa_cart_item_fields` carries no cart id,
     * so once the lines are gone there is nothing left to join through.
     *
     * @param string $column The cart-line column to match on.
     * @param int[] $values The values to match.
     * @param string $operation The public method name, for the failure.
     *
     * @return int[] The carts that lost a line.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    private function removeLinesMatching(string $column, array $values, string $operation): array
    {
        $values = $this->ids($values);

        if ($values === []) {
            return [];
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'id_cart']))
                    ->from($db->quoteName('#__alfa_cart_items'))
                    ->whereIn($db->quoteName($column), $values),
            )->loadObjectList() ?: [];

            if ($rows === []) {
                $db->transactionCommit(true);

                return [];
            }

            $lineIds = array_map(static fn ($row): int => (int) $row->id, $rows);
            $cartIds = array_values(array_unique(array_map(static fn ($row): int => (int) $row->id_cart, $rows)));

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_item_fields'))
                    ->whereIn($db->quoteName('id_cart_item'), $lineIds),
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_items'))
                    ->whereIn($db->quoteName('id'), $lineIds),
            )->execute();

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_cart'))
                    ->set($db->quoteName('updated') . ' = ' . $db->quote($this->now()))
                    ->whereIn($db->quoteName('id'), $cartIds),
            )->execute();

            $db->transactionCommit(true);

            return $cartIds;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new CartPersistenceException($operation, 0, $e);
        }
    }

    /**
     * Delete selection rows matching a column, LEAVING THE LINES STANDING, and
     * stamp the carts affected.
     *
     * The carts are two hops away (selection to line to cart) and must be
     * collected before the selections go.
     *
     * @param string $column The selection column to match on.
     * @param int[] $values The values to match.
     * @param string $operation The public method name, for the failure.
     *
     * @return int[] The carts whose lines lost a selection.
     *
     * @throws CartPersistenceException When the database refuses a statement.
     *
     * @since  1.10.0
     */
    private function removeSelectionsMatching(string $column, array $values, string $operation): array
    {
        $values = $this->ids($values);

        if ($values === []) {
            return [];
        }

        $db = $this->db;
        $db->transactionStart(true);

        try {
            $cartIds = array_map('intval', $db->setQuery(
                $db->getQuery(true)
                    ->select('DISTINCT ' . $db->quoteName('ci.id_cart'))
                    ->from($db->quoteName('#__alfa_cart_item_fields', 'f'))
                    ->join(
                        'INNER',
                        $db->quoteName('#__alfa_cart_items', 'ci')
                        . ' ON ' . $db->quoteName('ci.id') . ' = ' . $db->quoteName('f.id_cart_item'),
                    )
                    ->whereIn($db->quoteName('f.' . $column), $values),
            )->loadColumn() ?: []);

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_cart_item_fields'))
                    ->whereIn($db->quoteName($column), $values),
            )->execute();

            if ($cartIds !== []) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName('#__alfa_cart'))
                        ->set($db->quoteName('updated') . ' = ' . $db->quote($this->now()))
                        ->whereIn($db->quoteName('id'), $cartIds),
                )->execute();
            }

            $db->transactionCommit(true);

            return $cartIds;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw new CartPersistenceException($operation, 0, $e);
        }
    }

    /**
     * Positive integers only, de-duplicated.
     *
     * @param array $values Raw values.
     *
     * @return int[]
     *
     * @since  1.10.0
     */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $v): bool => $v > 0)));
    }
}
