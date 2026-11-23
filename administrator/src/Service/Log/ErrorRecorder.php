<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Log;

use Exception;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Error\JsonApi\AuthenticationFailedExceptionHandler;
use Joomla\CMS\Error\JsonApi\CheckinCheckoutExceptionHandler;
use Joomla\CMS\Error\JsonApi\InvalidParameterExceptionHandler;
use Joomla\CMS\Error\JsonApi\InvalidRouteExceptionHandler;
use Joomla\CMS\Error\JsonApi\NotAcceptableExceptionHandler;
use Joomla\CMS\Error\JsonApi\NotAllowedExceptionHandler;
use Joomla\CMS\Error\JsonApi\ResourceNotFoundExceptionHandler;
use Joomla\CMS\Error\JsonApi\SaveExceptionHandler;
use Joomla\CMS\Error\JsonApi\SendEmailExceptionHandler;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use mysqli_sql_exception;
use PDOException;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The record of a request that ended in a server error (an uncaught throwable that is not a 4xx).
 *
 * One line per error in administrator/logs/com_alfa_errors.php (category com_alfa.errors), written through Joomla's
 * log: its date, its priority and the client's address are Joomla's own columns; the line itself carries a reference
 * (16 hexadecimal characters), the client (site, administrator or api), the method and the address asked for (a
 * parameter that looks like a token, a key or a password shown as ***), the referrer's host, the user agent, a hash of
 * the session id (never the id), the user id, and each throwable of the chain: its class, its code, the database's
 * error number and SQLSTATE when the database refused, its message with the database's user, host, schema and
 * password and the site's folder taken out, and the first place in Alfa Commerce's code it passed through; then the
 * request's peak memory and time.
 *
 * The visitor's page carries the reference only: the error shown is a sentence with the reference, the original
 * chained to it as its cause (Joomla's error page shows that cause only with Debug System on). The status stays a 5xx,
 * nothing is retried and nothing is hidden from the log. A failure to write never changes the page.
 *
 * Joomla's Rotate Logs task rotates the file with the site's other logs.
 *
 * @since  2.2.2
 */
final class ErrorRecorder
{
    /**
     * The log category of the record.
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const CATEGORY = 'com_alfa.errors';

    /**
     * Its file, in Joomla's log folder; the same options as AlfaLog's map, so the two registrations are one.
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const FILE = 'com_alfa_errors.php';

    /**
     * The response header that carries the reference.
     *
     * @var string
     *
     * @since  2.2.2
     */
    public const HEADER = 'X-Alfa-Error-Reference';

    /**
     * The longest message kept for one throwable, in bytes.
     *
     * @var int
     *
     * @since  2.2.2
     */
    private const MESSAGE_LIMIT = 500;

    /**
     * A query parameter whose value is never written: a form token (32 hexadecimal characters as its name), or a
     * name that says token, key, password, secret, signature or authorisation.
     *
     * @var string
     *
     * @since  2.2.2
     */
    private const SECRET_PARAMETER = '/^(?:[0-9a-f]{32}|.*(?:token|key|pass|secret|sig|auth|hash|code).*)$/i';

    /**
     * Whether an error is a server error, the kind recorded: one the application answers with a status that is not
     * a 4xx. The REST API answers through Joomla's JSON:API error handlers, which give a refused parameter, a refused
     * save, a record not found, a refused access and the like their 4xx whatever the exception's code (a refused
     * parameter thrown with code 0 answers 400), and anything else 500. The site and the administrator answer an
     * error's 4xx code with it and anything else as a server error.
     *
     * @param Throwable $error The uncaught error.
     * @param CMSApplicationInterface $app The application that caught it.
     *
     * @since  2.2.2
     */
    public static function isServerError(Throwable $error, CMSApplicationInterface $app): bool
    {
        $code = $app->isClient('api') ? self::apiStatus(error: $error) : (int) $error->getCode();

        return $code < 400 || $code > 499;
    }

    /**
     * The status the REST API answers an uncaught error with: the first of Joomla's JSON:API error handlers that manages
     * it decides, in the order Joomla's JsonapiRenderer registers them; an error none manages, or one that is not an
     * Exception, answers 500, as the renderer's fallback does.
     *
     * @param Throwable $error The uncaught error.
     *
     * @since  2.2.2
     */
    private static function apiStatus(Throwable $error): int
    {
        if (!$error instanceof Exception) {
            return 500;
        }

        foreach ([
            new InvalidRouteExceptionHandler(),
            new AuthenticationFailedExceptionHandler(),
            new NotAcceptableExceptionHandler(),
            new NotAllowedExceptionHandler(),
            new InvalidParameterExceptionHandler(),
            new ResourceNotFoundExceptionHandler(),
            new SaveExceptionHandler(),
            new CheckinCheckoutExceptionHandler(),
            new SendEmailExceptionHandler(),
        ] as $handler) {
            if ($handler->manages($error)) {
                return (int) $handler->handle($error)->getStatus();
            }
        }

        return 500;
    }

    /**
     * Record a server error of the request: one line in the error log.
     *
     * @param Throwable $error The uncaught error.
     * @param CMSApplicationInterface $app The application that caught it.
     * @param string $reference The reference the visitor is given.
     *
     * @return bool Whether the line was written.
     *
     * @since  2.2.2
     */
    public static function record(Throwable $error, CMSApplicationInterface $app, string $reference): bool
    {
        try {
            Log::addLogger(['text_file' => self::FILE], Log::ALL, [self::CATEGORY]);
            Log::add(self::line(error: $error, app: $app, reference: $reference), Log::ERROR, self::CATEGORY);

            return true;
        } catch (Throwable) {
            // An unwritable log never changes the page the visitor gets.
            return false;
        }
    }

    /**
     * A new reference: 16 hexadecimal characters.
     *
     * @since  2.2.2
     */
    public static function reference(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * The status a server error answers with: its own code when it is a 5xx, else 500.
     *
     * @param Throwable $error The uncaught error.
     *
     * @since  2.2.2
     */
    public static function status(Throwable $error): int
    {
        $code = (int) $error->getCode();

        return $code >= 500 && $code <= 599 ? $code : 500;
    }

    /**
     * The record's line.
     *
     * @param Throwable $error The uncaught error.
     * @param CMSApplicationInterface $app The application.
     * @param string $reference The reference.
     *
     * @since  2.2.2
     */
    public static function line(Throwable $error, CMSApplicationInterface $app, string $reference): string
    {
        $parts = [
            'reference ' . $reference,
            self::client(app: $app),
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . self::address(),
            'referrer ' . (parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: '-'),
            'agent "' . str_replace('"', "'", mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)) . '"',
            'session ' . self::sessionHash(app: $app),
            'user ' . self::userId(app: $app),
        ];
        $depth = 0;

        for ($link = $error; $link !== null && $depth < 5; $link = $link->getPrevious(), $depth++) {
            $parts[] = ($depth === 0 ? 'error ' : 'cause ') . self::describe(error: $link, app: $app);
        }

        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $parts[] = \sprintf('memory %.1f MB, time %.3f s', memory_get_peak_usage(true) / 1048576, microtime(true) - $started);

        return implode(' | ', $parts);
    }

    /**
     * A message without the database's credentials, names and the site's folder, on one line, at most
     * MESSAGE_LIMIT bytes.
     *
     * @param string $message The message.
     * @param CMSApplicationInterface|null $app The application whose configuration names the database.
     *
     * @since  2.2.2
     */
    public static function clean(string $message, ?CMSApplicationInterface $app = null): string
    {
        $replace = [];

        if ($app !== null) {
            foreach (['password' => '<password>', 'user' => '<user>', 'db' => '<schema>', 'host' => '<host>'] as $setting => $mask) {
                $value = (string) $app->get($setting, '');

                // A value of one or two characters would mask letters of ordinary words.
                if (\strlen($value) >= 3) {
                    $replace[$value] = $mask;
                }
            }
        }

        $replace[JPATH_ROOT . DIRECTORY_SEPARATOR] = '';
        $replace[JPATH_ROOT] = '.';
        uksort($replace, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $message = strtr($message, $replace);
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return \strlen($message) > self::MESSAGE_LIMIT ? mb_strcut($message, 0, self::MESSAGE_LIMIT) . '...' : trim($message);
    }

    /**
     * One throwable: class, code, the database's error number and SQLSTATE, the cleaned message, the first place in
     * Alfa Commerce's code.
     *
     * @param Throwable $error The throwable.
     * @param CMSApplicationInterface $app The application whose configuration names the database.
     *
     * @since  2.2.2
     */
    private static function describe(Throwable $error, CMSApplicationInterface $app): string
    {
        $text = $error::class . ' (' . $error->getCode() . ')';
        $sql = self::sqlState(error: $error);

        if ($sql !== null) {
            $text .= ' sql ' . $sql;
        }

        $text .= ': ' . self::clean(message: $error->getMessage(), app: $app);
        $where = self::alfaFrame(error: $error);

        return $where === null ? $text : $text . ' at ' . $where;
    }

    /**
     * The database's error number, with its SQLSTATE when the driver gave one; null when the database did not refuse.
     *
     * @param Throwable $error The throwable.
     *
     * @since  2.2.2
     */
    private static function sqlState(Throwable $error): ?string
    {
        if ($error instanceof mysqli_sql_exception) {
            return $error->getCode() . ' ' . $error->getSqlState();
        }

        if ($error instanceof PDOException) {
            $info = (array) $error->errorInfo;

            return trim(($info[1] ?? $error->getCode()) . ' ' . ($info[0] ?? ''));
        }

        if (str_starts_with($error::class, 'Joomla\\Database\\Exception\\')) {
            return (string) $error->getCode();
        }

        return null;
    }

    /**
     * The first place of the throwable or its trace inside Alfa Commerce's code (the component, an alfa plugin, the
     * Alfa modules), relative to the site's folder.
     *
     * @param Throwable $error The throwable.
     *
     * @since  2.2.2
     */
    private static function alfaFrame(Throwable $error): ?string
    {
        $frames = array_merge([['file' => $error->getFile(), 'line' => $error->getLine()]], $error->getTrace());

        foreach ($frames as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));

            if ($file !== '' && preg_match('#/(?:components/com_alfa|plugins/(?:alfa[^/]*|system/alfasync|webservices/alfa|console/alfa)|modules/mod_(?:alfa_[^/]+|easy_articles))/#', $file)) {
                $root = str_replace('\\', '/', JPATH_ROOT) . '/';

                return (str_starts_with($file, $root) ? substr($file, \strlen($root)) : $file) . ':' . (int) ($frame['line'] ?? 0);
            }
        }

        return null;
    }

    /**
     * The client of the request.
     *
     * @param CMSApplicationInterface $app The application.
     *
     * @since  2.2.2
     */
    private static function client(CMSApplicationInterface $app): string
    {
        foreach (['site', 'administrator', 'api'] as $client) {
            if ($app->isClient($client)) {
                return $client;
            }
        }

        return 'other';
    }

    /**
     * The path and query asked for, a secret-looking parameter's value shown as ***.
     *
     * @since  2.2.2
     */
    private static function address(): string
    {
        try {
            $uri = Uri::getInstance();
            $path = $uri->getPath();
            $query = $uri->getQuery(true);
        } catch (Throwable) {
            return '-';
        }

        $masked = [];

        foreach ((array) $query as $name => $value) {
            $masked[$name] = preg_match(self::SECRET_PARAMETER, (string) $name) ? '***' : $value;
        }

        return $path . ($masked === [] ? '' : '?' . urldecode(http_build_query($masked)));
    }

    /**
     * The first 12 hexadecimal characters of the SHA-256 of the session id; '-' without a session.
     *
     * @param CMSApplicationInterface $app The application.
     *
     * @since  2.2.2
     */
    private static function sessionHash(CMSApplicationInterface $app): string
    {
        try {
            $id = method_exists($app, 'getSession') ? (string) $app->getSession()->getId() : '';
        } catch (Throwable) {
            $id = '';
        }

        return $id === '' ? '-' : substr(hash('sha256', $id), 0, 12);
    }

    /**
     * The user id; 0 for a guest or when no user is known.
     *
     * @param CMSApplicationInterface $app The application.
     *
     * @since  2.2.2
     */
    private static function userId(CMSApplicationInterface $app): int
    {
        try {
            return (int) ($app->getIdentity()->id ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
