<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Media;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundAddressGuard;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundAddressRefused;
use Closure;
use finfo;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

/**
 * An image fetched from the address a REST API client gives (a media row {"type": "image", "url": "https://..."}),
 * into a file of this server's temporary folder, for the media upload path to store as it stores an upload.
 *
 * The guards, each before a byte is kept: http and https only; the host resolved and every address it resolves to
 * public, the connection made to the address vetted and no other, so a DNS answer that changes in between cannot point
 * inside (OutboundAddressGuard); a redirect followed only to an address vetted the same way, at most MAX_REDIRECTS of
 * them; the body cut off past the size Options, Images sets, and the whole fetch past its seconds; the file then judged
 * by its content, never by its name or the type the server states: an image of a type the shop takes, or nothing.
 *
 * @api READ ONLY: fetch an image from an outside address with the guards above; the answer is a temporary file the
 *      caller stores and removes.
 *
 * @since  2.3.0
 */
final class MediaFetch
{
    /**
     * The redirects followed, each to a vetted address.
     *
     * @since  2.3.0
     */
    public const MAX_REDIRECTS = 3;

    /**
     * The size Options, Images, Images by URL sets when it sets none, in MB.
     *
     * @since  2.3.0
     */
    public const DEFAULT_MAX_MB = 10;

    /**
     * The seconds a fetch may take when Options sets none.
     *
     * @since  2.3.0
     */
    public const DEFAULT_SECONDS = 15;

    /**
     * How an address is vetted: OutboundAddressGuard::vet() for http and https unless another check is given.
     *
     * @since  2.3.0
     */
    private readonly Closure $vet;

    /**
     * @param Closure|null $vet How an address is vetted, fn (string $url): array{host: string, port: int, ip: string},
     *                          throwing OutboundAddressRefused; null for OutboundAddressGuard::vet() with http allowed.
     * @param int|null $maxBytes The largest body taken; null for Options, Images, Images by URL.
     * @param int|null $seconds The seconds a fetch may take, redirects included; null for Options.
     *
     * @since  2.3.0
     */
    public function __construct(?Closure $vet = null, private ?int $maxBytes = null, private ?int $seconds = null)
    {
        $this->vet = $vet ?? static fn (string $url): array => OutboundAddressGuard::vet(url: $url, httpsOnly: false);
        $params = ComponentHelper::getParams('com_alfa');
        $this->maxBytes ??= max(1, (int) $params->get('media_fetch_max_size', self::DEFAULT_MAX_MB)) * 1024 * 1024;
        $this->seconds ??= max(1, (int) $params->get('media_fetch_timeout', self::DEFAULT_SECONDS));
    }

    /**
     * The sha256 of an address, as a media row keeps it beside the address (source_url_hash): the same address is not
     * fetched twice for one record.
     *
     * @param string $url The address.
     *
     * @since  2.3.0
     */
    public static function urlHash(string $url): string
    {
        return hash('sha256', trim($url));
    }

    /**
     * Fetch the image at an address.
     *
     * @param string $url The address.
     * @param string[] $allowedMimes The image types the shop takes (Options, Images, Allowed Image Types).
     *
     * @return array{file: string, name: string, mime: string} The temporary file (the caller removes it), the name the
     *                                                         address gives the image, its type read from its content.
     *
     * @throws MediaFetchRefused When the address, a redirect, the answer or the file is refused.
     *
     * @since  2.3.0
     */
    public function fetch(string $url, array $allowedMimes): array
    {
        if (!\function_exists('curl_init')) {
            throw new MediaFetchRefused(MediaFetchRefused::UNAVAILABLE);
        }

        $deadline = microtime(true) + (int) $this->seconds;
        $file = (string) tempnam((string) Factory::getApplication()->get('tmp_path', sys_get_temp_dir()), 'fetch-');
        $current = $url;

        try {
            for ($hop = 0; ; $hop++) {
                $left = $deadline - microtime(true);

                if ($left <= 0) {
                    throw new MediaFetchRefused(MediaFetchRefused::TIMEOUT, (int) $this->seconds);
                }

                [$status, $location] = $this->get(url: $current, file: $file, seconds: $left);

                if ($status >= 300 && $status < 400 && $location !== '') {
                    if ($hop >= self::MAX_REDIRECTS) {
                        throw new MediaFetchRefused(MediaFetchRefused::REDIRECTS, self::MAX_REDIRECTS);
                    }

                    $current = self::resolved(base: $current, location: $location);

                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    throw new MediaFetchRefused(MediaFetchRefused::STATUS, $status);
                }

                break;
            }

            $mime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($file));

            if (!\in_array($mime, array_map('strtolower', $allowedMimes), true)) {
                throw new MediaFetchRefused(MediaFetchRefused::TYPE, $mime);
            }

            return ['file' => $file, 'name' => self::nameOf(url: $current), 'mime' => $mime];
        } catch (MediaFetchRefused $refused) {
            @unlink($file);

            throw $refused;
        }
    }

    /**
     * One request: the address vetted, the connection pinned to the vetted address, the body written to the file and
     * cut off past the size allowed, no redirect followed by the transport.
     *
     * @param string $url The address.
     * @param string $file The temporary file, emptied first.
     * @param float $seconds The seconds left.
     *
     * @return array{0: int, 1: string} The status and, for a redirect, its Location.
     *
     * @throws MediaFetchRefused When the address is refused, the transfer fails, or the body is too large.
     *
     * @since  2.3.0
     */
    private function get(string $url, string $file, float $seconds): array
    {
        try {
            $vetted = ($this->vet)($url);
        } catch (OutboundAddressRefused $e) {
            throw new MediaFetchRefused(match ($e->reason) {
                OutboundAddressRefused::SCHEME => MediaFetchRefused::SCHEME,
                OutboundAddressRefused::NOT_URL => MediaFetchRefused::NOT_URL,
                default => MediaFetchRefused::NOT_PUBLIC,
            });
        }

        $out = fopen($file, 'wb');

        if ($out === false) {
            throw new MediaFetchRefused(MediaFetchRefused::UNAVAILABLE);
        }

        $location = '';
        $limit = (int) $this->maxBytes;
        $tooLarge = false;
        $https = str_starts_with(strtolower($url), 'https:');
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(1, min($seconds, 10) * 1000),
            CURLOPT_TIMEOUT_MS => (int) max(1, $seconds * 1000),
            CURLOPT_PROTOCOLS => $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Alfa Commerce image fetch',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location): int {
                if (preg_match('/^Location:\s*(\S+)\s*$/i', $line, $match) === 1) {
                    $location = $match[1];
                }

                return \strlen($line);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($curl, $expected, $received) use ($limit, &$tooLarge): int {
                // A body larger than allowed is cut off as it arrives, whatever size the server announced.
                if ($expected > $limit || $received > $limit) {
                    $tooLarge = true;

                    return 1;
                }

                return 0;
            },
        ]);

        // A host name is pinned to the address vetted for it; an address given as the host needs no name resolved.
        if (filter_var($vetted['host'], FILTER_VALIDATE_IP) === false) {
            curl_setopt($handle, CURLOPT_RESOLVE, [$vetted['host'] . ':' . $vetted['port'] . ':' . (str_contains($vetted['ip'], ':') ? '[' . $vetted['ip'] . ']' : $vetted['ip'])]);
        }

        $done = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        fclose($out);

        if ($tooLarge) {
            throw new MediaFetchRefused(MediaFetchRefused::TOO_LARGE, (int) round($limit / 1048576));
        }

        if ($done === false) {
            throw $errno === CURLE_OPERATION_TIMEDOUT
                ? new MediaFetchRefused(MediaFetchRefused::TIMEOUT, (int) $this->seconds)
                : new MediaFetchRefused(MediaFetchRefused::TRANSFER);
        }

        return [$status, $location];
    }

    /**
     * A redirect's Location as an absolute address: an absolute one as it is, a path against the address that
     * answered.
     *
     * @param string $base The address that answered.
     * @param string $location Its Location.
     *
     * @since  2.3.0
     */
    private static function resolved(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'http') . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = (string) ($parts['path'] ?? '/');

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    /**
     * The name an address gives its image: the last segment of its path, or "image" when it has none.
     *
     * @param string $url The address.
     *
     * @since  2.3.0
     */
    private static function nameOf(string $url): string
    {
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return trim($name) !== '' ? $name : 'image';
    }
}
