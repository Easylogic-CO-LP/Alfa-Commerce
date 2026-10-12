<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Ledger Support: shared plumbing for the order ledgers
 *
 * The payment and shipment ledgers (PaymentLedger, ShipmentLedger) are two
 * independent CRUD + fluent-builder engines over different tables, but they
 * lean on the same low-level services: the database driver, the currency and
 * user of an order, method-name snapshotting, and warning logs. Those helpers
 * were byte-identical in both classes; this trait is their single home.
 *
 * All methods are private static: the trait contributes them to each using
 * class exactly as if they were declared there (late static binding keeps
 * {@see self::warn()} labelled with the concrete class).
 *
 * CONTRACT: the record-persistence helpers (getRaw/saveToTable/deleteFromTable)
 * read the using class's own schema constants, which every using class must
 * define: TABLE, PK, NULLABLE_INT_COLUMNS, NULLABLE_DATE_COLUMNS and
 * NULLABLE_ENUM_COLUMNS (the last may be an empty array).
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Site\Service\Data\PluginPayload;
use Alfa\Component\Alfa\Site\Service\Data\ValueException;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Closure;
use Exception;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use JsonException;
use Throwable;
use TypeError;

trait LedgerSupport
{
    //  THE PLUGIN PAYLOAD: the opaque JSON column every ledger row carries
    //
    //  `plugin_data` is whatever the carrier or gateway needs to remember about
    //  this row: a locker id, an instalment term, a label URL, the last event a
    //  webhook reported. The core never looks inside it. A named column per
    //  plugin concept would be a core schema that changes every time somebody
    //  writes a plugin, and would leave every OTHER plugin carrying dead
    //  columns.
    //
    //  THREE OPERATIONS, AND THE THIRD IS NOT THE SECOND:
    //
    //      ->pluginData(['parcel_id' => $id, 'status' => 'booked'])  write keys
    //      ->pluginData(['last_error' => null])                      write null
    //      ->removePluginData(['label_url'])                         drop keys
    //
    //  Storing null says "asked, and there is no answer". Removing says "this
    //  fact no longer exists". A reader can tell them apart: has() is true for
    //  the first and false for the second, so they must be different calls
    //  here too; a single method that treated null as an erase would collapse
    //  them and there would be no way back.
    //
    //  PLUGINS PASS ARRAYS, NEVER JSON. Encoding is this layer's business, and
    //  it happens once, here, at save time. A plugin that json_encode()s its own
    //  payload double-encodes it the moment somebody merges into it.

    /**
     * Keys staged for writing, in the order they were staged.
     *
     *
     * @since  1.10.0
     */
    protected array $pluginDataSet = [];

    /**
     * Keys staged for removal.
     *
     * @var string[]
     *
     * @since  1.10.0
     */
    protected array $pluginDataRemove = [];

    /**
     * Merge keys into this row's plugin payload.
     *
     *     $this->shipmentUpdate($id)
     *         ->pluginData(['parcel_id' => $parcelId, 'status' => 'booked'])
     *         ->save();
     *
     * STAGES; save() PERSISTS. Nothing is read or written until save(), and
     * that is what makes the merge safe: the payload already in the row is read
     * at the moment of writing, with the row held, not when the builder was
     * created. A booking that takes three seconds on the carrier's API cannot
     * overwrite a webhook that landed during those three seconds: the
     * webhook's keys are still there when the merge runs, and only the keys
     * named here change.
     *
     * MERGE DEPTH: ONE LEVEL, and that is deliberate and unchanged. The merge is
     * array_merge() over the top-level keys, so:
     *
     *   - a key named here REPLACES what was stored under it, wholesale. Writing
     *     ['tracking' => ['state' => 'in_transit']] over
     *     ['tracking' => ['state' => 'booked', 'at' => '...']] leaves the 'at'
     *     behind: read, change and write the whole sub-array instead.
     *   - a key NOT named here is left exactly as it was.
     *   - INTEGER-LIKE KEYS ARE APPENDED, NOT OVERWRITTEN: array_merge()
     *     renumbers them. Use string keys; '0' and '1' do not mean what they
     *     look like they mean.
     *
     * Values must survive a round trip through JSON: strings, numbers, booleans,
     * null, and arrays of those. Objects, resources, NAN and INF are refused
     * here rather than written as something else.
     *
     * @param array $data Keys to merge in.
     *
     *
     * @throws ValueException When a value cannot be stored.
     *
     * @since  1.9.1
     */
    public function pluginData(array $data): static
    {
        self::assertStorable($data);

        // Later calls win over earlier ones, the same way the final merge will
        // treat them against the stored row.
        $this->pluginDataSet = array_merge($this->pluginDataSet, $data);

        // Setting a key that was staged for removal cancels the removal: the
        // last thing the caller said about that key is what it meant.
        $this->pluginDataRemove = array_values(
            array_diff($this->pluginDataRemove, array_keys($data)),
        );

        return $this;
    }

    /**
     * Drop keys from this row's plugin payload.
     *
     *     $this->shipmentUpdate($id)->removePluginData(['label_url'])->save();
     *
     * NOT THE SAME AS WRITING NULL. This leaves no trace of the key at all, so
     * a reader's has() answers false and a typed accessor falls back to its
     * default. Write null instead when the fact still applies and its value is
     * simply unknown.
     *
     * Removing a key that was never there is not an error: the outcome asked
     * for is the outcome already in place.
     *
     * @param string[] $keys Keys to drop.
     *
     *
     * @since  1.10.0
     */
    public function removePluginData(array $keys): static
    {
        foreach ($keys as $key) {
            if (!\is_string($key) || $key === '') {
                throw new ValueException(
                    static::class . '::removePluginData(): keys must be non-empty strings.',
                );
            }

            // A key removed after being staged for writing is removed: again,
            // the last instruction wins.
            unset($this->pluginDataSet[$key]);

            if (!\in_array($key, $this->pluginDataRemove, true)) {
                $this->pluginDataRemove[] = $key;
            }
        }

        return $this;
    }

    /**
     * Whether this builder has payload changes waiting to be written.
     *
     * The builders' "nothing changed, do not write" short-circuit reads this:
     * a save whose ONLY change is a payload key is still a save.
     *
     *
     * @since  1.10.0
     */
    protected function hasStagedPluginData(): bool
    {
        return $this->pluginDataSet !== [] || $this->pluginDataRemove !== [];
    }

    /**
     * Resolve the staged changes against a row that is not there yet.
     *
     * Nothing to merge with and nothing to remove from, so the staged keys ARE
     * the payload.
     *
     *
     * @since  1.10.0
     */
    protected function stagePluginDataForCreate(): void
    {
        if (!$this->hasStagedPluginData()) {
            return;
        }

        $this->data['plugin_data'] = self::encodePayload($this->pluginDataSet);
    }

    /**
     * Write a row, merging any staged payload into it under the row's own lock.
     *
     * WHY A TRANSACTION FOR AN OPAQUE COLUMN. Two writers reach one row by
     * design: a webhook reporting what the carrier did, and an operator doing
     * something in the admin. Both merge, so both must read-modify-write, and
     * two unsynchronised read-modify-writes lose whichever finished first.
     * Holding the row from the read until the write commits is what makes
     * "unrelated keys are preserved" true rather than usually true.
     *
     * The lock is taken ONLY when there is a payload to merge; an ordinary
     * status change pays nothing for this.
     *
     * transactionStart(true) degrades to a SAVEPOINT when something has already
     * opened a transaction, so this composes inside placement.
     *
     * @param int $id The row being written.
     * @param Closure $write fn(): int|false - performs the actual row write.
     *
     * @return int|false Whatever the write returned.
     *
     * @since  1.10.0
     */
    protected function writeWithPluginData(int $id, Closure $write): int|false
    {
        if (!$this->hasStagedPluginData()) {
            return $write();
        }

        $db = self::db();
        $db->transactionStart(true);

        try {
            $this->mergeStagedPluginData($id);

            $result = $write();

            if ($result === false) {
                TransactionState::rollBackLevel($db);

                return false;
            }

            TransactionState::commitLevel($db);

            return $result;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel($db, $e);

            throw $e;
        }
    }

    /**
     * Read the stored payload with the row held, apply the staged changes, and
     * put the result where the row write will pick it up.
     *
     * @param int $id The row.
     *
     *
     * @throws ValueException When what is stored cannot be read.
     *
     * @since  1.10.0
     */
    private function mergeStagedPluginData(int $id): void
    {
        $db = self::db();

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('plugin_data'))
                ->from($db->quoteName(static::TABLE))
                ->where($db->quoteName(static::PK) . ' = ' . $id)
            . ' FOR UPDATE',
        );

        $current = PluginPayload::of(
            $db->loadResult(),
            static::TABLE . ' #' . $id . '.plugin_data',
        )->toArray();

        // array_merge, not '+' and not a recursive merge: documented on
        // pluginData() and relied on by callers that replace a sub-array whole.
        $merged = array_merge($current, $this->pluginDataSet);

        foreach ($this->pluginDataRemove as $key) {
            unset($merged[$key]);
        }

        $this->data['plugin_data'] = self::encodePayload($merged);
    }

    /**
     * Refuse anything that cannot come back out of the column as it went in.
     *
     * Checked when it is STAGED, not when it is written, so the refusal names
     * the call that made the mistake instead of surfacing three lines later
     * inside save() with no clue which key was at fault.
     *
     * @param array $data The keys being staged.
     *
     *
     * @throws ValueException When a key or value cannot be stored.
     *
     * @since  1.10.0
     */
    private static function assertStorable(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!\is_string($key) || $key === '') {
                throw new ValueException(sprintf(
                    '%s::pluginData(): keys must be non-empty strings, %s given.',
                    static::class,
                    \is_int($key) ? 'the integer ' . $key : \gettype($key),
                ));
            }

            self::assertStorableValue($key, $value);
        }
    }

    /**
     * One value, checked to the bottom of whatever it nests.
     *
     * @param string $key The key being written, for the message.
     * @param mixed $value The value.
     * @param int $depth How deep we already are.
     *
     *
     * @throws ValueException When the value cannot be stored.
     *
     * @since  1.10.0
     */
    private static function assertStorableValue(string $key, mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new ValueException(sprintf(
                '%s::pluginData(): "%s" nests too deeply to store.',
                static::class,
                $key,
            ));
        }

        if ($value === null || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return;
        }

        if (\is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new ValueException(sprintf(
                    '%s::pluginData(): "%s" is %s, which JSON cannot express.',
                    static::class,
                    $key,
                    is_nan($value) ? 'NAN' : 'INF',
                ));
            }

            return;
        }

        if (\is_array($value)) {
            foreach ($value as $nested) {
                self::assertStorableValue($key, $nested, $depth + 1);
            }

            return;
        }

        // Objects included, deliberately. A payload is a record, not a live
        // graph: an object that round-trips to an associative array is not the
        // same thing coming back, and one that does not round-trip at all is a
        // silent data loss. Callers convert to arrays themselves, so the shape
        // they get back is the shape they chose.
        throw new ValueException(sprintf(
            '%s::pluginData(): "%s" is %s, which cannot be stored: pass scalars, null or arrays.',
            static::class,
            $key,
            \is_object($value) ? 'an instance of ' . $value::class : \gettype($value),
        ));
    }

    /**
     * The payload, as the column stores it.
     *
     * @param array $payload The merged payload.
     *
     *
     * @throws ValueException When encoding fails after all.
     *
     * @since  1.10.0
     */
    private static function encodePayload(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            // In practice a string that is not valid UTF-8: a name that came
            // in through an import in the wrong encoding. Refusing beats
            // writing the empty string, which would erase the whole payload.
            throw new ValueException(sprintf(
                '%s: the payload could not be encoded (%s).',
                static::class,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * The Joomla database driver.
     *
     *
     * @since  1.0.0
     */
    private static function db(): \Joomla\Database\DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Load one ledger row by primary key.
     *
     * @param int $id Row PK.
     *
     * @return object|null The row, or null when absent.
     *
     * @since  1.0.0
     */
    private static function getRaw(int $id): ?object
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName(self::PK) . ' = ' . (int) $id);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }

    /**
     * Insert or update a ledger row, keyed by the primary key present in
     * $data. Unknown keys are stripped to real table columns, and the three
     * nullable-column sets are sanitised ('' -> null) so MySQL strict mode
     * accepts them. On insert, the new PK is written back into $data.
     *
     * @param array $data Row data (by reference; PK back-filled on insert).
     *
     * @return bool True on success, false on failure (logged via warn()).
     *
     * @since  1.0.0
     */
    private static function saveToTable(array &$data): bool
    {
        $db = self::db();

        // Filter to real DB columns: unknown keys safely stripped
        $tableColumns = $db->getTableColumns(self::TABLE);
        $filtered = array_intersect_key($data, $tableColumns);

        if (empty($filtered)) {
            self::warn('saveToTable(): no valid columns after filtering');
            return false;
        }

        // Sanitize nullable columns: forms send '' which MySQL strict rejects
        foreach (self::NULLABLE_INT_COLUMNS as $col) {
            if (array_key_exists($col, $filtered) && $filtered[$col] === '') {
                $filtered[$col] = null;
            }
        }
        foreach (self::NULLABLE_DATE_COLUMNS as $col) {
            if (array_key_exists($col, $filtered) && empty($filtered[$col])) {
                $filtered[$col] = null;
            }
        }
        foreach (self::NULLABLE_ENUM_COLUMNS as $col) {
            if (array_key_exists($col, $filtered) && $filtered[$col] === '') {
                $filtered[$col] = null;
            }
        }

        // A gateway reference that overflows its column must never cost us the
        // row: the charge already happened at the gateway, and a ledger without
        // it is a money hole. Clamp to what the column holds and say so loudly;
        // the full value stays in the gateway's own logs.
        self::clampGatewayReference($filtered);

        $isNew = empty($filtered[self::PK]) || (int) $filtered[self::PK] === 0;

        try {
            if ($isNew) {
                unset($filtered[self::PK]);
                $obj = (object) $filtered;
                $db->insertObject(self::TABLE, $obj, self::PK);
                $data[self::PK] = $obj->{self::PK} ?? $db->insertid();
            } else {
                $obj = (object) $filtered;
                // $nulls = TRUE. updateObject() defaults to false, which
                // SKIPS every null column, so a field the caller
                // deliberately CLEARED is silently left at its old value.
                // The nullable-column sanitising above exists precisely to
                // turn a form's '' into null, and without this that work was
                // thrown away on every update: clearing a refund's credit-note
                // attribution, its refund link or its processed_at read as
                // saved and changed nothing.
                $db->updateObject(self::TABLE, $obj, self::PK, true);
                $data[self::PK] = $filtered[self::PK];
            }
        } catch (Exception $e) {
            if (TransactionState::endedBy($db, $e)) {
                throw $e;
            }

            self::warn('saveToTable() failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Trim over-long gateway references to their column width.
     *
     * `transaction_id` is the one column a third party fills with a value we do
     * not control, and MySQL in strict mode rejects the whole INSERT when it
     * overflows, which used to lose the payment attempt entirely (26 such
     * losses on the demo before this guard). Truncating a reference degrades
     * lookup; dropping the row loses the money trail, so we truncate and warn.
     *
     * @param array $filtered Row about to be written (by reference).
     *
     *
     * @since  1.0.0
     */
    private static function clampGatewayReference(array &$filtered): void
    {
        $column = 'transaction_id';

        if (!isset($filtered[$column]) || !\is_string($filtered[$column])) {
            return;
        }

        $limit = self::textColumnLimit($column);
        $value = $filtered[$column];

        if ($limit <= 0 || mb_strlen($value) <= $limit) {
            return;
        }

        $filtered[$column] = mb_substr($value, 0, $limit);

        // The prefix is what identifies the producer next time: a reference this
        // long is usually a payload or an error text a gateway put in the id
        // field, and without a sample the log cannot say whose it was.
        self::warn(\sprintf(
            'gateway reference was %d characters, longer than %s holds (%d), stored truncated so the payment row survives. Starts with: %s...',
            mb_strlen($value),
            $column,
            $limit,
            mb_substr($value, 0, 32),
        ));
    }

    /**
     * How many characters a text column of this table holds.
     *
     * `getTableColumns()` in its default shape answers 'varchar' with no width,
     * so the declared length has to come from the full column definition, read
     * once per request and remembered.
     *
     * @param string $column Column name.
     *
     * @return int Declared length, or 0 when the column is not a sized text column.
     *
     * @since  1.0.0
     */
    private static function textColumnLimit(string $column): int
    {
        static $limits = null;

        if ($limits === null) {
            $limits = [];

            try {
                foreach (self::db()->getTableColumns(self::TABLE, false) as $name => $definition) {
                    if (preg_match('/^(?:var)?char\((\d+)\)/i', (string) ($definition->Type ?? ''), $m)) {
                        $limits[$name] = (int) $m[1];
                    }
                }
            } catch (Throwable $e) {
                // A driver that cannot describe the table simply gets no clamp.
                $limits = [];
            }
        }

        return $limits[$column] ?? 0;
    }

    /**
     * Delete one ledger row by primary key.
     *
     * @param int $id Row PK.
     *
     * @return bool True on success, false on failure (logged via warn()).
     *
     * @since  1.0.0
     */
    private static function deleteFromTable(int $id): bool
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName(self::PK) . ' = ' . (int) $id);

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            self::warn('deleteFromTable() failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Resolve a payment/shipment method name for the snapshot columns.
     *
     * Snapshot at save time: survives method deletion. Method names are
     * multilingual (per-language aux tables); the base row carries none, so
     * a plain getItem() snapshots ''; resolve via the language tables
     * (current tag -> site default), like checkout.
     *
     * @param string $modelName 'Payment' or 'Shipment'
     * @param int $methodId Method row PK
     *
     * @return string Method name, or empty string if not found
     *
     * @since  1.0.0
     */
    private static function resolveMethodName(string $modelName, int $methodId): string
    {
        if ($methodId <= 0) {
            return '';
        }

        $map = [
            'Payment' => ['#__alfa_payments', 'id_payment'],
            'Shipment' => ['#__alfa_shipments', 'id_shipment'],
        ];

        if (!isset($map[$modelName])) {
            return '';
        }

        try {
            return MultilingualHelper::getTranslatedValue(
                id:                $methodId,
                tableName:         $map[$modelName][0],
                primaryColumnName: $map[$modelName][1],
                field:             'name',
            );
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Resolve a payment/shipment method's PLUGIN ELEMENT for the snapshot.
     *
     * The sibling of resolveMethodName(), and the more important of the two: a
     * name is a label, this is identity. It is what boots the plugin that can
     * refund the card, and once the method row is deleted there is nowhere
     * else to learn it: the name cannot be reverse-engineered into an
     * element, and on this codebase it is not even unique (the credit ledger
     * writes 'Customer credit' onto rows whose method is the order's real one).
     *
     * Unlike the name this lives on the BASE row, not the per-language aux
     * tables, so it is a plain single-column read.
     *
     * @param string $modelName 'Payment' or 'Shipment'
     * @param int $methodId Method row PK
     *
     * @return string Plugin element, or '' if the method is gone
     *
     * @since  1.10.0
     */
    private static function resolveMethodType(string $modelName, int $methodId): string
    {
        if ($methodId <= 0) {
            return '';
        }

        $table = ['Payment' => '#__alfa_payments', 'Shipment' => '#__alfa_shipments'][$modelName] ?? '';

        if ($table === '') {
            return '';
        }

        try {
            $db = self::db();

            // #__alfa_shipments.type is `text` where the payments one is
            // varchar(50); the column it feeds is varchar(50) on both sides.
            return substr((string) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('type'))
                    ->from($db->quoteName($table))
                    ->where($db->quoteName('id') . ' = ' . $methodId),
            )->loadResult(), 0, 50);
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Get a related admin model via Joomla's MVC factory.
     *
     * @param string $name Model name: 'Payment', 'Shipment', etc.
     *
     * @return \Joomla\CMS\MVC\Model\AdminModel
     *
     * @since  1.0.0
     */
    private static function getRelatedModel(string $name)
    {
        return Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel($name, 'Administrator', ['ignore_request' => true]);
    }

    /**
     * The write boundary of a ledger row's money: the caller's figure as the
     * text its decimal column is handed.
     *
     * A ledger row's money is money in its ORDER'S currency, at the order's
     * frozen scale: a payment, a refund and a shipping cost alike (create()
     * stamps id_currency from OrderCurrency::idOf()). A Money in another
     * currency is refused here, the last point where both the Money and the
     * order are in hand: €20 booked against a £-denominated order as a bare
     * "20.00" reconciles to nothing and is invisible afterwards. A Money of
     * that currency is read again at the order's frozen scale
     * ({@see self::frozenScaleMoney()}); one finer than that scale is refused by
     * name and never stored finer.
     *
     * Any other figure (a typed text, an integer, a float) carries no currency
     * to disagree with and is read once as the order's money
     * ({@see Money::of()}): a text exactly, a float at the digits Money reads
     * it at. The column is handed the exact decimal text of that Money, never
     * a float, which a database driver prints with fourteen significant digits.
     *
     * @param mixed $value Money, a numeric text, or a number; null and '' are nothing.
     * @param int $orderId Order whose currency the figure must be in.
     * @param string $field Column name, for the refusal message.
     *
     * @return string|false The column's text; false = refused, nothing may be written.
     *
     * @throws OrderCurrencyException When the order names no currency.
     *
     * @since  1.14.0
     */
    private static function moneyForStorage(mixed $value, int $orderId, string $field): string|false
    {
        $currency = OrderCurrency::of(orderId: $orderId);

        if ($value instanceof Money) {
            if (!$value->getCurrency()->equals($currency)) {
                self::warn(sprintf(
                    '%s is in %s but order #%d is in %s; refusing the write rather than storing a bare number',
                    $field,
                    $value->getCurrency()->getCode(),
                    $orderId,
                    $currency->getCode(),
                ));

                return false;
            }

            $atScale = self::frozenScaleMoney(value: $value, currency: $currency);

            if ($atScale === null) {
                self::warn(sprintf(
                    '%s %s is finer than order #%d\'s money, which is kept at %d decimal places; nothing was written',
                    $field,
                    ExactAmount::ofMoney($value)->text(),
                    $orderId,
                    $currency->getDecimalPlaces(),
                ));

                return false;
            }

            return StoredDecimal::of($atScale);
        }

        try {
            return StoredDecimal::of(Money::of($value === null || $value === '' ? 0 : $value, $currency));
        } catch (InvalidArgumentException | TypeError $e) {
            self::warn(sprintf('%s of order #%d is not an amount of its money (%s); nothing was written', $field, $orderId, $e->getMessage()));

            return false;
        }
    }

    /**
     * A Money of the order's currency, read again at the order's frozen scale.
     *
     * Currency::equals() compares the currency's number only, so a Money made
     * from the currency's row after its scale changed passes as the order's
     * money while its minor units are of another scale. Its exact digits are
     * read at the order's scale; a figure finer than that scale is not money
     * of the order and is answered as null, never rounded.
     *
     * @param Money $value A Money of the order's currency number.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return Money|null The same figure at the order's scale; null when finer.
     *
     * @since 1.14.0
     */
    private static function frozenScaleMoney(Money $value, Currency $currency): ?Money
    {
        $text = ExactAmount::ofMoney($value)->text();
        $point = strpos($text, '.');

        if ($point !== false && \strlen($text) - $point - 1 > $currency->getDecimalPlaces()) {
            return null;
        }

        return Money::of($text, $currency);
    }

    /**
     * Get the current authenticated user's ID.
     *
     * Returns 0 for guests and CLI contexts.
     *
     *
     * @since  1.0.0
     */
    private static function getCurrentUserId(): int
    {
        try {
            return Factory::getApplication()->getIdentity()->id ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Log a warning to the Joomla log and the admin message area.
     *
     * The log tag is the concrete ledger class (via late static binding), so
     * PaymentLedger and ShipmentLedger stay distinguishable in the log. The
     * message is plain text: the log takes it as is, the message area escaped.
     * A sentence written for the operator names its call in the log only.
     *
     * @param string $message Warning text (plain text, never markup)
     * @param string $context The call it concerns, for the log only ('create()'); empty when the message names it.
     *
     *
     * @since  1.0.0
     */
    private static function warn(string $message, string $context = ''): void
    {
        $label = substr((string) strrchr(static::class, '\\'), 1);

        Log::add('[' . $label . '] ' . ($context !== '' ? $context . ': ' : '') . $message, Log::WARNING, 'com_alfa.orders');

        try {
            // Plain text, and the queue renders HTML: a message can quote a
            // typed Transaction ID or a driver error that echoes a value.
            Factory::getApplication()->enqueueMessage(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), 'error');
        } catch (Exception $e) {
            // Silently ignore: CLI or early boot context
        }
    }
}
