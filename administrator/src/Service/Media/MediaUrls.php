<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Media;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\BackgroundWork;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\MediaFetchJob;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Images a REST API client gives as addresses ({"type": "image", "url": "https://..."}), brought to the media upload
 * path: an address already fetched for the record is not fetched again; one or two images are fetched at once, before
 * the record's save opens its transaction, and stored by the save as an upload is; more are fetched by a background
 * job (media.fetch, MediaFetchJob) that the save writes, named in the answer's meta.tasks. An image refused is named
 * by its JSON pointer into the request (MediaUrls::refusals()), and the record keeps everything else the request
 * sends. A fetched file is known to the save by a token of this request only, so no submission can name a file of the
 * server as fetched.
 *
 * @api WRITE: the images of a write given as addresses, the ones refused, and the files fetched for it; queue()
 *      enqueues the background fetch of the rest.
 *
 * @since  2.3.0
 */
final class MediaUrls
{
    /**
     * The most images given as addresses that a write fetches itself; more go to a background job.
     *
     * @since  2.3.0
     */
    public const INLINE = 2;

    /**
     * The files fetched in this request and not stored yet: token => {file, name, url}.
     *
     * @var array<string, array{file: string, name: string, url: string}>
     * @since  2.3.0
     */
    private static array $held = [];

    /**
     * The images of this request's writes that were refused: {pointer, url, reason}.
     *
     * @var list<array{pointer: string, url: string, reason: string}>
     * @since  2.3.0
     */
    private static array $refused = [];

    /**
     * Every file fetched in this request: removed at its end, whatever became of it.
     *
     * @var string[]
     * @since  2.3.0
     */
    private static array $files = [];

    /**
     * Whether the removal of the files left at the end of the request is registered.
     *
     * @since  2.3.0
     */
    private static bool $sweeping = false;

    /**
     * Bring a write's media rows to the upload path: each row that gives an address ('fetch' => url, 'pointer' => its
     * JSON pointer) is dropped when the record already holds an image fetched from that address (or the request names
     * it twice), fetched now when the write gives at most INLINE of them (the row then carries 'fetched', the token
     * the save stores it by), or left marked for the save to queue. A fetch refused drops the row and is noted.
     *
     * @param array<string, array> $rows The write's media rows, keyed as the save reads them.
     * @param string $origin The record's kind, as its media rows name it (item, category, manufacturer).
     * @param int $recordId The record, 0 for one the write creates.
     * @param MediaFetch|null $fetch The fetch; null for the guarded one with the Options' limits.
     *
     * @return array<string, array> The rows the save takes.
     *
     * @since  2.3.0
     */
    public static function prepare(array $rows, string $origin, int $recordId, ?MediaFetch $fetch = null): array
    {
        // Only a new row is an image to fetch: a row the record holds keeps its file.
        $given = array_filter($rows, static fn (array $row, $key): bool => str_contains((string) $key, 'new-') && (string) ($row['fetch'] ?? '') !== '', ARRAY_FILTER_USE_BOTH);

        if ($given === []) {
            return $rows;
        }

        $known = array_fill_keys(self::storedHashes(origin: $origin, recordId: $recordId), true);

        foreach ($given as $key => $row) {
            $hash = MediaFetch::urlHash((string) $row['fetch']);

            if (isset($known[$hash])) {
                unset($rows[$key], $given[$key]);

                continue;
            }

            $known[$hash] = true;
        }

        if (\count($given) > self::INLINE) {
            return $rows;
        }

        $fetch ??= new MediaFetch();
        $allowed = MediaHelper::resolveAllowedMimes(ComponentHelper::getParams('com_alfa')->get('media_mime'));

        foreach ($given as $key => $row) {
            $url = (string) $row['fetch'];

            try {
                $fetched = $fetch->fetch(url: $url, allowedMimes: $allowed);
                $rows[$key]['fetched'] = self::hold(file: $fetched['file'], name: $fetched['name'], url: $url);
                unset($rows[$key]['fetch']);
            } catch (MediaFetchRefused $e) {
                self::refuse(pointer: (string) ($row['pointer'] ?? ''), url: $url, reason: ErrorText::of($e));
                unset($rows[$key]);
            }
        }

        return $rows;
    }

    /**
     * Keep a fetched file for the save of this request; the token names it.
     *
     * @param string $file The temporary file.
     * @param string $name The name the address gives it.
     * @param string $url The address.
     *
     * @since  2.3.0
     */
    public static function hold(string $file, string $name, string $url): string
    {
        $token = bin2hex(random_bytes(16));
        self::$held[$token] = ['file' => $file, 'name' => $name, 'url' => $url];
        self::$files[] = $file;

        if (!self::$sweeping) {
            self::$sweeping = true;
            register_shutdown_function(static function (): void {
                foreach (self::$files as $fetched) {
                    if (is_file($fetched)) {
                        @unlink($fetched);
                    }
                }

                self::$held = [];
                self::$files = [];
            });
        }

        return $token;
    }

    /**
     * The fetched file a token names, given once: the save stores it, then removes the temporary file.
     *
     * @param string $token The token.
     *
     * @return array{file: string, name: string, url: string}|null Null when this request fetched no such file.
     *
     * @since  2.3.0
     */
    public static function take(string $token): ?array
    {
        $held = self::$held[$token] ?? null;
        unset(self::$held[$token]);

        return $held;
    }

    /**
     * Note an image refused, for the answer.
     *
     * @param string $pointer Its JSON pointer into the request (/media/1/url).
     * @param string $url The address.
     * @param string $reason Why, in a client's words.
     *
     * @since  2.3.0
     */
    public static function refuse(string $pointer, string $url, string $reason): void
    {
        self::$refused[] = ['pointer' => $pointer, 'url' => $url, 'reason' => $reason];
    }

    /**
     * The images refused in this request, and none after: each answer says its own.
     *
     * @return list<array{pointer: string, url: string, reason: string}>
     *
     * @since  2.3.0
     */
    public static function refusals(): array
    {
        $refused = self::$refused;
        self::$refused = [];

        return $refused;
    }

    /**
     * Owe the fetch of the images a save left marked: one background job for this save's images, in the save's
     * transaction, so it exists once the save has committed and not if it rolls back.
     *
     * @param string $origin The record's kind.
     * @param int $recordId The record.
     * @param list<array{url: string, alt: string, ordering: int, pointer: string}> $images The images, in order.
     *
     * @since  2.3.0
     */
    public static function queue(string $origin, int $recordId, array $images): void
    {
        if ($images === [] || $recordId <= 0) {
            return;
        }

        BackgroundWork::core()->job(MediaFetchJob::NAME)
            ->scope($origin . ':' . $recordId . ':' . bin2hex(random_bytes(4)))
            ->with(['origin' => $origin, 'record' => $recordId, 'images' => $images])
            ->enqueue();
    }

    /**
     * The hashes of the addresses a record's images were fetched from.
     *
     * @param string $origin The record's kind.
     * @param int $recordId The record; 0 holds none.
     *
     * @return string[]
     *
     * @since  2.3.0
     */
    public static function storedHashes(string $origin, int $recordId): array
    {
        if ($recordId <= 0) {
            return [];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return array_values(array_map('strval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('source_url_hash'))
                ->from($db->quoteName('#__alfa_media'))
                ->where($db->quoteName('origin') . ' = ' . $db->quote($origin))
                ->where($db->quoteName('item_id') . ' = ' . $recordId)
                ->where($db->quoteName('source_url_hash') . ' IS NOT NULL'),
        )->loadColumn() ?: []));
    }
}
