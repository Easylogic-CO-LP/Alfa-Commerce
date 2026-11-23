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
 * Per-document credential derived from the order's secure key, as PaymentToken derives the payment's: a guest's
 * order page links each of its documents with this HMAC of the document's id, keyed with the order's own secret, so
 * the page never writes the secret and a link opens that one document alone.
 *
 * @since  2.3.0
 */
final class DocumentToken
{
    /**
     * Derive the token for one document of an order.
     *
     * @param int $documentId The document the token is bound to.
     * @param string $secureKey The order's secure_key (HMAC key).
     *
     * @return string Hex sha256 HMAC.
     *
     * @since  2.3.0
     */
    public static function for(int $documentId, string $secureKey): string
    {
        return hash_hmac('sha256', 'alfa.document:' . $documentId, $secureKey);
    }

    /**
     * Constant-time check of a presented token. An empty secure key or an empty candidate never matches: a blank stored
     * key must not open a document.
     *
     * @param int $documentId The document the token claims.
     * @param string $secureKey The order's secure_key (HMAC key).
     * @param string $candidate The token presented.
     *
     * @since  2.3.0
     */
    public static function verify(int $documentId, string $secureKey, string $candidate): bool
    {
        if ($secureKey === '' || $candidate === '') {
            return false;
        }

        return hash_equals(self::for($documentId, $secureKey), $candidate);
    }
}
