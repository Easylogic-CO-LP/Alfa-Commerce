<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Media;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\ThumbnailsRecreateJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use InvalidArgumentException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\File;
use Throwable;

/**
 * Recreate the gallery thumbnails (2.2.0, the media.thumbnails.recreate job): the domain service the
 * Tools > Media buttons request and the job walks.
 *
 * TWO MODES. "all" makes every image row's thumbnail again at the configured box (after a change of the box); "missing"
 * makes only the ones a row lacks: no thumbnail (empty or NULL), its file gone or unreadable, the full image as its
 * thumbnail (its own path or another row's), or smaller than the box allows for its image. A row of type url is never
 * touched; a row whose own image file is missing is reported and left as it is.
 *
 * WHAT THE MERCHANT CHOSE STAYS (2.2.0, REV-C21 MAJOR 1). A thumbnail chosen on the tile (thumbnail_origin chosen) is
 * left as it is in both modes and counted kept, while its file is there. A row written before 2.2.0 (NULL) cannot say
 * what its thumbnail was made from: it is made again as any other, and its previous file is kept on disk, so a
 * picture chosen then can be chosen again, counted apart in the report.
 *
 * WHAT A TICK CAN DECODE (REV-C21 MAJOR 3, measured in c21r2). An image whose decode does not fit the memory the tick
 * has left is not opened: the row is failed with that reason and the walk goes on, so one image too big for the process
 * never ends every tick in a fatal. The need is GD's measured peak by format (decodeNeed()), with a quarter more.
 *
 * THE MODE IS THE SHOP'S, NOT THE JOB'S PAYLOAD (a merge never rewrites a payload): #__alfa_meta keeps the mode asked
 * for. "all" always wins: asked while a job waits or runs, it becomes the mode and the job (asked again) walks once more
 * from the start; "missing" asked while a job is active is covered by that job and changes nothing. A walk reads the
 * mode once, at its start, and keeps it in its checkpoint beside its report.
 *
 * THE FILE BEFORE THE ROW. Each row's thumbnail is written first, into the save folder with saveMedia's naming
 * (MediaHelper::thumbnailDestination(): <the image's name>-thumb at a stem no file in any extension and no row has),
 * never over another file, then the row is pointed at it in one statement, its own short transaction, while the row
 * still names the thumbnail the walk read (NULL included), then its old thumbnail is deleted when no row names it any
 * more. A kill between the write and the update, or after the update, leaves one file no row names, which Tools > Media
 * lists among the files no row tracks.
 *
 * @since  2.2.0
 */
final class ThumbnailRecreation
{
    /**
     * Every image row's thumbnail made again.
     *
     * @since  2.2.0
     */
    public const MODE_ALL = 'all';

    /**
     * Only the missing and undersized ones.
     *
     * @since  2.2.0
     */
    public const MODE_MISSING = 'missing';

    /**
     * The #__alfa_meta row of the mode asked for.
     *
     * @since  2.2.0
     */
    public const META = 'media.thumbnails.mode';

    /**
     * Row ids a report lists by name for each of its two lists; beyond them it counts.
     *
     * @since  2.2.0
     */
    public const REPORT_IDS = 20;

    /**
     * A thumbnail this many pixels short of what the box allows is right: two resizers round differently.
     *
     * @since  2.2.0
     */
    private const ROUNDING = 1;

    /**
     * What GD takes, in bytes a pixel of the image, to make one thumbnail of it, by image type: PHP's real peak around
     * makeThumbnail() less the usage before it, measured at 1500, 2500 and 3500 px a side and fitted (runs/c21r2/
     * decode-peak.txt in the build record), for the worst variant of each format: a PNG with alpha 8.61 (libpng's
     * buffer of the whole image, then GD's own 4 bytes), a JPEG 4.61 (progressive the same), a WebP 4.61 (alpha the
     * same), a GIF 1.05; rounded up. A type not measured reads as a PNG, the most.
     *
     * @since  2.2.0
     */
    private const DECODE_BYTES_PER_PIXEL = [IMAGETYPE_PNG => 8.7, IMAGETYPE_JPEG => 4.7, IMAGETYPE_WEBP => 4.7, IMAGETYPE_GIF => 1.1];

    /**
     * What a decode takes whatever its size, beyond the thumbnail's own canvas (the measured fit's fixed part, at most
     * 4.27 MB with a 500 x 375 canvas inside it, rounded up), in bytes.
     *
     * @since  2.2.0
     */
    private const DECODE_FIXED = 4194304;

    /**
     * The margin over the measured need: a quarter more.
     *
     * @since  2.2.0
     */
    private const DECODE_SAFETY = 1.25;

    /**
     * Why a row's thumbnail was not made: its image too big to decode in the memory the tick has left.
     *
     * @since  2.2.0
     */
    public const REASON_MEMORY = 'memory';

    /**
     * Why a row's thumbnail was not made: no file could be written.
     *
     * @since  2.2.0
     */
    public const REASON_WRITE = 'write';

    /**
     * Why a row's thumbnail was not made: the row changed or went while the walk wrote it.
     *
     * @since  2.2.0
     */
    public const REASON_CHANGED = 'changed';

    /**
     * Ask for the thumbnails to be recreated in a mode: the job, or the one already waiting or running.
     *
     * One transaction holds the mode's row and the enqueue, so two requests at once agree: "all" becomes the mode and is
     * owed; "missing" is owed only when no job is active, and otherwise joins the active one as it is.
     *
     * @param string $mode self::MODE_ALL or self::MODE_MISSING.
     *
     * @return int The job.
     *
     * @throws InvalidArgumentException When the mode is neither.
     *
     * @since  2.2.0
     */
    public static function request(string $mode): int
    {
        if (!\in_array($mode, [self::MODE_ALL, self::MODE_MISSING], true)) {
            throw new InvalidArgumentException('A thumbnails mode is ' . self::MODE_ALL . ' or ' . self::MODE_MISSING . ' (given: ' . $mode . ').');
        }

        $db = self::db();
        $name = self::META;
        $db->transactionStart();

        try {
            $db->setQuery(
                $db->getQuery(true)->setQuery(
                    'INSERT IGNORE INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ') VALUES (:name, :value)',
                )
                    ->bind(':name', $name)
                    ->bind(':value', $mode),
            )->execute();
            // The mode's row held to the commit: a second request waits here, then sees this one's job.
            $db->setQuery(
                'SELECT ' . $db->quoteName('value') . ' FROM ' . $db->quoteName('#__alfa_meta')
                . ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote($name) . ' FOR UPDATE',
            )->loadResult();
            $active = self::activeJob(db: $db);

            if ($mode === self::MODE_MISSING && $active !== null) {
                $db->transactionCommit();

                return $active;
            }

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_meta'))
                    ->set($db->quoteName('value') . ' = :value')
                    ->where($db->quoteName('name') . ' = :name')
                    ->bind(':value', $mode)
                    ->bind(':name', $name),
            )->execute();
            $id = Runner::enqueue(handler: ThumbnailsRecreateJob::NAME, scopeKey: ThumbnailsRecreateJob::NAME, payload: [])->id;
            $db->transactionCommit();

            return $id;
        } catch (Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }
    }

    /**
     * The mode asked for last: "missing" when none was ever asked.
     *
     * @since  2.2.0
     */
    public static function mode(): string
    {
        $db = self::db();
        $value = (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote(self::META)),
        )->loadResult();

        return $value === self::MODE_ALL ? self::MODE_ALL : self::MODE_MISSING;
    }

    /**
     * The rows a walk looks at: every media row but those of type url.
     *
     * @since  2.2.0
     */
    public static function rowCount(): int
    {
        $db = self::db();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_media'))
                ->where($db->quoteName('type') . ' != ' . $db->quote('url')),
        )->loadResult();
    }

    /**
     * The next rows of a walk, by ascending id: never a row of type url.
     *
     * @param int $after The last id walked.
     * @param int $limit At most this many.
     *
     * @return object[] Rows (id, path, thumbnail, thumbnail_origin).
     *
     * @since  2.2.0
     */
    public static function rowsAfter(int $after, int $limit): array
    {
        $db = self::db();

        return $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'path', 'thumbnail', 'thumbnail_origin']))
                ->from($db->quoteName('#__alfa_media'))
                ->where($db->quoteName('type') . ' != ' . $db->quote('url'))
                ->where($db->quoteName('id') . ' > :after')
                ->bind(':after', $after, ParameterType::INTEGER)
                ->order($db->quoteName('id') . ' ASC'),
            0,
            max(1, $limit),
        )->loadObjectList() ?: [];
    }

    /**
     * Recreate one row's thumbnail in a mode, when the mode asks for it.
     *
     * The outcome is 'made', 'kept' (the mode does not ask for it, or the merchant chose it on the tile), 'missing' (the
     * row's own image file is gone) or 'failed' with its reason (self::REASON_MEMORY, self::REASON_WRITE,
     * self::REASON_CHANGED); `previous` says a row written before 2.2.0 was made and its previous thumbnail stays on
     * disk, named by no row.
     *
     * @param object $row The row (id, path, thumbnail, thumbnail_origin).
     * @param string $mode self::MODE_ALL or self::MODE_MISSING.
     *
     * @return array{outcome: string, reason: string|null, previous: bool} The outcome, its reason and the previous file.
     *
     * @since  2.2.0
     */
    public static function recreate(object $row, string $mode): array
    {
        $image = ltrim((string) $row->path, '/');
        $old = ltrim((string) ($row->thumbnail ?? ''), '/');
        $origin = $row->thumbnail_origin ?? null;

        if ($image === '' || str_contains($image, '://') || str_starts_with($image, '//') || !is_file(JPATH_ROOT . '/' . $image)) {
            return self::outcome(outcome: 'missing');
        }

        // The merchant's own picture, chosen on the tile: theirs, in both modes, while its file is there.
        if ($origin === MediaHelper::THUMBNAIL_CHOSEN && $old !== '' && !str_contains($old, '://') && is_file(JPATH_ROOT . '/' . $old)) {
            return self::outcome(outcome: 'kept');
        }

        if ($mode !== self::MODE_ALL && !self::lacks(row: $row)) {
            return self::outcome(outcome: 'kept');
        }

        if (!self::decodeFits(file: JPATH_ROOT . '/' . $image)) {
            return self::outcome(outcome: 'failed', reason: self::REASON_MEMORY);
        }

        $params = ComponentHelper::getParams('com_alfa');
        $written = MediaHelper::makeThumbnail(
            source: JPATH_ROOT . '/' . $image,
            dest: MediaHelper::thumbnailDestination(source: $image, absolutePath: JPATH_ROOT . '/' . self::saveFolder(params: $params)),
            params: $params,
        );

        // Nothing written: the row keeps what it names, never an empty thumbnail.
        if ($written === '') {
            return self::outcome(outcome: 'failed', reason: self::REASON_WRITE);
        }

        // One statement, its own short transaction: only while the row still names what this walk read (NULL too).
        $db = self::db();
        $id = (int) $row->id;
        $current = $row->thumbnail ?? null;
        $fromImage = MediaHelper::THUMBNAIL_FROM_IMAGE;
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__alfa_media'))
                ->set($db->quoteName('thumbnail') . ' = :written')
                ->set($db->quoteName('thumbnail_origin') . ' = :origin')
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('thumbnail') . ' <=> :current')
                ->bind(':written', $written)
                ->bind(':origin', $fromImage)
                ->bind(':id', $id, ParameterType::INTEGER)
                ->bind(':current', $current, $current === null ? ParameterType::NULL : ParameterType::STRING),
        )->execute();

        if ($db->getAffectedRows() !== 1) {
            // The row was changed or removed meanwhile: what was written is nobody's.
            self::deleteUnnamed(file: $written, saveFolder: self::saveFolder(params: $params));

            return self::outcome(outcome: 'failed', reason: self::REASON_CHANGED);
        }

        if ($old === '' || $old === $written || $old === $image || str_contains($old, '://')) {
            return self::outcome(outcome: 'made');
        }

        if ($origin === null) {
            // Written before 2.2.0: its thumbnail may be a picture the merchant chose then, so its file stays.
            return self::outcome(outcome: 'made', previous: is_file(JPATH_ROOT . '/' . $old) && MediaHelper::filesNamedByOtherRows(files: [$old]) === []);
        }

        self::deleteUnnamed(file: $old, saveFolder: self::saveFolder(params: $params));

        return self::outcome(outcome: 'made');
    }

    /**
     * One outcome of recreate().
     *
     * @param string $outcome 'made', 'kept', 'missing' or 'failed'.
     * @param string|null $reason Why it failed.
     * @param bool $previous Whether a previous thumbnail stays on disk.
     *
     * @return array{outcome: string, reason: string|null, previous: bool}
     *
     * @since  2.2.0
     */
    private static function outcome(string $outcome, ?string $reason = null, bool $previous = false): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'previous' => $previous];
    }

    /**
     * Whether an image's decode fits the memory this process has left: decodeNeed() for its size and type, read from the
     * file's header without decoding it, against memory_limit less the process's real usage. A file whose size cannot be
     * read is not refused here (the writer falls back as it does for any image it cannot resize).
     *
     * @param string $file Absolute path of the image.
     *
     * @since  2.2.0
     */
    public static function decodeFits(string $file): bool
    {
        $limit = trim((string) \ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $bytes = match (strtolower(substr($limit, -1))) {
            'g' => (int) $limit * 1073741824,
            'm' => (int) $limit * 1048576,
            'k' => (int) $limit * 1024,
            default => (int) $limit,
        };
        $size = @getimagesize($file);

        if ($bytes <= 0 || $size === false) {
            return true;
        }

        return self::decodeNeed(width: (int) $size[0], height: (int) $size[1], type: (int) $size[2]) <= $bytes - memory_get_usage(true);
    }

    /**
     * The memory one thumbnail of an image needs, in bytes: a quarter more than GD's measured peak for its type (its
     * pixels at DECODE_BYTES_PER_PIXEL, the thumbnail's canvas at the box, 4 bytes a pixel, and DECODE_FIXED).
     *
     * @param int $width The image's width.
     * @param int $height The image's height.
     * @param int $type Its IMAGETYPE_* (from getimagesize()).
     *
     * @since  2.2.0
     */
    public static function decodeNeed(int $width, int $height, int $type): int
    {
        [$boxWidth, $boxHeight] = MediaHelper::thumbnailBox();
        $rate = self::DECODE_BYTES_PER_PIXEL[$type] ?? self::DECODE_BYTES_PER_PIXEL[IMAGETYPE_PNG];

        return (int) ceil(self::DECODE_SAFETY * ($rate * $width * $height + $boxWidth * $boxHeight * 4 + self::DECODE_FIXED));
    }

    /**
     * Whether a row lacks a proper thumbnail: none, its file gone or unreadable, the full image (its own path or
     * another row's), or smaller than the box allows for its image (a thumbnail never grows past its image).
     *
     * @param object $row The row (id, path, thumbnail); a NULL thumbnail is none.
     *
     * @since  2.2.0
     */
    public static function lacks(object $row): bool
    {
        $image = ltrim((string) $row->path, '/');
        $thumbnail = ltrim((string) ($row->thumbnail ?? ''), '/');

        if ($thumbnail === '' || $thumbnail === $image || str_contains($thumbnail, '://') || !is_file(JPATH_ROOT . '/' . $thumbnail)) {
            return true;
        }

        $db = self::db();
        $id = (int) $row->id;
        $asPath = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_media'))
                ->where($db->quoteName('id') . ' != :id')
                ->where($db->quoteName('path') . ' IN (' . $db->quote($thumbnail) . ', ' . $db->quote('/' . $thumbnail) . ')')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadResult();

        if ($asPath > 0) {
            return true;
        }

        $thumbSize = @getimagesize(JPATH_ROOT . '/' . $thumbnail);
        $imageSize = @getimagesize(JPATH_ROOT . '/' . $image);

        if ($thumbSize === false) {
            return true;
        }

        if ($imageSize === false) {
            return false;
        }

        [$boxWidth, $boxHeight] = MediaHelper::thumbnailBox();
        $scale = min($boxWidth / max(1, $imageSize[0]), $boxHeight / max(1, $imageSize[1]), 1);

        return $thumbSize[0] + self::ROUNDING < (int) round($imageSize[0] * $scale)
            || $thumbSize[1] + self::ROUNDING < (int) round($imageSize[1] * $scale);
    }

    /**
     * A report's lines after one more row.
     *
     * @param array $report So far (self::emptyReport()'s keys).
     * @param int $id The row.
     * @param array{outcome: string, reason: string|null, previous: bool} $outcome What recreate() said.
     *
     * @return array The report with the row counted, and named when its list has room (a failed row with its reason).
     *
     * @since  2.2.0
     */
    public static function noted(array $report, int $id, array $outcome): array
    {
        $report += self::emptyReport();
        $kind = (string) $outcome['outcome'];
        $report[$kind] = (int) ($report[$kind] ?? 0) + 1;

        foreach (['missing' => 'missingIds', 'failed' => 'failedIds'] as $listed => $list) {
            if ($kind === $listed && \count($report[$list]) < self::REPORT_IDS) {
                $report[$list][] = $id;

                if ($kind === 'failed') {
                    $report['failedReasons'][(string) $id] = (string) ($outcome['reason'] ?? self::REASON_WRITE);
                }
            }
        }

        if (!empty($outcome['previous'])) {
            $report['previous']++;

            if (\count($report['previousIds']) < self::REPORT_IDS) {
                $report['previousIds'][] = $id;
            }
        }

        return $report;
    }

    /**
     * A walk's report and mode as its job's checkpoint holds them, or null when it holds none yet.
     *
     * @param array|string|null $checkpoint The job's checkpoint, decoded or as stored.
     *
     * @return array{mode: string, made: int, kept: int, missing: int, failed: int, previous: int, missingIds: int[], failedIds: int[], previousIds: int[], failedReasons: array<string, string>}|null
     *
     * @since  2.2.0
     */
    public static function reportOf(array|string|null $checkpoint): ?array
    {
        $checkpoint = \is_string($checkpoint) ? json_decode($checkpoint, true) : $checkpoint;

        if (!\is_array($checkpoint) || !\is_array($checkpoint['report'] ?? null)) {
            return null;
        }

        return ['mode' => $checkpoint['mode'] === self::MODE_ALL ? self::MODE_ALL : self::MODE_MISSING]
            + array_intersect_key($checkpoint['report'] + self::emptyReport(), self::emptyReport());
    }

    /**
     * The report of the newest job of thumbnails, or null when none walked yet.
     *
     * @since  2.2.0
     */
    public static function lastReport(): ?array
    {
        $db = self::db();
        $type = Tasks::TYPE_JOB;
        $scope = ThumbnailsRecreateJob::NAME;

        return self::reportOf(checkpoint: $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('checkpoint'))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('scope_key') . ' = :scope')
                ->order($db->quoteName('id') . ' DESC')
                ->bind(':type', $type)
                ->bind(':scope', $scope),
            0,
            1,
        )->loadResult());
    }

    /**
     * An empty report: the counts, the rows named for the image files missing and the thumbnails not made (each with its
     * reason), and the rows written before 2.2.0 whose previous thumbnail stays on disk.
     *
     * @return array{made: int, kept: int, missing: int, failed: int, previous: int, missingIds: int[], failedIds: int[], previousIds: int[], failedReasons: array<string, string>}
     *
     * @since  2.2.0
     */
    public static function emptyReport(): array
    {
        return ['made' => 0, 'kept' => 0, 'missing' => 0, 'failed' => 0, 'previous' => 0, 'missingIds' => [], 'failedIds' => [], 'previousIds' => [], 'failedReasons' => []];
    }

    /**
     * Delete a file of the save folder that no media row names.
     *
     * @param string $file Site-relative file.
     * @param string $saveFolder The save folder.
     *
     * @since  2.2.0
     */
    private static function deleteUnnamed(string $file, string $saveFolder): void
    {
        if (!MediaHelper::insideFolder(file: $file, folder: $saveFolder) || MediaHelper::filesNamedByOtherRows(files: [$file]) !== []) {
            return;
        }

        try {
            if (is_file(JPATH_ROOT . '/' . $file)) {
                File::delete(JPATH_ROOT . '/' . $file);
            }
        } catch (Throwable) {
            // A file that cannot be deleted is left: Tools > Media lists it among the files no row tracks.
        }
    }

    /**
     * The save folder, site-relative.
     *
     * @param object $params Component parameters.
     *
     * @since  2.2.0
     */
    private static function saveFolder(object $params): string
    {
        return trim((string) $params->get('media_save_location', 'images/commerce'), '/');
    }

    /**
     * The job of thumbnails waiting or running, or null.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @since  2.2.0
     */
    private static function activeJob(DatabaseDriver $db): ?int
    {
        $name = ThumbnailsRecreateJob::NAME;
        $id = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                ->where($db->quoteName('scope_key') . ' = :scope')
                ->whereIn($db->quoteName('state'), [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], ParameterType::STRING)
                ->bind(':scope', $name),
        )->loadResult();

        return $id > 0 ? $id : null;
    }

    /**
     * The connection.
     *
     * @since  2.2.0
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
