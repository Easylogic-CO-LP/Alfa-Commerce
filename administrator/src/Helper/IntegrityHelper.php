<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Setup\AlfaVersion;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\Http\HttpFactory;
use Joomla\Http\Transport\Curl as CurlTransport;
use Throwable;

/**
 * File-integrity verification for com_alfa. SINGLE SOURCE OF TRUTH: the signed
 * canonical checksums published on the CDN.
 *
 * The component carries no local baseline (no DB snapshot): every verdict comes
 * from fetching the signed `checksums-<version>.json` over HTTPS and verifying its
 * detached ed25519 signature against the bundled public keys, then diffing the live
 * shipped tree against the signed file set. Files are compared with
 * {@see PackageHelper::contentHash()}, the method the catalog declares, so a
 * whitespace-only change (line endings, indentation) is not a modification.
 * Security rests on the private signing key (off-box), not on hiding anything:
 * the algorithm, URL and public keys are all open by design.
 *
 * When the CDN can't be reached (offline / blocked / not yet published) the result
 * is `unreachable`, reported as a plainly NOT-SAFE state, never green, so a blocked
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
     * private key) can publish if the primary signer is unavailable: succession
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
     * fake source, and a fork that edits this const just self-defeats, since its
     * files still won't match your signed official reference. HTTPS only.
     *
     * @var string
     * @since  1.0.0
     */
    private const CDN_INTEGRITY_BASE = 'https://cdn.alfacommerce.gr/com_alfa/integrity/';

    /**
     * Patterns that map a catalog path to the extension folder it lives in. The
     * reverse walk visits exactly these folders (the component roots and every
     * shipped plugin, module and media folder the catalog claims) to surface files
     * that exist on disk but are NOT in the official set (a dropped web shell). Shared
     * trees (language/, libraries/) never match, so other extensions' files are never
     * reported.
     *
     * @var string[]
     * @since  2.0.0
     */
    private const EXTENSION_ROOT_PATTERNS = [
        '#^(components/[^/]+)/#',
        '#^(administrator/components/[^/]+)/#',
        '#^(api/components/[^/]+)/#',
        '#^(media/[^/]+)/#',
        '#^(modules/[^/]+)/#',
        '#^(plugins/[^/]+/[^/]+)/#',
    ];

    /**
     * Desktop clutter the reverse walk ignores, by exact name: .DS_Store (macOS Finder), Thumbs.db
     * and desktop.ini (Windows Explorer); and the ._<name> files macOS writes beside copied
     * files (AppleDouble). They hold no code. Every other file is walked, hidden ones included:
     * .htaccess and .user.ini change how the web server and PHP run the folder.
     *
     * @var string[]
     * @since  2.1.1
     */
    private const CLUTTER = ['.ds_store', 'thumbs.db', 'desktop.ini'];

    /**
     * The #__alfa_meta fact holding the counts of the verdict the Security notification was
     * last set from, as JSON. The tamper banner reads it beside the notification's severity.
     *
     * @var string
     * @since  2.1.1
     */
    public const SUMMARY_META = 'integrity_verdict';

    /**
     * The tamper banner, character-encoded so a plaintext search for its wording does not
     * surface it, and kept out of the language files so a language override cannot silence
     * it. Its lead sentence, then the sentence for changed or missing files, then the ends of
     * the sentence for extra files only (after their number: one file, several files).
     *
     * @var string
     * @since  2.1.1
     */
    private const ALARM_LEAD = "\x3c\x73\x74\x72\x6f\x6e\x67\x3e\x53\x65\x63\x75\x72\x69\x74\x79\x20\x63\x6f\x6e\x63\x65\x72\x6e\x3a\x3c\x2f\x73\x74\x72\x6f\x6e\x67\x3e\x20\x74\x68\x69\x73\x20\x41\x6c\x66\x61\x20\x43\x6f\x6d\x6d\x65\x72\x63\x65\x20\x69\x6e\x73\x74\x61\x6c\x6c\x61\x74\x69\x6f\x6e\x20\x64\x6f\x65\x73\x20\x6e\x6f\x74\x20\x6d\x61\x74\x63\x68\x20\x74\x68\x65\x20\x73\x69\x67\x6e\x65\x64\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x72\x65\x6c\x65\x61\x73\x65\x2e\x20";

    /** @since  2.1.1 */
    private const ALARM_CHANGED = "\x49\x74\x73\x20\x66\x69\x6c\x65\x73\x20\x68\x61\x76\x65\x20\x62\x65\x65\x6e\x20\x63\x68\x61\x6e\x67\x65\x64\x2e";

    /** @since  2.1.1 */
    private const ALARM_EXTRA_ONE = "\x20\x65\x78\x74\x72\x61\x20\x66\x69\x6c\x65\x20\x6e\x6f\x74\x20\x69\x6e\x20\x74\x68\x65\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x72\x65\x6c\x65\x61\x73\x65\x2e";

    /**
     * The whole banner when the official checksums could not be verified (bad signature) or could not be
     * fetched: then nothing is known about the files, so it says what happened instead.
     *
     * @var string
     * @since  2.1.1
     */
    private const ALARM_UNVERIFIED = "\x3c\x73\x74\x72\x6f\x6e\x67\x3e\x53\x65\x63\x75\x72\x69\x74\x79\x20\x63\x6f\x6e\x63\x65\x72\x6e\x3a\x3c\x2f\x73\x74\x72\x6f\x6e\x67\x3e\x20\x74\x68\x69\x73\x20\x41\x6c\x66\x61\x20\x43\x6f\x6d\x6d\x65\x72\x63\x65\x20\x69\x6e\x73\x74\x61\x6c\x6c\x61\x74\x69\x6f\x6e\x20\x63\x6f\x75\x6c\x64\x20\x6e\x6f\x74\x20\x62\x65\x20\x63\x68\x65\x63\x6b\x65\x64\x20\x61\x67\x61\x69\x6e\x73\x74\x20\x74\x68\x65\x20\x73\x69\x67\x6e\x65\x64\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x72\x65\x6c\x65\x61\x73\x65\x3a\x20\x74\x68\x65\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x63\x68\x65\x63\x6b\x73\x75\x6d\x73\x20\x63\x6f\x75\x6c\x64\x20\x6e\x6f\x74\x20\x62\x65\x20\x76\x65\x72\x69\x66\x69\x65\x64\x2e";

    /** @since  2.1.1 */
    private const ALARM_UNFETCHED = "\x3c\x73\x74\x72\x6f\x6e\x67\x3e\x53\x65\x63\x75\x72\x69\x74\x79\x20\x63\x6f\x6e\x63\x65\x72\x6e\x3a\x3c\x2f\x73\x74\x72\x6f\x6e\x67\x3e\x20\x74\x68\x69\x73\x20\x41\x6c\x66\x61\x20\x43\x6f\x6d\x6d\x65\x72\x63\x65\x20\x69\x6e\x73\x74\x61\x6c\x6c\x61\x74\x69\x6f\x6e\x20\x63\x6f\x75\x6c\x64\x20\x6e\x6f\x74\x20\x62\x65\x20\x63\x68\x65\x63\x6b\x65\x64\x20\x61\x67\x61\x69\x6e\x73\x74\x20\x74\x68\x65\x20\x73\x69\x67\x6e\x65\x64\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x72\x65\x6c\x65\x61\x73\x65\x3a\x20\x74\x68\x65\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x63\x68\x65\x63\x6b\x73\x75\x6d\x73\x20\x63\x6f\x75\x6c\x64\x20\x6e\x6f\x74\x20\x62\x65\x20\x66\x65\x74\x63\x68\x65\x64\x2e";

    /** @since  2.1.1 */
    private const ALARM_EXTRA_MANY = "\x20\x65\x78\x74\x72\x61\x20\x66\x69\x6c\x65\x73\x20\x6e\x6f\x74\x20\x69\x6e\x20\x74\x68\x65\x20\x6f\x66\x66\x69\x63\x69\x61\x6c\x20\x72\x65\x6c\x65\x61\x73\x65\x2e";

    /**
     * Verify this install against the SIGNED canonical checksums on the CDN: the
     * authoritative "is this the official Easylogic release?" answer.
     *
     * Looks up `checksums-<siteVersion>.json` first; only on a miss falls back to
     * `checksums-latest.json`. Verifies the detached ed25519 signature over the EXACT
     * fetched bytes against the bundled public keys (so a forged or MITM'd file is
     * rejected; security rests on the key, not the URL), then judges the tree with
     * {@see self::verdictFromCatalog()}. A fetch/parse/signature failure is reported
     * as its own NOT-SAFE state, never as "clean", never green. Nothing is ever
     * disabled.
     *
     * @return array{
     *     status: string, fellBack?: bool, siteVersion?: string,
     *     officialVersion?: string, modified?: string[], missing?: string[],
     *     injected?: string[], reason?: string, checkedAt: int
     * } status is one of official | modified | ahead | unreachable | bad_signature.
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
     * Compute a fresh verdict: CDN fetch, detached-signature verify, then the tree
     * comparison. {@see self::verifyAgainstOfficial()} wraps this to stamp the time.
     *
     * @return array Same shape as {@see self::verifyAgainstOfficial()}, without `checkedAt`.
     *
     * @since  1.0.0
     */
    private static function computeVerdict(): array
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return ['status' => 'unreachable', 'reason' => 'no_sodium'];
        }

        $siteVersion = AlfaVersion::installed()?->toString() ?? '';

        // Exact version first; only on a miss fall back to the "latest" pointer.
        $fellBack = false;
        $fetched = self::fetchOfficial(file: 'checksums-' . $siteVersion . '.json');

        if ($fetched === null) {
            $fetched = self::fetchOfficial(file: 'checksums-latest.json');
            $fellBack = true;
        }

        if ($fetched === null) {
            // Couldn't reach/read a reference: a NOT-SAFE network state, not a verdict.
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
            // We fetched something, but it isn't signed by us -> trust nothing in it.
            return ['status' => 'bad_signature', 'siteVersion' => $siteVersion, 'fellBack' => $fellBack];
        }

        $doc = json_decode($jsonBytes, true);

        return self::verdictFromCatalog(
            doc: \is_array($doc) ? $doc : [],
            fellBack: $fellBack,
            siteVersion: $siteVersion,
            root: JPATH_ROOT,
        );
    }

    /**
     * Judge an install against an already-authenticated catalog document. Split from
     * the fetch so the judgement can be exercised offline against a synthetic tree.
     *
     * The catalog's `hash` field names the method its digests were computed with and
     * must equal {@see PackageHelper::HASH_METHOD}; a catalog without it, with another
     * method, or without a `files` map is reported as `bad_signature` with a `reason`
     * (`unknown_hash` / `unparseable`): fail closed, never green.
     *
     * Severity is context-aware: an EXACT-version catalog that differs is a real
     * deviation (`modified`); a LATEST fallback means the install is ahead of the
     * published catalog, so its differences are expected (`ahead`).
     *
     * @param array $doc The decoded catalog: version, generated, hash, files.
     * @param bool $fellBack Whether the catalog is the `latest` pointer rather than the site's own version.
     * @param string $siteVersion The installed manifest version.
     * @param string $root Installation root to compare, no trailing slash.
     *
     * @return array Same shape as {@see self::verifyAgainstOfficial()}, without `checkedAt`.
     *
     * @since  2.0.0
     */
    public static function verdictFromCatalog(array $doc, bool $fellBack, string $siteVersion, string $root): array
    {
        $files = $doc['files'] ?? null;

        if (!\is_array($files)) {
            return ['status' => 'bad_signature', 'reason' => 'unparseable', 'siteVersion' => $siteVersion, 'fellBack' => $fellBack];
        }

        if (($doc['hash'] ?? null) !== PackageHelper::HASH_METHOD) {
            return ['status' => 'bad_signature', 'reason' => 'unknown_hash', 'siteVersion' => $siteVersion, 'fellBack' => $fellBack];
        }

        $diff = self::compareTree(files: $files, root: $root);
        $clean = ($diff['modified'] === [] && $diff['missing'] === [] && $diff['injected'] === []);

        // Exact match -> real deviation (alarm) vs clean; fallback -> "ahead" (calm).
        $status = $fellBack
            ? ($clean ? 'official' : 'ahead')
            : ($clean ? 'official' : 'modified');

        return [
            'status' => $status,
            'fellBack' => $fellBack,
            'siteVersion' => $siteVersion,
            'officialVersion' => (string) ($doc['version'] ?? ''),
        ] + $diff;
    }

    /**
     * Compare a catalog's file map against a tree on disk:
     *   - modified : a listed file's content hash differs;
     *   - missing  : a listed file is gone (removed);
     *   - injected : a file exists inside a shipped extension folder that the catalog
     *                does not list (reverse walk: catches dropped shells AND files
     *                injected into the distribution itself).
     *
     * @param array<string, array{h: string}> $files Root-relative path => {h: content hash}.
     * @param string $root Installation root, no trailing slash.
     *
     * @return array{modified: string[], missing: string[], injected: string[]}
     *
     * @since  2.0.0
     */
    public static function compareTree(array $files, string $root): array
    {
        $modified = [];
        $missing = [];

        foreach ($files as $rel => $entry) {
            $abs = $root . '/' . $rel;

            if (!is_file($abs)) {
                $missing[] = $rel;
            } elseif (PackageHelper::contentHash(path: $abs) !== (string) ($entry['h'] ?? '')) {
                $modified[] = $rel;
            }
        }

        return [
            'modified' => $modified,
            'missing' => $missing,
            'injected' => self::collectInjected(root: $root, known: $files),
        ];
    }

    /**
     * The extension folders a catalog claims: every distinct match of
     * {@see self::EXTENSION_ROOT_PATTERNS} over its paths, sorted. These, and only
     * these, are reverse-walked for injected files.
     *
     * @param array<string, mixed> $files Catalog file map, root-relative paths as keys.
     *
     * @return string[] Root-relative folders, no leading or trailing slash.
     *
     * @since  2.0.0
     */
    public static function walkRoots(array $files): array
    {
        $roots = [];

        foreach (array_keys($files) as $rel) {
            foreach (self::EXTENSION_ROOT_PATTERNS as $pattern) {
                if (preg_match($pattern, (string) $rel, $match)) {
                    $roots[$match[1]] = true;
                    break;
                }
            }
        }

        $roots = array_keys($roots);
        sort($roots);

        return $roots;
    }

    /**
     * Cached wrapper around {@see self::verifyAgainstOfficial()} for the passive
     * surfaces (toolbar badge / notification panel). Uses Joomla's callback cache
     * (group `com_alfa.integrity`, 24h) exactly like CategoryHelper, so repeated
     * reads cost nothing and the real check (CDN fetch + hashing) runs at most once
     * per day, and only when something asks (it's fetched async after page load, so
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
            // Cache unavailable -> compute directly (correct, just uncached).
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
            // Via the controller factory: Factory::getCache($group, '') throws on the
            // empty handler (no such controller class), so this method was silently
            // never cleaning anything.
            Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => 'com_alfa.integrity'])
                ->cache->clean();
        } catch (Throwable) {
            // Best-effort: a stale badge for up to the TTL is harmless.
        }
    }

    /**
     * Reverse-walk every extension folder the catalog claims and return each file
     * present on disk whose root-relative path is NOT a key in $known. This is the
     * half that surfaces injected files: the expected-set loop alone never visits a
     * path it doesn't already expect. {@see self::walk()} reports hidden files too and
     * ignores only desktop clutter by exact name ({@see self::isClutter()}), so genuine
     * intruders can't hide behind a broad rule.
     *
     * @param string $root Installation root, no trailing slash.
     * @param array<string, mixed> $known The expected file set, root-relative paths as keys.
     *
     * @return string[] Root-relative paths present on disk but absent from $known.
     *
     * @since  1.0.0
     */
    private static function collectInjected(string $root, array $known): array
    {
        $found = [];

        foreach (self::walkRoots(files: $known) as $relRoot) {
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
     * Whether a file name is desktop clutter the reverse walk ignores ({@see self::CLUTTER},
     * case-insensitive, and the AppleDouble ._<name>). A name with a segment a web server may
     * execute is never clutter: ._shell.php runs as a script wherever .php does.
     *
     * @param string $name A file name, without its folder.
     *
     * @since  2.1.1
     */
    public static function isClutter(string $name): bool
    {
        $lower = strtolower($name);

        if (!\in_array($lower, self::CLUTTER, true) && !str_starts_with($name, '._')) {
            return false;
        }

        return array_intersect(explode('.', $lower), InputFilter::FORBIDDEN_FILE_EXTENSIONS) === [];
    }

    /**
     * The counts of a verdict that the tamper banner needs: its status and how many files
     * are modified, missing and injected.
     *
     * @param array $verdict A verdict from {@see self::verifyAgainstOfficial()}.
     *
     * @return array{status: string, modified: int, missing: int, injected: int}
     *
     * @since  2.1.1
     */
    public static function summarise(array $verdict): array
    {
        return [
            'status' => (string) ($verdict['status'] ?? 'unreachable'),
            'modified' => \count((array) ($verdict['modified'] ?? [])),
            'missing' => \count((array) ($verdict['missing'] ?? [])),
            'injected' => \count((array) ($verdict['injected'] ?? [])),
        ];
    }

    /**
     * Record a verdict's counts ({@see self::summarise()}) as the {@see self::SUMMARY_META}
     * fact, written only when it changed. Best effort: a failure leaves the earlier counts,
     * and the banner then says what it said before.
     *
     * @param array $verdict The verdict the Security notification is being set from.
     *
     * @since  2.1.1
     */
    public static function recordSummary(array $verdict): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $json = json_encode(self::summarise(verdict: $verdict), JSON_THROW_ON_ERROR);
            $stored = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('value'))
                    ->from($db->quoteName('#__alfa_meta'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote(self::SUMMARY_META)),
            )->loadResult();

            if ($stored === $json) {
                return;
            }

            $query = $db->createQuery();

            if ($stored === null) {
                $query->insert($db->quoteName('#__alfa_meta'))
                    ->columns($db->quoteName(['name', 'value']))
                    ->values($db->quote(self::SUMMARY_META) . ', ' . $db->quote($json));
            } else {
                $query->update($db->quoteName('#__alfa_meta'))
                    ->set($db->quoteName('value') . ' = ' . $db->quote($json))
                    ->where($db->quoteName('name') . ' = ' . $db->quote(self::SUMMARY_META));
            }

            $db->setQuery($query)->execute();
        } catch (Throwable) {
            // The counts only shape the banner's wording; the verdict itself is unaffected.
        }
    }

    /**
     * The counts last recorded by {@see self::recordSummary()}, or null when none are.
     * One indexed read, for the tamper banner.
     *
     * @return array{status: string, modified: int, missing: int, injected: int}|null
     *
     * @since  2.1.1
     */
    public static function recordedSummary(): ?array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $json = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('value'))
                    ->from($db->quoteName('#__alfa_meta'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote(self::SUMMARY_META)),
            )->loadResult();
            $summary = \is_string($json) ? json_decode($json, true) : null;
        } catch (Throwable) {
            return null;
        }

        return \is_array($summary) && isset($summary['status'], $summary['modified'], $summary['missing'], $summary['injected'])
            ? $summary
            : null;
    }

    /**
     * The tamper banner's text for the recorded counts. When the official checksums could not
     * be verified or fetched it says so (nothing is known about the files then); when nothing
     * is modified or missing and only extra files were found, it names how many; otherwise,
     * and when no counts are recorded, it says the files have been changed.
     *
     * @param array|null $summary Counts from {@see self::recordedSummary()}.
     *
     * @since  2.1.1
     */
    public static function alarmText(?array $summary): string
    {
        $status = (string) ($summary['status'] ?? '');

        if ($status === 'bad_signature') {
            return self::ALARM_UNVERIFIED;
        }

        if ($status === 'unreachable') {
            return self::ALARM_UNFETCHED;
        }

        $extra = (int) ($summary['injected'] ?? 0);

        if (
            $status === 'modified'
            && (int) ($summary['modified'] ?? 0) === 0
            && (int) ($summary['missing'] ?? 0) === 0
            && $extra > 0
        ) {
            return self::ALARM_LEAD . $extra . ($extra === 1 ? self::ALARM_EXTRA_ONE : self::ALARM_EXTRA_MANY);
        }

        return self::ALARM_LEAD . self::ALARM_CHANGED;
    }

    /**
     * Fetch a signed checksums file and its detached signature from the CDN over
     * HTTPS. Returns [jsonBytes, signatureBase64], or null on any failure (non-200,
     * empty body, network/timeout); callers treat null as "could not verify".
     *
     * The catalog compresses about four to one and the CDN negotiates gzip, so it is
     * requested compressed, but only through the framework's curl transport, which
     * decodes the body itself. The stream and socket transports would hand back the
     * gzip bytes, which the signature check must never see.
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
            return null; // HTTPS only: never trust an unauthenticated transport.
        }

        try {
            $factory = new HttpFactory();
            $http = $factory->getHttp(['userAgent' => (new Version())->getUserAgent('Joomla', true, false)]);
            $headers = $factory->getAvailableDriver() instanceof CurlTransport ? ['Accept-Encoding' => 'gzip'] : [];

            $jsonRes = $http->get($base . $file, $headers, 10);
            $json = (string) $jsonRes->getBody();

            if ($jsonRes->getStatusCode() !== 200 || $json === '') {
                return null;
            }

            $sigRes = $http->get($base . $file . '.sig', [], 10);
            $sig = (string) $sigRes->getBody();

            if ($sigRes->getStatusCode() !== 200 || $sig === '') {
                return null;
            }

            return [$json, $sig];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recursively collect every file beneath an absolute directory, keyed by its
     * root-relative path, hidden files and folders included (.htaccess, .user.ini, a
     * dot-named folder). Skips only '.', '..' and desktop clutter ({@see self::isClutter()}):
     * a tight, name-based ignore, never broad, so an intruder can't hide behind an
     * over-greedy rule. A missing directory is a silent no-op.
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
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $abs = $absDir . '/' . $entry;
            $rel = $relDir . '/' . $entry;

            if (is_dir($abs)) {
                self::walk(absDir: $abs, relDir: $rel, out: $out);
            } elseif (!self::isClutter(name: $entry)) {
                $out[] = $rel;
            }
        }
    }
}
