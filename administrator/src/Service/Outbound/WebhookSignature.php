<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

/**
 * The signature of a webhook's request: HMAC-SHA256, keyed with the webhook's secret, over the moment the request was
 * signed (Unix seconds), a full stop and the body exactly as sent, in the header X-Alfa-Signature as
 * `t=<seconds>,v1=<64 hex digits>`. The receiver computes the same over the body as received, compares with a
 * constant-time comparison and refuses a moment older than {@see self::TOLERANCE_SECONDS}, so a request captured
 * on the way cannot be sent again later.
 *
 * @since  2.3.0
 */
final class WebhookSignature
{
    /**
     * The header that carries the signature.
     *
     * @since  2.3.0
     */
    public const HEADER = 'X-Alfa-Signature';

    /**
     * How old a signed moment a receiver takes, in seconds.
     *
     * @since  2.3.0
     */
    public const TOLERANCE_SECONDS = 300;

    /**
     * The header's value for a body signed at a moment.
     *
     * @param string $secret The webhook's secret.
     * @param int $timestamp The moment, Unix seconds.
     * @param string $body The body as sent.
     *
     * @since  2.3.0
     */
    public static function header(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Whether a request's signature is the shop's: what a receiver checks, written here once as the reference the
     * Manual gives.
     *
     * @param string $secret The webhook's secret.
     * @param string $header The X-Alfa-Signature header as received.
     * @param string $body The body as received.
     * @param int $now The receiver's clock, Unix seconds.
     *
     * @since  2.3.0
     */
    public static function verify(string $secret, string $header, string $body, int $now): bool
    {
        if (preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/D', trim($header), $match) !== 1) {
            return false;
        }

        $timestamp = (int) $match[1];

        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $match[2]);
    }

    /**
     * A new secret: 32 random bytes as 64 hex digits.
     *
     * @since  2.3.0
     */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
