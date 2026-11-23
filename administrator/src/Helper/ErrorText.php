<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Factory;
use Throwable;

\defined('_JEXEC') or die;

/**
 * An exception's text made safe for a log line, a staff message or an answer a plugin logs: the one rule core's own
 * writers use and every extension uses the same way.
 *
 * A database driver's text names the site's database user, its host and its schema ("Access denied for user
 * 'shop'@'10.0.0.5'", "Table 'shop_db.jos_x' doesn't exist"), and a transport's text can carry a credential (an
 * address with a user and password, an Authorization header, a key sent as a parameter). The rule, applied in this
 * order:
 *
 *   1. the site's configured database user, host and schema (database name), each where it stands as a word of its
 *      own, become <user>, <host> and <schema>; the database password, the site's secret and the mail password
 *      become <secret> wherever they occur;
 *   2. any 'name'@'host' pair (the server's form for an account) becomes '<user>'@'<host>';
 *   3. the user and password of an address (scheme://user:password@host) become <user>:<secret>;
 *   4. a Bearer or Basic credential, and a value of four characters or more given to a password, passwd, pwd,
 *      secret, client_secret, api_key, apikey, access_token, refresh_token or token (name=value or name: value), become
 *      <secret> (the server's "using password: YES" stays as it is);
 *   5. control characters (line breaks included) become spaces, so one failure is one log line;
 *   6. the text is cut at 1,000 characters.
 *
 * Everything else is kept as it is, so an ordinary message reads the same. Outside a Joomla application (no site
 * configuration to read) rule 1 is not applied; the others are.
 *
 * Usage:
 *   } catch (\Throwable $e) {
 *       Log::add('Refund #' . $id . ' could not be recorded: ' . ErrorText::of($e), Log::ERROR, 'com_alfa.payments.mygateway');
 *   }
 *
 * @since  2.3.0
 */
final class ErrorText
{
    /**
     * The longest text answered, in characters, before the cut's mark.
     *
     * @since  2.3.0
     */
    public const LIMIT = 1000;

    /**
     * The site's configured values to replace, read once per request: [value => placeholder, word only].
     *
     * @var array<string, array{0: string, 1: bool}>|null
     */
    private static ?array $configured = null;

    /**
     * The text of a failure, or a text in hand, made safe by the rule above.
     *
     * @api READ ONLY: answers the cleaned text; writes and logs nothing, never throws.
     *
     * @param Throwable|string $failure The failure (its message is read), or a text.
     *
     * @return string The cleaned text, on one line, at most LIMIT characters and the cut's mark.
     *
     * @since  2.3.0
     */
    public static function of(Throwable|string $failure): string
    {
        $text = $failure instanceof Throwable ? $failure->getMessage() : $failure;

        foreach (self::configured() as $value => [$placeholder, $wordOnly]) {
            $text = $wordOnly
                ? (string) preg_replace('/(?<![\p{L}\p{N}_.-])' . preg_quote($value, '/') . '(?![\p{L}\p{N}_-])/u', $placeholder, $text)
                : str_replace($value, $placeholder, $text);
        }

        $text = (string) preg_replace("/'[^'\\s]*'@'[^'\\s]*'/u", "'<user>'@'<host>'", $text);
        $text = (string) preg_replace('#\b([a-z][a-z0-9+.-]*://)[^\s/:@]+:[^\s/@]+@#iu', '$1<user>:<secret>@', $text);
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/u', '$1 <secret>', $text);
        $text = (string) preg_replace(
            '/\b(password|passwd|pwd|client_secret|secret|api_key|apikey|access_token|refresh_token|token)(["\']?\s*[=:]\s*["\']?)[^\s"\'&,;)]{4,}/iu',
            '$1$2<secret>',
            $text,
        );
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

        return mb_strlen($text) > self::LIMIT ? mb_substr($text, 0, self::LIMIT) . '...' : $text;
    }

    /**
     * The site's configured database user, host, schema and secrets, longest first (so a value inside another is
     * replaced after it); empty and one-character values left out. Nothing outside a Joomla application.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private static function configured(): array
    {
        if (self::$configured !== null) {
            return self::$configured;
        }

        $values = [];

        try {
            $app = Factory::getApplication();
            $values = [
                [(string) $app->get('password'), '<secret>', false],
                [(string) $app->get('secret'), '<secret>', false],
                [(string) $app->get('smtppass'), '<secret>', false],
                [(string) $app->get('db'), '<schema>', true],
                [(string) $app->get('user'), '<user>', true],
                [(string) $app->get('host'), '<host>', true],
            ];
        } catch (Throwable) {
            // No application: no site configuration to read; the patterns still apply.
            $values = [];
        }

        $map = [];

        foreach ($values as [$value, $placeholder, $wordOnly]) {
            if (mb_strlen($value) > 1 && !isset($map[$value])) {
                $map[$value] = [$placeholder, $wordOnly];
            }
        }

        uksort($map, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return self::$configured = $map;
    }
}
