<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task\Job;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Media\MediaFetch;
use Alfa\Component\Alfa\Administrator\Service\Media\MediaFetchRefused;
use Alfa\Component\Alfa\Administrator\Service\Media\MediaUrls;
use Alfa\Component\Alfa\Administrator\Service\Task\JobChunk;
use Alfa\Component\Alfa\Administrator\Service\Task\JobHandler;
use Alfa\Component\Alfa\Administrator\Service\Task\JobPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\JobRun;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * The images a REST API write gave as addresses, more than it fetches itself (MediaUrls::INLINE): one unit an image,
 * fetched with the guards of MediaFetch and stored through the media upload path (the catalogue folder, the thumbnail,
 * the dominant colour, the media plugin events), each at the place the write gave it. The job's scope is the write's
 * own (media.fetch:<kind>:<record>:<token>), so two writes never share one.
 *
 * Its checkpoint keeps the report a reader of the task finds in its result: each image's pointer into the request,
 * its address and what became of it (stored with its media id, kept because the record already holds an image from
 * that address, or refused with the reason). An image refused does not stop the others. The fetch is a call outside
 * the shop, so the job keeps its own transactions (ownTransactions): no chunk transaction is held while it waits.
 *
 * @since  2.2.2
 */
final class MediaFetchJob implements JobHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.2
     */
    public const NAME = 'media.fetch';

    /**
     * An image stored.
     *
     * @since  2.2.2
     */
    public const STORED = 'stored';

    /**
     * An image the record already holds, from the same address: not fetched again.
     *
     * @since  2.2.2
     */
    public const KEPT = 'kept';

    /**
     * An image not stored, with the reason.
     *
     * @since  2.2.2
     */
    public const REFUSED = 'refused';

    /**
     * The fetch a test gives the job; null for the guarded one with the Options' limits.
     *
     * @since  2.2.2
     */
    public function __construct(private readonly ?MediaFetch $fetch = null)
    {
    }

    /**
     * A light job of one image a chunk to begin with; the runner grows the chunk from the time it measures.
     *
     * @since  2.2.2
     */
    public function policy(): JobPolicy
    {
        return new JobPolicy(label: 'COM_ALFA_TASKS_HANDLER_MEDIA_FETCH', weight: JobPolicy::LIGHT, priority: 15, initialUnits: 1, rerunOnMerge: false, ownTransactions: true);
    }

    /**
     * The images the write gave.
     *
     * @param JobRun $run The job.
     *
     * @since  2.2.2
     */
    public function total(JobRun $run): int
    {
        return \count((array) ($run->payload['images'] ?? []));
    }

    /**
     * Fetch and store the next images, each in a transaction of its own.
     *
     * @param JobRun $run The job.
     * @param int $units At most this many images.
     *
     * @since  2.2.2
     */
    public function chunk(JobRun $run, int $units): JobChunk
    {
        $images = array_values((array) ($run->payload['images'] ?? []));
        $origin = (string) ($run->payload['origin'] ?? '');
        $recordId = (int) ($run->payload['record'] ?? 0);
        $next = (int) ($run->checkpoint['next'] ?? 0);
        $report = (array) ($run->checkpoint['report'] ?? []);
        $walked = 0;

        while ($walked < $units && $next < \count($images)) {
            $report[] = $this->one(image: (array) $images[$next], origin: $origin, recordId: $recordId);
            $next++;
            $walked++;
        }

        return new JobChunk(units: $walked, checkpoint: ['next' => $next, 'report' => $report], finished: $next >= \count($images));
    }

    /**
     * What a reader of the task is told: each image and what became of it, null before the job has walked any.
     *
     * @param array<string, mixed>|null $checkpoint The job's checkpoint.
     *
     * @return list<array<string, mixed>>|null
     *
     * @since  2.2.2
     */
    public static function result(?array $checkpoint): ?array
    {
        $report = $checkpoint['report'] ?? null;

        return \is_array($report) ? array_values($report) : null;
    }

    /**
     * One image: kept when the record already holds one from its address, refused when the record is gone, else
     * fetched and stored as an upload.
     *
     * @param array $image {url, alt, ordering, pointer}.
     * @param string $origin The record's kind.
     * @param int $recordId The record.
     *
     * @return array{pointer: string, url: string, outcome: string, media_id: int|null, reason: string|null}
     *
     * @since  2.2.2
     */
    private function one(array $image, string $origin, int $recordId): array
    {
        $url = (string) ($image['url'] ?? '');
        $hash = MediaFetch::urlHash($url);
        $entry = ['pointer' => (string) ($image['pointer'] ?? ''), 'url' => $url, 'outcome' => self::REFUSED, 'media_id' => null, 'reason' => null];

        if (\in_array($hash, MediaUrls::storedHashes(origin: $origin, recordId: $recordId), true)) {
            return ['outcome' => self::KEPT] + $entry;
        }

        if (!self::recordExists(origin: $origin, recordId: $recordId)) {
            return ['reason' => 'the record no longer exists'] + $entry;
        }

        $allowed = MediaHelper::resolveAllowedMimes(ComponentHelper::getParams('com_alfa')->get('media_mime'));

        try {
            $fetched = ($this->fetch ?? new MediaFetch())->fetch(url: $url, allowedMimes: $allowed);
        } catch (MediaFetchRefused $e) {
            return ['reason' => ErrorText::of($e)] + $entry;
        }

        $db = TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));
        $token = MediaUrls::hold(file: $fetched['file'], name: $fetched['name'], url: $url);
        $db->transactionStart(true);

        try {
            MediaHelper::saveMedia(
                mediaData: ['new-0' => ['source' => '', 'thumbnail' => '', 'alt' => (string) ($image['alt'] ?? ''), 'type' => 'image', 'delete' => 0,
                    'fetched' => $token, 'pointer' => $entry['pointer'], 'position' => (int) ($image['ordering'] ?? 0)]],
                droppedMedia: [],
                itemId: $recordId,
                mediaOrigin: $origin,
            );
            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            Log::add('[MediaFetchJob] The image fetched from ' . $url . ' could not be stored: ' . $e::class . ': ' . ErrorText::of($e), Log::WARNING, 'com_alfa');

            return ['reason' => 'the image was fetched and could not be stored'] + $entry;
        }

        // A refusal of the upload path (the content, a media plugin) is said for this image; nothing of it is stored.
        $refused = MediaUrls::refusals();
        $mediaId = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_media'))
                ->where($db->quoteName('origin') . ' = ' . $db->quote($origin))
                ->where($db->quoteName('item_id') . ' = ' . $recordId)
                ->where($db->quoteName('source_url_hash') . ' = ' . $db->quote($hash))
                ->order($db->quoteName('id') . ' DESC'),
            0,
            1,
        )->loadResult();

        if ($mediaId === 0) {
            return ['reason' => (string) ($refused[0]['reason'] ?? 'the upload path did not store it')] + $entry;
        }

        return ['outcome' => self::STORED, 'media_id' => $mediaId] + $entry;
    }

    /**
     * Whether the record an image belongs to still exists: a product, a category or a manufacturer; another kind is
     * taken as it is.
     *
     * @param string $origin The record's kind.
     * @param int $recordId The record.
     *
     * @since  2.2.2
     */
    private static function recordExists(string $origin, int $recordId): bool
    {
        $table = ['item' => '#__alfa_items', 'category' => '#__alfa_categories', 'manufacturer' => '#__alfa_manufacturers'][$origin] ?? null;

        if ($table === null) {
            return $recordId > 0;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName($table))
                ->where($db->quoteName('id') . ' = ' . $recordId),
        )->loadResult() > 0;
    }
}
