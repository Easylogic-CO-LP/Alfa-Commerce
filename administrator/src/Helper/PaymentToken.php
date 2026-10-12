<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

/**
 * Per-purpose payment credential derived from the order's secure key. The raw
 * secure_key travels inside emailed download links, so handing that SAME secret
 * to the payment endpoints would let a leaked download URL be replayed against
 * the payment webhook chain: the HMAC binds the credential to the payment
 * purpose (and the order id) without revealing the key. The plugin's own
 * gateway verification remains the real authentication; this only proves the
 * caller knows the order.
 *
 * @since  1.9.0
 */
final class PaymentToken
{
    /**
     * Derive the payment token for an order.
     *
     * @param int $orderId Order PK the token is bound to.
     * @param string $secureKey The order's secure_key (HMAC key).
     *
     * @return string Hex sha256 HMAC.
     *
     * @since  1.9.0
     */
    public static function for(int $orderId, string $secureKey): string
    {
        return hash_hmac('sha256', 'alfa.payment:' . $orderId, $secureKey);
    }

    /**
     * Constant-time check of a presented token. An empty secure key or an
     * empty candidate never matches: a blank stored key must not open the
     * endpoint.
     *
     * @param int $orderId Order PK the token claims.
     * @param string $secureKey The order's secure_key (HMAC key).
     * @param string $candidate The token presented by the caller.
     *
     *
     * @since  1.9.0
     */
    public static function verify(int $orderId, string $secureKey, string $candidate): bool
    {
        if ($secureKey === '' || $candidate === '') {
            return false;
        }

        return hash_equals(self::for($orderId, $secureKey), $candidate);
    }
}
