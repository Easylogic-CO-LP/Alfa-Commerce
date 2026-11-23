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
 * The one check of an address the shop connects to on an outside party's word (an image's URL a REST API client
 * gives, a webhook's address staff enter): the host is resolved and every address it resolves to must be public and routable, never loopback, private,
 * link-local (a cloud's metadata address), unique-local, shared address space or reserved. The caller connects to the
 * address vetted here and to no other (the connection is pinned to it), so a DNS answer that changes between the
 * check and the connection cannot point inside; its transport follows no redirect, a redirect's address is vetted
 * here as a new one.
 *
 * @since  2.3.0
 */
final class OutboundAddressGuard
{
    /**
     * Whether every address a host resolves to is public (an address given as the host is judged as it is).
     *
     * @api READ ONLY: whether a host resolves, and only to public routable addresses.
     *
     * @param string $host The host.
     *
     * @since  2.3.0
     */
    public static function isPublicHost(string $host): bool
    {
        return self::publicAddresses(host: $host) !== [];
    }

    /**
     * The address to connect to for a URL: its scheme allowed, its host resolved and every address public.
     *
     * @api READ ONLY: vet an outside address before a connection; the answer names the one address to connect to.
     *
     * @param string $url The URL.
     * @param bool $httpsOnly Whether only https is taken; otherwise http and https.
     *
     * @return array{host: string, port: int, ip: string}
     *
     * @throws OutboundAddressRefused When the URL is refused, with the reason staff read.
     *
     * @since  2.3.0
     */
    public static function vet(string $url, bool $httpsOnly = true): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($parts === false || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundAddressRefused(reason: OutboundAddressRefused::NOT_URL);
        }

        if ($scheme !== 'https' && ($httpsOnly || $scheme !== 'http')) {
            throw new OutboundAddressRefused(reason: $httpsOnly ? OutboundAddressRefused::HTTPS_ONLY : OutboundAddressRefused::SCHEME);
        }

        $addresses = self::publicAddresses(host: $host);

        if ($addresses === []) {
            throw new OutboundAddressRefused(reason: OutboundAddressRefused::NOT_PUBLIC);
        }

        return ['host' => $host, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), 'ip' => $addresses[0]];
    }

    /**
     * The addresses a host resolves to, when every one is public; none when it does not resolve or any is not.
     *
     * @param string $host The host, or an address.
     *
     * @return string[]
     *
     * @since  2.3.0
     */
    private static function publicAddresses(string $host): array
    {
        $host = trim($host, '[]');

        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $addresses = [];

            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $addresses[] = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            }

            // The system resolver as well, which reads the host's own table of names (localhost among them).
            foreach (@gethostbynamel($host) ?: [] as $address) {
                $addresses[] = (string) $address;
            }

            $addresses = array_values(array_unique(array_filter($addresses)));
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false) {
                return [];
            }
        }

        return $addresses;
    }
}
