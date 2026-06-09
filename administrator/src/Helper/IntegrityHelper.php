<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Throwable;

/**
 * File-integrity verification for com_alfa — SINGLE SOURCE OF TRUTH: the signed
 * canonical checksums published on the CDN.
 *
 * The component carries no local baseline (no DB snapshot): every verdict comes
 * from fetching the signed `checksums-<version>.json` over HTTPS and verifying its
 * detached ed25519 signature against the bundled public keys, then diffing the live
 * core tree against the signed file set. Security rests on the private signing key
 * (off-box), not on hiding anything — the algorithm, URL and public keys are all
 * open by design.
 *
 * When the CDN can't be reached (offline / blocked / not yet published) the result
 * is `unreachable` — reported as a plainly NOT-SAFE state, never green, so a blocked
 * connection can't masquerade as healthy. The only defence against a fully-owned
 * server (which can neuter any on-box check) is an external off-box monitor.
 *
 * @since  1.0.0
 */
class IntegrityHelper
{
    /**
     * Base64 ed25519 PUBLIC keys trusted to sign the canonical CDN checksums. A
     * signature from ANY of these verifies, so a backup signer (holding their own
     * private key) can publish if the primary signer is unavailable — succession
     * with no shared secret. The matching PRIVATE keys live only in the vault / a
     * CI secret, NEVER here or on the server. Revoke a signer by removing its key
     * in a new release; add one by appending its public key.
     *
     * @var string[]
     * @since  1.0.0
     */
    private const TRUSTED_PUBLIC_KEYS = [
        'x6E27LNfUJucR6jI4GybPGBGQD7ZvVZ9s7/yc4ODyTU=', // primary
        'x+8RWDXGGwGpl4dljDCUpTG9J8sAzr3u1cnszTDAEoE=', // backup
    ];

    /**
     * Base URL of the signed canonical checksums on the CDN. HARDCODED on purpose
     * (not a config field) so a site admin or on-box attacker can't repoint it at a
     * fake source — and a fork that edits this const just self-defeats, since its
     * files still won't match your signed official reference. HTTPS only.
     *
     * @var string
     * @since  1.0.0
     */
    private const CDN_INTEGRITY_BASE = 'https://cdn.alfacommerce.gr/com_alfa/integrity/';

    /**
     * Component install roots walked to catch files that exist on disk but are NOT
     * in the official set ("added" / injected). The reverse walk is what surfaces a
     * dropped web shell — the expected-set loop alone never visits it.
     *
     * @var string[]
     * @since  1.0.0
     */
    private const CORE_ROOTS = [
        'components/com_alfa',
        'administrator/components/com_alfa',
        'api/components/com_alfa',
        'media/com_alfa',
    ];

    /**
     * Verify this install against the SIGNED canonical checksums on the CDN — the
     * authoritative "is this the official Easylogic release?" answer.
     *
     * Looks up `checksums-<siteVersion>.json` first; only on a miss falls back to
     * `checksums-latest.json`. Verifies the detached ed25519 signature over the EXACT
     * fetched bytes against the bundled public keys (so a forged or MITM'd file is
     * rejected — security rests on the key, not the URL), then compares the live core
     * tree to the signed file set:
     *   - modified : a listed file's bytes differ;
     *   - missing  : a listed file is gone (removed);
     *   - injected : a file exists in core that the official set does not list
     *                (reverse walk — catches dropped shells AND files injected into
     *                the distribution itself).
     *
     * Severity is context-aware: an EXACT-version match that differs is a real
     * deviation (alarm — injected especially); a LATEST fallback means the install is
     * AHEAD of the published catalog (developing/customised), so its differences are
     * expected and shown calmly. A fetch/parse/signature failure is reported as its
     * own NOT-SAFE state — never as "clean", never green. Nothing is ever disabled.
     *
     * @return array{
     *     status: string, fellBack?: bool, siteVersion?: string,
     *     officialVersion?: string, modified?: string[], missing?: string[],
     *     injected?: string[], reason?: string
     * } status ∈ official | modified | ahead | unreachable | bad_signature.
     *
     * @since  1.0.0
     */
    public static function verifyAgainstOfficial(): array
    {
        $verdict = self::computeVerdict();
        $verdict['checkedAt'] = time();

        return $verdict;
    }

    /**
     * Compute a fresh verdict — CDN fetch, detached-signature verify, then hash every
     * listed file. {@see self::verifyAgainstOfficial()} wraps this to stamp the time.
     *
     *
     * @since  1.0.0
     */
    private static function computeVerdict(): array
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return ['status' => 'unreachable', 'reason' => 'no_sodium'];
        }

        $siteVersion = self::manifestVersion();

        // Exact version first; only on a miss fall back to the "latest" pointer.
        $fellBack = false;
        $fetched = self::fetchOfficial(file: 'checksums-' . $siteVersion . '.json');

        if ($fetched === null) {
            $fetched = self::fetchOfficial(file: 'checksums-latest.json');
            $fellBack = true;
        }

        if ($fetched === null) {
            // Couldn't reach/read a reference — a NOT-SAFE network state, not a verdict.
            return ['status' => 'unreachable', 'siteVersion' => $siteVersion];
        }

        [$jsonBytes, $sigB64] = $fetched;

        // Verify the detached signature over the EXACT fetched bytes.
        $sigRaw = base64_decode(trim($sigB64), true);
        $sigOk = false;

        if ($sigRaw !== false && \strlen($sigRaw) === SODIUM_CRYPTO_SIGN_BYTES) {
            foreach (self::TRUSTED_PUBLIC_KEYS as $b64pk) {
                $pk = base64_decode($b64pk, true);

                if ($pk === false || \strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    continue;
                }

                if (sodium_crypto_sign_verify_detached($sigRaw, $jsonBytes, $pk)) {
                    $sigOk = true;
                    break;
                }
            }
        }

        if (!$sigOk) {
            // We fetched something, but it isn't signed by us → trust nothing in it.
            return ['status' => 'bad_signature', 'siteVersion' => $siteVersion, 'fellBack' => $fellBack];
        }

        $doc = json_decode($jsonBytes, true);
        $officialFiles = (\is_array($doc) && isset($doc['files']) && \is_array($doc['files'])) ? $doc['files'] : null;

        if ($officialFiles === null) {
            return ['status' => 'bad_signature', 'reason' => 'unparseable', 'siteVersion' => $siteVersion, 'fellBack' => $fellBack];
        }

        $root = JPATH_ROOT;
        $modified = [];
        $missing = [];

        foreach ($officialFiles as $rel => $fp) {
            $abs = $root . '/' . $rel;

            if (!is_file($abs)) {
                $missing[] = $rel;
            } elseif (hash_file('sha256', $abs) !== ($fp['h'] ?? '')) {
                $modified[] = $rel;
            }
        }

        $injected = self::collectInjected(root: $root, known: $officialFiles);

        $clean = ($modified === [] && $missing === [] && $injected === []);

        // Exact match → real deviation (alarm) vs clean; fallback → "ahead" (calm).
        $status = $fellBack
            ? ($clean ? 'official' : 'ahead')
            : ($clean ? 'official' : 'modified');

        return [
            'status' => $status,
            'fellBack' => $fellBack,
            'siteVersion' => $siteVersion,
            'officialVersion' => (string) ($doc['version'] ?? ''),
            'modified' => $modified,
            'missing' => $missing,
            'injected' => $injected,
        ];
    }

    /**
     * Cached wrapper around {@see self::verifyAgainstOfficial()} for the passive
     * surfaces (toolbar badge / notification panel). Uses Joomla's callback cache
     * (group `com_alfa.integrity`, 24h) exactly like CategoryHelper, so repeated
     * reads cost nothing and the real check (CDN fetch + hashing) runs at most once
     * per day — and only when something asks (it's fetched async after page load, so
     * even the refresh never blocks a page). The Security tab calls
     * verifyAgainstOfficial() directly for a FRESH result and then clears this cache.
     *
     * @return array Same shape as {@see self::verifyAgainstOfficial()}.
     *
     * @since  1.0.0
     */
    public static function cachedVerdict(): array
    {
        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.integrity',
                    'lifetime' => 1440, // 24h, in minutes
                    'caching' => true,
                ]);

            return (array) $cache->get([self::class, 'verifyAgainstOfficial'], [], 'verdict');
        } catch (Throwable) {
            // Cache unavailable → compute directly (correct, just uncached).
            return self::verifyAgainstOfficial();
        }
    }

    /**
     * Drop the cached verdict so the next {@see self::cachedVerdict()} recomputes.
     * Called after a fresh Security-tab check so the badge reflects the latest.
     *
     *
     * @since  1.0.0
     */
    public static function clearVerdictCache(): void
    {
        try {
            Factory::getCache('com_alfa.integrity', '')->clean();
        } catch (Throwable) {
            // Best-effort — a stale badge for up to the TTL is harmless.
        }
    }

    /**
     * Reverse-walk the component's core install roots and return every file present
     * on disk whose root-relative path is NOT a key in $known. This is the half that
     * surfaces injected files (web shells, tampered distributions) — the expected-set
     * loop alone never visits a path it doesn't already expect. {@see self::walk()}
     * applies a tight, name-based ignore (dotfiles/cruft) so genuine intruders can't
     * hide behind a broad rule.
     *
     * @param string $root Joomla installation root.
     * @param array<string, array{h:string,s:int,m:int}> $known The expected file set.
     *
     * @return string[] Root-relative paths present on disk but absent from $known.
     *
     * @since  1.0.0
     */
    private static function collectInjected(string $root, array $known): array
    {
        $found = [];

        foreach (self::CORE_ROOTS as $relRoot) {
            $absRoot = $root . '/' . $relRoot;

            if (is_dir($absRoot)) {
                self::walk(absDir: $absRoot, relDir: $relRoot, out: $found);
            }
        }

        return array_values(array_filter(
            $found,
            static fn (string $rel): bool => !\array_key_exists($rel, $known),
        ));
    }

    /**
     * Fetch a signed checksums file and its detached signature from the CDN over
     * HTTPS. Returns [jsonBytes, signatureBase64], or null on any failure (non-200,
     * empty body, network/timeout) — callers treat null as "could not verify".
     *
     * @param string $file The checksums filename (e.g. checksums-1.0.5.json).
     *
     * @return array{0: string, 1: string}|null
     *
     * @since  1.0.0
     */
    private static function fetchOfficial(string $file): ?array
    {
        $base = self::CDN_INTEGRITY_BASE;

        if (!str_starts_with($base, 'https://')) {
            return null; // HTTPS only — never trust an unauthenticated transport.
        }

        try {
            // J4/5: HttpFactory::getHttp() is an INSTANCE method — must not be called statically.
            $http = (new HttpFactory())->getHttp();

            $jsonRes = $http->get($base . $file, [], 10);

            if ((int) $jsonRes->code !== 200 || (string) $jsonRes->body === '') {
                return null;
            }

            $sigRes = $http->get($base . $file . '.sig', [], 10);

            if ((int) $sigRes->code !== 200 || (string) $sigRes->body === '') {
                return null;
            }

            return [(string) $jsonRes->body, (string) $sigRes->body];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read the current component version from the installed manifest.
     *
     * @return string The manifest <version>, or an empty string if unreadable.
     *
     * @since  1.0.0
     */
    private static function manifestVersion(): string
    {
        $manifestPath = JPATH_ADMINISTRATOR . '/components/com_alfa/alfa.xml';

        if (!is_file($manifestPath)) {
            return '';
        }

        $xml = @simplexml_load_file($manifestPath);

        if ($xml === false) {
            return '';
        }

        return trim((string) $xml->version);
    }

    /**
     * Recursively collect every file beneath an absolute directory, keyed by its
     * root-relative path. Skips '.', '..' and dotfiles/dotfolders at every level
     * (a tight, name-based ignore — never broad, so an intruder can't hide behind
     * an over-greedy rule). A missing directory is a silent no-op.
     *
     * @param string $absDir Absolute directory on the live install.
     * @param string $relDir Root-relative path of that directory (no leading slash).
     * @param string[] $out Accumulator of root-relative file paths (by ref).
     *
     * @since  1.0.0
     */
    private static function walk(string $absDir, string $relDir, array &$out): void
    {
        foreach ((array) scandir($absDir) as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $abs = $absDir . '/' . $entry;
            $rel = $relDir . '/' . $entry;

            if (is_dir($abs)) {
                self::walk(absDir: $abs, relDir: $rel, out: $out);
            } else {
                $out[] = $rel;
            }
        }
    }
}
