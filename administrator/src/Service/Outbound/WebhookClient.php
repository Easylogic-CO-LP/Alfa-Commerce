<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Outbound;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * The POST of a webhook's request to the address the guard vetted, and to no other: the connection is pinned to the
 * vetted address (the host name still sent, so TLS checks the receiver's certificate against it), redirects are not
 * followed, the connection is given 5 seconds and the whole request 15.
 *
 * @since  2.3.0
 */
final class WebhookClient
{
    /**
     * Seconds to connect.
     *
     * @since  2.3.0
     */
    public const CONNECT_SECONDS = 5;

    /**
     * Seconds for the whole request.
     *
     * @since  2.3.0
     */
    public const TOTAL_SECONDS = 15;

    /**
     * POST a body.
     *
     * @param string $url The address.
     * @param array{host: string, port: int, ip: string} $vetted The address the guard vetted for it.
     * @param string $body The body.
     * @param array<string, string> $headers The headers by name.
     *
     * @return array{status: int, body: string, error: string, retryAfter: int|null} status 0 when no answer came.
     *
     * @throws RuntimeException When PHP has no curl.
     *
     * @since  2.3.0
     */
    public function post(string $url, array $vetted, string $body, array $headers): array
    {
        if (!\function_exists('curl_init')) {
            throw new RuntimeException('Webhooks need PHP\'s curl extension.');
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $retryAfter = null;
        $handle = curl_init($url);
        $https = str_starts_with(strtolower($url), 'https:');

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_SECONDS,
            CURLOPT_PROTOCOLS => $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$retryAfter): int {
                if (preg_match('/^Retry-After:\s*(\d{1,7})\s*$/i', $line, $match) === 1) {
                    $retryAfter = (int) $match[1];
                }

                return \strlen($line);
            },
        ]);

        // A host name is pinned to the address vetted for it; an address given as the host needs no name resolved.
        if (filter_var($vetted['host'], FILTER_VALIDATE_IP) === false) {
            curl_setopt($handle, CURLOPT_RESOLVE, [$vetted['host'] . ':' . $vetted['port'] . ':' . (str_contains($vetted['ip'], ':') ? '[' . $vetted['ip'] . ']' : $vetted['ip'])]);
        }

        $answer = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $answer === false ? curl_error($handle) : '';

        return [
            'status' => $answer === false ? 0 : $status,
            'body' => \is_string($answer) ? substr($answer, 0, 255) : '',
            'error' => $error,
            'retryAfter' => $retryAfter,
        ];
    }
}
