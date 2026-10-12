<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use stdClass;

/**
 * PaymentReferenceVault: the payment credential VAULT.
 *
 * Owns `#__alfa_payment_references`, the credential vault: stored
 * credentials for merchant-initiated charges are a first-class PAYMENTS
 * entity, reusable across consumers (subscriptions today, saved-cards
 * checkout tomorrow), revocable, with expiry visibility for pre-dunning.
 *
 * The `reference` payload is OPAQUE gateway-owned JSON: written from a
 * StoreReferenceEvent result, handed back verbatim to ChargeReferenceEvent.
 * Core never interprets it. Consumers store only the vault row id.
 *
 * Revocation keeps the row (state 0): audit stays intact and consumers
 * discover the credential is gone through findActive() returning null.
 *
 * @since  1.3.0
 */
class PaymentReferenceVault
{
    /**
     * Persist a reference produced by a StoreReferenceEvent.
     *
     * @param int $userId Owning customer (0 = guest).
     * @param int $paymentMethodId `#__alfa_payments` row that can charge it.
     * @param string $gateway Payment plugin element (denormalized guard).
     * @param int $originOrderId Order whose customer-present payment made the mandate.
     * @param array $reference Opaque gateway payload.
     * @param string $label Customer-facing display ('' ok).
     * @param string|null $expiresAt Credential expiry (UTC SQL) or null.
     *
     * @return int New vault row id.
     *
     * @since  1.3.0
     */
    public static function store(
        int $userId,
        int $paymentMethodId,
        string $gateway,
        int $originOrderId,
        array $reference,
        string $label = '',
        ?string $expiresAt = null,
    ): int {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $row = new stdClass();
        $row->id_user = $userId;
        $row->id_payment_method = $paymentMethodId;
        $row->gateway = $gateway;
        $row->id_origin_order = $originOrderId;
        $row->reference = json_encode($reference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row->label = substr($label, 0, 100);
        $row->expires_at = $expiresAt;
        $row->state = 1;
        $row->created = Factory::getDate('now', 'UTC')->toSql();
        $row->created_by = $userId;

        $db->insertObject('#__alfa_payment_references', $row, 'id');

        return (int) $row->id;
    }

    /**
     * Load an ACTIVE reference row, payload decoded onto ->reference.
     *
     * Returns null for missing, revoked or foreign-gateway rows; consumers
     * treat null as "credential gone, fall back to manual payment".
     *
     * @param int $id Vault row id.
     * @param string|null $gateway When given, the row must belong to this
     *                             gateway (guards against method retyping).
     *
     * @since  1.3.0
     */
    public static function findActive(int $id, ?string $gateway = null): ?object
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__alfa_payment_references'))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('state') . ' = 1')
                ->bind(':id', $id);

            $db->setQuery(query: $query);
            $row = $db->loadObject();

            if ($row === null || ($gateway !== null && $row->gateway !== $gateway)) {
                return null;
            }

            $row->reference = json_decode((string) $row->reference, true) ?: [];

            return $row;
        } catch (Exception $e) {
            Log::add(
                entry:    'PaymentReferenceVault::findActive failed for #' . $id . ': ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.orders',
            );

            return null;
        }
    }

    /**
     * Active references of a customer (future saved-cards UI / method pickers).
     *
     * @param int $userId Customer id.
     *
     * @return object[] Rows with decoded ->reference, newest first.
     *
     * @since  1.3.0
     */
    public static function findForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__alfa_payment_references'))
            ->where($db->quoteName('id_user') . ' = :userId')
            ->where($db->quoteName('state') . ' = 1')
            ->order($db->quoteName('id') . ' DESC')
            ->bind(':userId', $userId);

        $db->setQuery(query: $query);
        $rows = $db->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $row->reference = json_decode((string) $row->reference, true) ?: [];
        }

        return $rows;
    }

    /**
     * Revoke a reference (audit-preserving soft delete).
     *
     * @param int $id Vault row id.
     *
     * @return bool True when a row was revoked.
     *
     * @since  1.3.0
     */
    public static function revoke(int $id): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_payment_references'))
            ->set($db->quoteName('state') . ' = 0')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':id', $id);

        $db->setQuery(query: $query);
        $db->execute();

        return (int) $db->getAffectedRows() === 1;
    }

    /**
     * Stamp a successful charge (dunning/expiry reporting reads this).
     *
     * @param int $id Vault row id.
     *
     * @since  1.3.0
     */
    public static function markUsed(int $id): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $now = Factory::getDate('now', 'UTC')->toSql();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_payment_references'))
            ->set($db->quoteName('last_used_at') . ' = :now')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':now', $now)
            ->bind(':id', $id);

        $db->setQuery(query: $query);
        $db->execute();
    }
}
