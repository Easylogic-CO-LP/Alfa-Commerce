<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * The guided-tour pipeline's IMPORT half: the repo's JSON files under
 * `administrator/components/com_alfa/tours/` are the single source of truth, and
 * this seeder makes the `#__guidedtours` tables match them: Joomla core has no
 * file-based tour discovery (DB rows are the only mechanism), so the files travel
 * with the package and land here on every install/update.
 *
 * The contract that keeps per-user state honest:
 *   - upsert BY UID, keeping the tour row id STABLE: `#__user_profiles` records
 *     completed/skipped against that id, and replacing the row would orphan it;
 *   - a tour's STEPS are replaced wholesale (they carry no user state);
 *   - versions short-circuit: a file whose `version` matches the stored stamp is
 *     skipped, so postflight stays cheap;
 *   - a `com_alfa.*` tour in the DB with NO file any more is removed together with
 *     its user-profile keys, and tours outside our uid prefix are NEVER touched.
 *
 * All human text in the files is LANGUAGE KEYS. Their texts are one file,
 * administrator/language/<tag>/com_alfa.tours.ini, which plg_system_alfasync loads for a
 * signed-in administrator (Joomla's own tour loader looks for a file per tour).
 *
 * @since  2.0.0
 */
final class TourSeeder
{
    /**
     * Only tours in our namespace are ours to create, update or delete.
     *
     * @var string
     * @since  2.0.0
     */
    public const UID_PREFIX = 'com_alfa.';

    /**
     * Where the JSON sources live.
     *
     * @var string
     * @since  2.0.0
     */
    public const TOURS_PATH = JPATH_ADMINISTRATOR . '/components/com_alfa/tours';

    /**
     * What a non-interactive step stores in `interactive_type`: a column core reads
     * as an array key on EVERY step, interactive or not. See {@see self::interactiveType()}.
     *
     * @var int
     * @since  2.0.0
     */
    private const INTERACTIVE_TYPE_FILLER = 1;

    /**
     * How this seeder WRITES, independent of what the files SAY.
     *
     * The version stamp answers "is the database already what the files describe?",
     * and until now only the files could change the answer, so a fix to the writer
     * itself (the `interactive_type` filler was the first) reached new installs and
     * left every existing one with the rows it had. Bumping this re-seeds every tour
     * once on the next update, without touching 33 files to say the same thing.
     *
     * @var int
     * @since  2.0.0
     */
    private const WRITE_REVISION = 2;

    /**
     * The stamp {@see self::stamp()} writes, read back: group 1 the file's version, group 2
     * the site shape of a tour with "when" steps.
     *
     * @var string
     * @since  2.1.0
     */
    private const STAMP_PATTERN = '#^alfa:v(\d+)/w\d+(?:\+(monolingual|multilingual))?$#';

    /**
     * Make the DB match the JSON files.
     *
     * @param bool $force True re-seeds even version-matched tours (the Tools action;
     *                    postflight passes false and pays only for changes).
     *
     * @return array{seeded: string[], skipped: string[], removed: string[], errors: string[]}
     *
     * @since  2.0.0
     */
    public static function seedAll(bool $force = false): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $result = ['seeded' => [], 'skipped' => [], 'removed' => [], 'errors' => []];

        $files = self::sources();
        $seen = [];

        foreach ($files as $file) {
            try {
                $tour = self::read($file);

                $seen[] = $tour['uid'];

                if (self::seedOne($db, $tour, $force)) {
                    $result['seeded'][] = $tour['uid'];
                } else {
                    $result['skipped'][] = $tour['uid'];
                }
            } catch (Throwable $e) {
                $result['errors'][] = basename($file) . ': ' . $e->getMessage();
            }
        }

        // Files are the truth: an OURS tour with no file left is gone on purpose.
        try {
            foreach (self::storedTours($db) as $uid => $id) {
                if (!\in_array($uid, $seen, true)) {
                    self::removeTour($db, (int) $id);
                    $result['removed'][] = $uid;
                }
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'cleanup: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Remove every tour of ours with its steps and per-user keys: what the
     * component's uninstall leaves of the guided tours is nothing. Ours is what
     * the seeder stamped ({@see self::storedTours()}); no other tour is touched.
     *
     * @return string[] The uids removed.
     *
     * @since  2.0.0
     */
    public static function removeAll(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $removed = [];

        foreach (self::storedTours($db) as $uid => $id) {
            self::removeTour($db, (int) $id);
            $removed[] = $uid;
        }

        return $removed;
    }

    /**
     * Every tour file the install ships: core's, plus each enabled platform
     * plugin's own `tours/` folder.
     *
     * A plugin that adds screens has tours to teach them, and before this it had
     * nowhere to put them: the seeder scanned one folder and deleted any `com_alfa.*`
     * tour it could not find a file for, so a plugin's tour inserted by hand
     * survived exactly until the next re-seed. Discovery is a glob over the enabled
     * plugins of the platform group (the same "look at the disk, never at a table"
     * rule the rest of the platform follows), so installing a plugin ships its
     * tours and disabling it takes them away again.
     *
     * @return string[] Absolute paths, core first so a plugin cannot shadow a core tour.
     *
     * @since  2.0.0
     */
    private static function sources(): array
    {
        $files = glob(self::TOURS_PATH . '/*.json') ?: [];
        $group = PanelDispatcher::PLATFORM_GROUP;

        try {
            PluginHelper::importPlugin(type: $group, plugin: null, autocreate: true);
            $descriptors = PluginHelper::getPlugin($group);
        } catch (Throwable) {
            return $files;                          // no platform group: core's tours stand alone
        }

        foreach ($descriptors as $descriptor) {
            $own = JPATH_PLUGINS . '/' . $group . '/' . $descriptor->name . '/tours';

            if (is_dir($own)) {
                $files = array_merge($files, glob($own . '/*.json') ?: []);
            }
        }

        return $files;
    }

    /**
     * Parse and validate one JSON source.
     *
     * @param string $file Absolute path.
     *
     * @return array The tour definition.
     *
     * @throws RuntimeException On unreadable/invalid files or a foreign uid.
     *
     * @since  2.0.0
     */
    private static function read(string $file): array
    {
        $tour = json_decode((string) file_get_contents($file), true);

        $uid = (string) ($tour['uid'] ?? '');
        $ours = str_starts_with($uid, self::UID_PREFIX)
            || preg_match('/^plg_' . preg_quote(PanelDispatcher::PLATFORM_GROUP, '/') . '_[a-z0-9\-]+\./', $uid) === 1;

        if (!\is_array($tour) || !$ours) {
            throw new RuntimeException('not a com_alfa tour definition');
        }

        if (!\is_array($tour['steps'] ?? null) || $tour['steps'] === []) {
            throw new RuntimeException('a tour without steps teaches nothing');
        }

        return $tour;
    }

    /**
     * Upsert one tour. The row id never changes; steps are replaced wholesale.
     *
     * @param DatabaseInterface $db The database.
     * @param array $tour The definition from JSON.
     * @param bool $force Re-seed even when the version stamp matches.
     *
     * @return bool True when written, false when version-skipped.
     *
     * @since  2.0.0
     */
    private static function seedOne(DatabaseInterface $db, array $tour, bool $force): bool
    {
        $uid = (string) $tour['uid'];

        // A step may declare the site it is for ("when": "multilingual"|"monolingual"):
        // the language and association steps are noise on a single-language shop, and
        // the only honest teaching on a multilingual one. The shape rides in the
        // version stamp so that ADDING a second language re-seeds these tours instead
        // of leaving a merchant with steps written for the site they used to have.
        $shape = self::siteShape();
        $stamp = self::stamp($tour, $shape);
        $now = Factory::getDate()->toSql();
        $user = (int) (Factory::getApplication()->getIdentity()?->id ?? 0);

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'note']))
            ->from($db->quoteName('#__guidedtours'))
            ->where($db->quoteName('uid') . ' = :uid')
            ->bind(':uid', $uid, ParameterType::STRING);

        $existing = $db->setQuery($query, 0, 1)->loadObject();

        if ($existing && !$force && (string) $existing->note === $stamp) {
            return false;
        }

        $row = (object) (self::tourContent($tour) + [
            'uid' => $uid,
            'published' => 1,
            'language' => '*',
            'note' => $stamp,
            'access' => 1,
            'modified' => $now,
            'modified_by' => $user,
        ]);

        if ($existing) {
            $row->id = (int) $existing->id;
            $db->updateObject('#__guidedtours', $row, 'id');
        } else {
            $row->created = $now;
            $row->created_by = $user;
            $db->insertObject('#__guidedtours', $row, 'id');
        }

        $tourId = (int) ($row->id ?? $db->insertid());

        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__guidedtour_steps'))
            ->where($db->quoteName('tour_id') . ' = :tour')
            ->bind(':tour', $tourId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $ordering = 1;

        foreach (self::stepContents($tour, $shape) as $content) {
            $stepRow = (object) ($content + [
                'tour_id' => $tourId,
                'published' => 1,
                'ordering' => $ordering++,
                'created' => $now,
                'created_by' => $user,
                'modified' => $now,
                'modified_by' => $user,
                'language' => '*',
                'note' => '',
            ]);

            $db->insertObject('#__guidedtour_steps', $stepRow);
        }

        return true;
    }

    /**
     * The site's shape, for the steps that declare one in "when".
     *
     * @return string 'monolingual' or 'multilingual'.
     *
     * @since  2.1.0
     */
    private static function siteShape(): string
    {
        return SetupHelper::contentLanguages() === [] ? 'monolingual' : 'multilingual';
    }

    /**
     * The version stamp the seeder writes in a tour row's note: the file's version, the
     * writer's revision and, for a tour with "when" steps, the site shape its steps were
     * chosen for. {@see self::STAMP_PATTERN} reads it back.
     *
     * @param array $tour The definition from JSON.
     * @param string $shape The site's shape ({@see self::siteShape()}).
     *
     *
     * @since  2.1.0
     */
    private static function stamp(array $tour, string $shape): string
    {
        $conditional = false;

        foreach ($tour['steps'] as $step) {
            if (isset($step['when'])) {
                $conditional = true;
                break;
            }
        }

        return 'alfa:v' . self::fileVersion($tour)
            . '/w' . self::WRITE_REVISION
            . ($conditional ? '+' . $shape : '');
    }

    /**
     * The version a tour file states.
     *
     * @param array $tour The definition from JSON.
     *
     *
     * @since  2.1.0
     */
    private static function fileVersion(array $tour): int
    {
        return (int) ($tour['version'] ?? 1);
    }

    /**
     * What the seeder writes in a tour row from its file; the uid, the stamp and the
     * bookkeeping columns are the caller's.
     *
     * @param array $tour The definition from JSON.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    private static function tourContent(array $tour): array
    {
        return [
            'title' => (string) ($tour['title'] ?? $tour['uid']),
            'description' => (string) ($tour['description'] ?? ''),
            'extensions' => json_encode($tour['extensions'] ?? ['com_alfa']),
            'url' => (string) ($tour['url'] ?? 'administrator/index.php?option=com_alfa'),
            'autostart' => !empty($tour['autostart']) ? 1 : 0,
            // The "Take a Tour" list is ordered by this column, and every tour
            // seeded with the same 0 came out in insertion order, which reads as
            // random to a merchant. Each file now states where it belongs in the
            // learning sequence, and an update carries the value too, so changing
            // the order is a re-seed rather than a database edit.
            'ordering' => (int) ($tour['ordering'] ?? 0),
        ];
    }

    /**
     * What the seeder writes in the step rows from a tour file, in order: the steps for a
     * site of this shape (a step whose "when" names the other shape is left out); the tour
     * id, the ordering and the bookkeeping columns are the caller's.
     *
     * @param array $tour The definition from JSON.
     * @param string $shape The site's shape ({@see self::siteShape()}).
     *
     * @return array<int, array<string, mixed>>
     *
     * @since  2.1.0
     */
    private static function stepContents(array $tour, string $shape): array
    {
        $contents = [];

        foreach ($tour['steps'] as $step) {
            if (isset($step['when']) && (string) $step['when'] !== $shape) {
                continue;
            }

            $contents[] = [
                'title' => (string) ($step['title'] ?? ''),
                'description' => (string) ($step['description'] ?? ''),
                'position' => (string) ($step['position'] ?? 'bottom'),
                'target' => (string) ($step['target'] ?? ''),
                'type' => (int) ($step['type'] ?? 0),
                'interactive_type' => self::interactiveType($step),
                'url' => (string) ($step['url'] ?? ''),
                'params' => isset($step['params']) ? json_encode($step['params']) : '',
            ];
        }

        return $contents;
    }

    /**
     * The step's interactive type, always a value core can look up.
     *
     * A step that is not interactive still has to store SOMETHING in the column, and
     * the obvious filler (0) is the one value that is not a
     * `GuidedtoursComponent::STEP_INTERACTIVETYPE_*` constant (they run 1 to 6). The
     * tour plugin reads `$this->stepInteractiveType[$step->interactive_type]`
     * unguarded, so a 0 raises "Undefined array key 0"; on any site with error
     * display on, that warning is echoed BEFORE the JSON body of the tour's own
     * com_ajax endpoint, `response.json()` throws in guidedtours.js, and every tour
     * dies with "Oh no! It looks like your tour is off track." Core's own tours store
     * the form-submit constant as their filler, and so do ours.
     *
     * @param array $step One step as the JSON file declares it.
     *
     *
     * @since  2.0.0
     */
    private static function interactiveType(array $step): int
    {
        $type = (int) ($step['interactive_type'] ?? self::INTERACTIVE_TYPE_FILLER);

        return $type >= 1 && $type <= 6 ? $type : self::INTERACTIVE_TYPE_FILLER;
    }

    /**
     * Our tours currently in the DB, uid => id.
     *
     * @param DatabaseInterface $db The database.
     *
     * @return array<string, int>
     *
     * @since  2.0.0
     */
    private static function storedTours(DatabaseInterface $db): array
    {
        // OURS IS WHAT WE STAMPED. Ownership used to be "the uid starts with
        // com_alfa.", which forced every tour into that namespace, including a
        // plugin's, whose wording Joomla then could not find (it derives the
        // language file from the uid's extension prefix). The seeder writes
        // note = 'alfa:vN' on everything it creates, so that stamp is the honest
        // test and a plugin tour can be called plg_alfa_<name>.<tour> as Joomla
        // expects.
        $stamp = 'alfa:v%';
        $query = $db->getQuery(true)
            ->select($db->quoteName(['uid', 'id']))
            ->from($db->quoteName('#__guidedtours'))
            ->where($db->quoteName('note') . ' LIKE :stamp')
            ->bind(':stamp', $stamp, ParameterType::STRING);

        $map = [];

        foreach ((array) $db->setQuery($query)->loadObjectList() as $row) {
            $map[(string) $row->uid] = (int) $row->id;
        }

        return $map;
    }

    /**
     * Delete one of OUR tours: steps, row, and the per-user autostart/completion
     * keys that reference its id.
     *
     * @param DatabaseInterface $db The database.
     * @param int $id The tour id.
     *
     *
     * @since  2.0.0
     */
    private static function removeTour(DatabaseInterface $db, int $id): void
    {
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__guidedtour_steps'))
            ->where($db->quoteName('tour_id') . ' = :tour')
            ->bind(':tour', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__guidedtours'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $key = 'guidedtour.id.' . $id;
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('profile_key') . ' = :key')
            ->bind(':key', $key, ParameterType::STRING);
        $db->setQuery($query)->execute();
    }

    /**
     * The pipeline's EXPORT half (dev-facing Tools action): dump OUR tours from the
     * DB back into the JSON shape, one file per uid, into {@see self::TOURS_PATH}:
     * author visually in Joomla's Guided Tours UI, export, commit.
     *
     * seedAll() re-seeds a tour on a site only when its file's version differs from the
     * one the site was seeded with, so the version is what carries a change to the sites
     * that already have the tour. A tour still as its file seeded it is not written: its
     * file already says it, every site skips it, and only the file holds what the
     * database cannot (the steps for the other site shape). A tour changed since (in
     * Joomla's Guided Tours screen), or one whose file is gone, is written with a version
     * above both its stamp and its file: every site re-seeds it on the next update, and a
     * file whose version was raised by hand is never lowered. Every tour is read before
     * the first file is written, so a stamp this seeder does not write stops the export
     * with nothing written.
     *
     * @return string[] The written uids.
     *
     * @throws RuntimeException When the tours folder cannot be written, or a tour's
     *                          stamp is not one this seeder writes.
     *
     * @since  2.0.0
     */
    public static function exportAll(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if (!is_dir(self::TOURS_PATH) && !mkdir(self::TOURS_PATH, 0o755, true)) {
            throw new RuntimeException('tours folder is not writable');
        }

        $definitions = self::definitions();
        $payloads = [];

        foreach (self::storedTours($db) as $uid => $id) {
            $tourQuery = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__guidedtours'))
                ->where($db->quoteName('id') . ' = ' . (int) $id);
            $tour = $db->setQuery($tourQuery)->loadObject();

            $stepsQuery = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__guidedtour_steps'))
                ->where($db->quoteName('tour_id') . ' = ' . (int) $id)
                ->order($db->quoteName('ordering') . ' ASC');
            $steps = (array) $db->setQuery($stepsQuery)->loadObjectList();

            if (preg_match(self::STAMP_PATTERN, (string) $tour->note, $stamp) !== 1) {
                throw new RuntimeException(\sprintf(
                    '%s: the stamp "%s" is not one the seeder writes, so the version is unknown; nothing was exported (reseed the tours, then export)',
                    $uid,
                    (string) $tour->note,
                ));
            }

            $content = self::exportContent($tour, $steps);
            $definition = $definitions[$uid] ?? null;

            if ($definition !== null && $content === self::seededContent($definition, $stamp[2] ?? null)) {
                continue;
            }

            $version = max((int) $stamp[1], $definition === null ? 0 : self::fileVersion($definition)) + 1;

            $payloads[$uid] = ['uid' => $uid, 'version' => $version] + $content;
        }

        $written = [];

        foreach ($payloads as $uid => $payload) {
            $file = self::TOURS_PATH . '/' . str_replace(self::UID_PREFIX, '', $uid) . '.json';

            file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            $written[] = $uid;
        }

        return $written;
    }

    /**
     * The tour files seedAll() reads, by uid. A file it cannot read seeded nothing and is
     * left out.
     *
     * @return array<string, array> uid => definition.
     *
     * @since  2.1.0
     */
    private static function definitions(): array
    {
        $definitions = [];

        foreach (self::sources() as $file) {
            try {
                $tour = self::read($file);
            } catch (Throwable) {
                continue;                           // seedAll() reports it as an error
            }

            $definitions[(string) $tour['uid']] = $tour;
        }

        return $definitions;
    }

    /**
     * A tour file in the export's shape as the seeder writes it into the database: what
     * the database holds for a tour nobody has changed since it was seeded.
     *
     * @param array $definition The tour's file as seedAll() reads it.
     * @param string|null $shape The site shape of the tour's stamp (a tour with "when" steps).
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    private static function seededContent(array $definition, ?string $shape): array
    {
        return self::exportContent(
            (object) self::tourContent($definition),
            array_map(
                static fn (array $step): object => (object) $step,
                self::stepContents($definition, $shape ?? self::siteShape()),
            ),
        );
    }

    /**
     * A tour in its JSON shape, from its rows, without the uid and the version: what the
     * export writes and what it compares.
     *
     * @param object $tour The tour row.
     * @param object[] $steps Its step rows, in order.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    private static function exportContent(object $tour, array $steps): array
    {
        return [
            'title' => (string) $tour->title,
            'description' => (string) $tour->description,
            'extensions' => json_decode((string) $tour->extensions, true) ?: ['com_alfa'],
            'url' => (string) $tour->url,
            'autostart' => (bool) $tour->autostart,
            'steps' => array_map(static function ($step): array {
                $out = [
                    'title' => (string) $step->title,
                    'description' => (string) $step->description,
                    'position' => (string) $step->position,
                    'target' => (string) $step->target,
                    'type' => (int) $step->type,
                ];

                // Only when it says something: a non-interactive step carries the
                // filler, and writing that into every file would be noise the
                // reader has to learn to ignore.
                if (!\in_array((int) $step->interactive_type, [0, self::INTERACTIVE_TYPE_FILLER], true)) {
                    $out['interactive_type'] = (int) $step->interactive_type;
                }

                if ((string) $step->url !== '') {
                    $out['url'] = (string) $step->url;
                }

                $params = json_decode((string) $step->params, true);

                if (\is_array($params) && $params !== []) {
                    $out['params'] = $params;
                }

                return $out;
            }, $steps),
            // Its place in the "Take a Tour" list: a file without it is seeded at 0.
            'ordering' => (int) $tour->ordering,
        ];
    }
}
