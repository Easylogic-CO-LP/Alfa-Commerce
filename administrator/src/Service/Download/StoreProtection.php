<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Download;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use RuntimeException;
use Throwable;

/**
 * Whether the download store refuses direct HTTP access, found out by asking the web server.
 *
 * Purchased files reach a buyer only through the download controller, which checks the grant,
 * the order and the buyer first. A store inside the public web root carries deny files
 * (`.htaccess`, `web.config`), but a web server that ignores them (nginx does) would hand any
 * object to anyone who has its path. So the protection is never assumed from the server's
 * name: a canary file with random content is put in the store and requested at its public URL
 * from the server side. The store is VERIFIED when the canary does not come back while a file
 * the package ships to the public media folder does (so the refusal is the store's, and the
 * request reached the site at all); EXPOSED when the canary's bytes come back; UNVERIFIED when
 * the request could not be made or the control file did not come back either. A store outside
 * the public web root has no URL and is not probed (OUTSIDE). Redirects are not followed: a
 * redirect answering either request may lead to another server, whose answer says nothing about
 * this one, so it leaves the store UNVERIFIED.
 *
 * The canary's name is random and is no storage key, so it can never be served or sold, and it
 * is deleted as soon as the answer is in. The result is recorded as `downloads_protection` in
 * `#__alfa_meta` (state, when, which URL, which store). The probe runs on four occasions only:
 * install, update, a change of the store's path, and "Check again".
 * A path change is seen by the first administrator page that resolves the store
 * ({@see self::current()}); nothing probes on a schedule or on other page loads, and never on a
 * storefront request. A result that is not verified stays a security warning on every Alfa
 * administrator page, the dashboard, the product's Downloads panel and the Options until a
 * later check verifies the store or its path moves outside the public web root. A result that
 * cannot be saved is never verified: it is kept for the request and said, and the next page,
 * finding nothing recorded, says the store is unchecked instead of probing again. The raw
 * reason of any failure goes to the downloads log, never into the message.
 *
 * Random extensionless object names stay a second line of defence only.
 *
 * Feature-specific to Downloads by design; a candidate for a shared environment and security
 * capability checker later.
 *
 * @since  2.0.0
 */
class StoreProtection
{
    /**
     * The `#__alfa_meta` row that holds the last result, as JSON.
     */
    public const META_RESULT = 'downloads_protection';

    /**
     * Seconds a probe request may take.
     */
    public const TIMEOUT = 5;

    /**
     * The control: a file the package ships to the public media folder, compared byte for byte.
     */
    public const CONTROL_FILE = 'media/com_alfa/images/placeholders/no-image.webp';

    public const STATE_VERIFIED = 'verified';

    public const STATE_EXPOSED = 'exposed';

    public const STATE_UNVERIFIED = 'unverified';

    public const STATE_OUTSIDE = 'outside';

    /**
     * This request's answers by store root, saved or not: a page asks at most once.
     *
     * @var array<string, object>
     */
    private static array $answers = [];

    /**
     * @param string|null $knownRoot The store root when the caller has settled it already (the
     *                               installer, before the Options are readable); null resolves it.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly ?string $knownRoot = null)
    {
    }

    /**
     * The last recorded result, or null when none is.
     *
     * @return object{state: string, root: string, url: string, checked_at: string, detail: string}|null
     *
     * @since  2.0.0
     */
    public function status(): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $json = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_RESULT)),
        )->loadResult();

        $result = \is_string($json) ? json_decode($json) : null;

        return \is_object($result) && isset($result->state, $result->root) ? $result : null;
    }

    /**
     * Administrator pages: the result for the store as it is now. A path that is not the one last
     * checked is a path change, and is checked; a store outside the public web root is answered
     * without a request. With nothing recorded for a store inside the web root (never checked, or
     * the result could not be saved) a page load is no reason to probe: the store is unverified
     * until Check again.
     *
     * @since  2.0.0
     */
    public function current(): object
    {
        $root = $this->root();
        $known = $this->remembered(root: $root);

        if ($known !== null) {
            return $known;
        }

        $result = $this->status();

        if ($result !== null && $result->root === $root) {
            $this->remember(result: $result);

            return $result;
        }

        if ($result !== null || $this->relativeTo(path: $root, base: $this->publicRoot()) === null) {
            return $this->check();
        }

        $unchecked = $this->blank(root: $root);
        $unchecked->detail = 'no check of this store is recorded (none ran, or its result could not be saved)';
        $this->remember(result: $unchecked);

        return $unchecked;
    }

    /**
     * Ask now, record the answer and return it.
     *
     * @since  2.0.0
     */
    public function check(): object
    {
        $result = $this->blank(root: $this->root());
        $this->ask(result: $result);

        try {
            $this->record(result: $result);
        } catch (Throwable $e) {
            // Unsaved, nothing would say the store was checked: never verified, and said. The
            // earlier result goes too: left behind, it would read as another path's on every page,
            // and every page would probe again.
            $this->log(what: 'the protection result could not be saved', error: $e);
            $this->forget();

            if ($result->state !== self::STATE_EXPOSED) {
                $result->state = self::STATE_UNVERIFIED;
            }

            $result->detail = ltrim($result->detail . '; the result could not be saved in the database', '; ');
        }

        $this->remember(result: $result);

        return $result;
    }

    /**
     * The probe: fills the result's state, URL and detail.
     *
     * @param object $result A result of {@see self::blank()}.
     *
     * @since  2.0.0
     */
    private function ask(object $result): void
    {
        $public = $this->publicRoot();
        $relative = $this->relativeTo(path: $result->root, base: $public);

        if ($relative === null) {
            $result->state = self::STATE_OUTSIDE;

            return;
        }

        try {
            $site = $this->siteUrl();
        } catch (Throwable $e) {
            $this->log(what: 'the site address could not be read', error: $e);
            $result->detail = 'the site address could not be read';

            return;
        }

        if ($site === null) {
            $result->detail = 'an install from the command line does not know the site address (live_site is empty)';

            return;
        }

        $name = 'probe-' . bin2hex(random_bytes(16));
        $canary = 'alfa-download-store-probe ' . bin2hex(random_bytes(32));
        $path = $result->root . '/' . $name;
        $result->url = $site . $this->urlPath(relative: $relative . '/' . $name);

        try {
            if ($this->writeCanary(path: $path, content: $canary) !== \strlen($canary)) {
                $result->detail = 'the probe file could not be written into the store';

                return;
            }

            $answer = $this->fetch(url: $result->url);

            if ($answer['code'] >= 300 && $answer['code'] < 400) {
                $result->detail = 'HTTP ' . $answer['code'] . ' for the probe file: a redirect is not followed, so it proves nothing';

                return;
            }

            if ($answer['body'] === $canary) {
                $result->state = self::STATE_EXPOSED;
                $result->detail = 'HTTP ' . $answer['code'] . ': the probe file came back';

                return;
            }

            $control = $this->fetch(url: $site . $this->urlPath(relative: self::CONTROL_FILE));
            $expected = @file_get_contents($public . '/' . self::CONTROL_FILE);

            if ($control['code'] === 200 && \is_string($expected) && $control['body'] === $expected) {
                $result->state = self::STATE_VERIFIED;
                $result->detail = 'HTTP ' . $answer['code'] . ' for the probe file; the public control file came back';

                return;
            }

            $result->detail = 'HTTP ' . $answer['code'] . ' for the probe file, but the public control file did not come back either (HTTP '
                . $control['code'] . ($control['code'] >= 300 && $control['code'] < 400 ? ', a redirect, not followed' : '')
                . '), so the refusal proves nothing';
        } catch (Throwable $e) {
            $this->log(what: 'the probe request failed (' . $result->url . ')', error: $e);
            $result->detail = 'the site did not answer at its own address';
        } finally {
            @unlink($path);
        }
    }

    /**
     * A result not yet asked: unverified, now.
     *
     * @param string $root Absolute store root.
     *
     * @since  2.0.0
     */
    private function blank(string $root): object
    {
        return (object) [
            'state' => self::STATE_UNVERIFIED,
            'root' => $root,
            'url' => '',
            'checked_at' => gmdate('Y-m-d H:i:s'),
            'detail' => '',
        ];
    }

    /**
     * The security warning for a result, or null when it needs none.
     *
     * @param object $result A result of {@see self::check()}.
     *
     * @since  2.0.0
     */
    public function warning(object $result): ?string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $location = $this->locationRule(root: (string) $result->root);

        if ($result->state === self::STATE_EXPOSED) {
            return Text::sprintf('COM_ALFA_DOWNLOADS_PROTECTION_EXPOSED', $e((string) $result->root), $e((string) $result->url), $e($result->checked_at . ' UTC'), $e($location));
        }

        if ($result->state === self::STATE_UNVERIFIED) {
            return Text::sprintf('COM_ALFA_DOWNLOADS_PROTECTION_UNVERIFIED', $e((string) $result->root), $e((string) $result->detail), $e($result->checked_at . ' UTC'), $e($location));
        }

        return null;
    }

    /**
     * The nginx rule that refuses the store, for the warning.
     *
     * @param string $root Absolute store root.
     *
     * @since  2.0.0
     */
    public function locationRule(string $root): string
    {
        $relative = $this->relativeTo(path: $root, base: $this->publicRoot());

        try {
            $base = rtrim((string) parse_url((string) $this->siteUrl(), PHP_URL_PATH), '/');
        } catch (Throwable) {
            $base = '';
        }

        return 'location ^~ ' . $base . '/' . ($relative ?? basename($root)) . '/ { deny all; }';
    }

    /**
     * The store root.
     *
     * @since  2.0.0
     */
    protected function root(): string
    {
        return $this->knownRoot ?? Storage::root();
    }

    /**
     * The public web root: where URLs start.
     *
     * @since  2.0.0
     */
    protected function publicRoot(): string
    {
        return rtrim(\defined('JPATH_PUBLIC') ? JPATH_PUBLIC : JPATH_ROOT, '/\\');
    }

    /**
     * The site's public address with a trailing slash, or null where it is not known. A
     * command-line run knows only live_site (and has no address without it).
     *
     * @since  2.0.0
     */
    protected function siteUrl(): ?string
    {
        $app = Factory::getApplication();

        if ($app->isClient('cli')) {
            $live = trim((string) $app->get('live_site', ''));

            return $live === '' ? null : rtrim($live, '/') . '/';
        }

        return rtrim(Uri::root(), '/') . '/';
    }

    /**
     * GET a URL from the server side. A redirect is returned as it is, never followed.
     *
     * @param string $url Absolute URL.
     *
     * @return array{code: int, body: string}
     *
     * @throws RuntimeException When no answer came back.
     *
     * @since  2.0.0
     */
    protected function fetch(string $url): array
    {
        $response = HttpFactory::getHttp(['follow_location' => false])->get($url, [], self::TIMEOUT);

        return ['code' => (int) $response->code, 'body' => (string) $response->body];
    }

    /**
     * Record a result.
     *
     * @param object $result A result of {@see self::check()}.
     *
     * @since  2.0.0
     */
    protected function record(object $result): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $exists = $db->setQuery(
            $db->createQuery()
                ->select('1')
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_RESULT)),
        )->loadResult();

        $query = $db->createQuery();

        if ($exists) {
            $query->update($db->quoteName('#__alfa_meta'))
                ->set($db->quoteName('value') . ' = ' . $db->quote($json))
                ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_RESULT));
        } else {
            $query->insert($db->quoteName('#__alfa_meta'))
                ->columns($db->quoteName(['name', 'value']))
                ->values($db->quote(self::META_RESULT) . ', ' . $db->quote($json));
        }

        $db->setQuery($query)->execute();
    }

    /**
     * Delete the recorded result (a DELETE still works where a too-long UPDATE does not).
     *
     * @since  2.0.0
     */
    protected function forget(): void
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                $db->createQuery()
                    ->delete($db->quoteName('#__alfa_meta'))
                    ->where($db->quoteName('name') . ' = ' . $db->quote(self::META_RESULT)),
            )->execute();
        } catch (Throwable $e) {
            $this->log(what: 'the earlier protection result could not be removed', error: $e);
        }
    }

    /**
     * Write the canary.
     *
     * @param string $path Absolute path.
     * @param string $content The canary's content.
     *
     * @return int|false Bytes written.
     *
     * @since  2.0.0
     */
    protected function writeCanary(string $path, string $content): int|false
    {
        return @file_put_contents($path, $content);
    }

    /**
     * This request's answer for a store root, or null.
     *
     * @param string $root Absolute store root.
     *
     * @since  2.0.0
     */
    protected function remembered(string $root): ?object
    {
        return self::$answers[$root] ?? null;
    }

    /**
     * Keep an answer for the rest of the request.
     *
     * @param object $result A result.
     *
     * @since  2.0.0
     */
    protected function remember(object $result): void
    {
        self::$answers[(string) $result->root] = $result;
    }

    /**
     * Put a failure's own words in the downloads log, where they belong.
     *
     * @param string $what What failed.
     * @param Throwable $error Why.
     *
     * @since  2.0.0
     */
    private function log(string $what, Throwable $error): void
    {
        Log::add(entry: 'Download store protection: ' . $what . ': ' . $error->getMessage(), priority: Log::WARNING, category: 'com_alfa.downloads');
    }

    /**
     * A path relative to a base it lies inside, or null when it lies outside.
     *
     * @param string $path Absolute path.
     * @param string $base Absolute base.
     *
     * @since  2.0.0
     */
    private function relativeTo(string $path, string $base): ?string
    {
        $path = rtrim((string) (realpath($path) ?: $path), '/\\');
        $base = rtrim((string) (realpath($base) ?: $base), '/\\');

        return str_starts_with($path, $base . '/') ? substr($path, \strlen($base) + 1) : null;
    }

    /**
     * A relative filesystem path as a URL path, each segment encoded.
     *
     * @param string $relative Relative path.
     *
     * @since  2.0.0
     */
    private function urlPath(string $relative): string
    {
        return implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $relative))));
    }
}
