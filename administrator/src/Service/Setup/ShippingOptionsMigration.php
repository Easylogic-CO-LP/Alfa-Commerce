<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingPriceEntryMode;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use RuntimeException;
use Throwable;

/**
 * The move of the two former shop-level shipping Options onto every shipping method, taken once by an update.
 *
 * Up to 2.1.0 the shop had two Options for the tax on shipping: Default shipping taxes (the tax rules of every
 * method that named none) and Shipping prices entered (what an entered shipping price meant, for every method).
 * Each shipping method now owns both, and nothing reads the Options any more. The move keeps what an updated shop
 * charged:
 *  - a method that names tax rules of its own keeps exactly them; the former default is never added to it;
 *  - a method that names none is given the rules of the former default that still exist, in the order named;
 *  - every method is given the former entry mode as it was read: tax excluded when stored so, tax included
 *    otherwise (never set, or a value the Options never offered);
 *  - only then, in the same transaction, are both Options removed. A failure leaves every method and both Options
 *    as they were, and the next update runs the move again. The Options being gone is the record that the move
 *    ran: a later run finds nothing to move and changes nothing.
 * Placed orders, their parcels and their documents keep what they froze: nothing here reads or writes them.
 *
 * @since  2.1.0
 */
final class ShippingOptionsMigration
{
    /**
     * The former Option holding the tax rules of a method that named none.
     *
     * @since  2.1.0
     */
    public const TAX_RULES_OPTION = 'shipping_tax_rules';

    /**
     * The former Option holding what an entered shipping price meant.
     *
     * @since  2.1.0
     */
    public const ENTRY_MODE_OPTION = 'shipping_price_entry_mode';

    /**
     * The method column of the entry mode, exactly as the install SQL and 2.1.0.sql define it.
     *
     * @since  2.1.0
     */
    private const COLUMN_DEFINITION = "enum('tax_included','tax_excluded') NOT NULL DEFAULT 'tax_included'"
        . " COMMENT 'What a price entered for this method means: tax_included (the customer pays it, the tax is inside)"
        . " or tax_excluded (the tax is added on top). Set on each method; there is no shop default'";

    /**
     * @param DatabaseDriver $db The connection the update runs on.
     *
     * @since  2.1.0
     */
    public function __construct(private readonly DatabaseDriver $db)
    {
    }

    /**
     * Add the method column of the entry mode when it is missing.
     *
     * 2.1.0.sql adds it on an update from 2.0. A site whose schema Joomla already recorded as 2.1.0 (an earlier
     * build of this release) never runs that file again, so the column is added here, with the same definition and
     * in the same place, before the move needs it. DDL: never inside a transaction.
     *
     * @return bool True when it was added now.
     *
     * @since  2.1.0
     */
    public function completeColumn(): bool
    {
        $db = $this->db;

        if (\array_key_exists('price_entry_mode', $db->getTableColumns('#__alfa_shipments', false))) {
            return false;
        }

        $db->setQuery(
            'ALTER TABLE ' . $db->quoteName('#__alfa_shipments') . ' ADD COLUMN ' . $db->quoteName('price_entry_mode')
            . ' ' . self::COLUMN_DEFINITION . ' AFTER ' . $db->quoteName('show_on_product'),
        )->execute();

        return true;
    }

    /**
     * Move the former Options onto every shipping method, then remove them, in one transaction.
     *
     * The answer says what was moved: the entry mode every method was given, the rules given to the methods
     * that named none, those methods, and how many methods there are. It is null when neither Option is
     * stored: nothing to move, or moved already.
     *
     * @return array{mode: string, rules: int[], given: int[], methods: int}|null
     *
     * @throws RuntimeException When the move failed: nothing was changed.
     *
     * @since  2.1.0
     */
    public function run(): ?array
    {
        $db = $this->db;
        $stored = ComponentOptions::stored(db: $db);

        if (!\array_key_exists(self::TAX_RULES_OPTION, $stored) && !\array_key_exists(self::ENTRY_MODE_OPTION, $stored)) {
            return null;
        }

        $storedMode = $stored[self::ENTRY_MODE_OPTION] ?? null;
        $mode = (\is_string($storedMode) ? ShippingPriceEntryMode::tryFrom($storedMode) : null) ?? ShippingPriceEntryMode::TaxIncluded;
        $rules = $this->existingRules(named: $stored[self::TAX_RULES_OPTION] ?? []);
        $remaining = $stored;
        unset($remaining[self::TAX_RULES_OPTION], $remaining[self::ENTRY_MODE_OPTION]);

        $db->transactionStart(true);

        try {
            $modeValue = $mode->value;
            $db->setQuery(
                $db->createQuery()
                    ->update($db->quoteName('#__alfa_shipments'))
                    ->set($db->quoteName('price_entry_mode') . ' = :mode')
                    ->bind(':mode', $modeValue, ParameterType::STRING),
            )->execute();

            $given = $this->methodsNamingNone();

            if ($given !== [] && $rules !== []) {
                $rows = [];

                foreach ($given as $methodId) {
                    foreach ($rules as $ruleId) {
                        $rows[] = $methodId . ', ' . $ruleId;
                    }
                }

                $db->setQuery(
                    $db->createQuery()
                        ->insert($db->quoteName('#__alfa_shipment_taxes'))
                        ->columns($db->quoteName(['shipment_id', 'tax_id']))
                        ->values($rows),
                )->execute();
            }

            // The record that the move ran, written last: the Options lose both keys only with everything above.
            ComponentOptions::write(params: $remaining, db: $db);

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            ComponentOptions::forgetCachedRecords();

            try {
                ComponentHelper::getComponent('com_alfa')->setParams(new Registry($stored));
            } catch (Throwable) {
                // A request that never loaded the component records has nothing to restore.
            }

            throw new RuntimeException(rtrim($e->getMessage(), '. '), 0, $e);
        }

        return [
            'mode' => $mode->value,
            'rules' => $rules,
            'given' => $given,
            'methods' => (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__alfa_shipments')))->loadResult(),
        ];
    }

    /**
     * The tax rules a former default named that still exist: positive ids, each once, in the order named.
     *
     * @param mixed $named The Option as stored: a list of ids, or one id.
     *
     * @return int[]
     *
     * @since  2.1.0
     */
    private function existingRules(mixed $named): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(
                static fn ($value): int => \is_scalar($value) && preg_match('/^\s*\d+\s*$/', (string) $value) ? (int) $value : 0,
                \is_array($named) ? $named : [$named],
            ),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $db = $this->db;
        $existing = array_map('intval', $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_taxes'))
                ->whereIn($db->quoteName('id'), $ids),
        )->loadColumn() ?: []);

        return array_values(array_filter($ids, static fn (int $id): bool => \in_array($id, $existing, true)));
    }

    /**
     * The shipping methods that name no tax rule of their own.
     *
     * @return int[] Their ids, ascending.
     *
     * @since  2.1.0
     */
    private function methodsNamingNone(): array
    {
        $db = $this->db;
        $named = $db->createQuery()
            ->select('1')
            ->from($db->quoteName('#__alfa_shipment_taxes', 'st'))
            ->where($db->quoteName('st.shipment_id') . ' = ' . $db->quoteName('s.id'))
            ->where($db->quoteName('st.tax_id') . ' > 0');

        return array_map('intval', $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('s.id'))
                ->from($db->quoteName('#__alfa_shipments', 's'))
                ->where('NOT EXISTS (' . $named . ')')
                ->order($db->quoteName('s.id') . ' ASC'),
        )->loadColumn() ?: []);
    }
}
