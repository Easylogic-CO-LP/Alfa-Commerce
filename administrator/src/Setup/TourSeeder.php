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
 * All human text in the files is LANGUAGE KEYS; the per-tour INIs live in the
 * component's own language folder, exactly where core's
 * GuidedtoursHelper::loadTranslationFiles() looks for `com_*` uids.
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
        $shape = SetupHelper::contentLanguages() === [] ? 'monolingual' : 'multilingual';
        $conditional = false;

        foreach ($tour['steps'] as $step) {
            if (isset($step['when'])) {
                $conditional = true;
                break;
            }
        }

        $stamp = 'alfa:v' . (int) ($tour['version'] ?? 1)
            . '/w' . self::WRITE_REVISION
            . ($conditional ? '+' . $shape : '');
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

        $row = (object) [
            'title' => (string) ($tour['title'] ?? $uid),
            'uid' => $uid,
            'description' => (string) ($tour['description'] ?? ''),
            'extensions' => json_encode($tour['extensions'] ?? ['com_alfa']),
            'url' => (string) ($tour['url'] ?? 'administrator/index.php?option=com_alfa'),
            'published' => 1,
            'language' => '*',
            'note' => $stamp,
            'access' => 1,
            'autostart' => !empty($tour['autostart']) ? 1 : 0,
            // The "Take a Tour" list is ordered by this column, and every tour
            // seeded with the same 0 came out in insertion order, which reads as
            // random to a merchant. Each file now states where it belongs in the
            // learning sequence, and an update carries the value too, so changing
            // the order is a re-seed rather than a database edit.
            'ordering' => (int) ($tour['ordering'] ?? 0),
            'modified' => $now,
            'modified_by' => $user,
        ];

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

        foreach ($tour['steps'] as $step) {
            if (isset($step['when']) && (string) $step['when'] !== $shape) {
                continue;
            }

            $stepRow = (object) [
                'tour_id' => $tourId,
                'title' => (string) ($step['title'] ?? ''),
                'published' => 1,
                'description' => (string) ($step['description'] ?? ''),
                'ordering' => $ordering++,
                'position' => (string) ($step['position'] ?? 'bottom'),
                'target' => (string) ($step['target'] ?? ''),
                'type' => (int) ($step['type'] ?? 0),
                'interactive_type' => self::interactiveType($step),
                'url' => (string) ($step['url'] ?? ''),
                'created' => $now,
                'created_by' => $user,
                'modified' => $now,
                'modified_by' => $user,
                'language' => '*',
                'note' => '',
                'params' => isset($step['params']) ? json_encode($step['params']) : '',
            ];

            $db->insertObject('#__guidedtour_steps', $stepRow);
        }

        return true;
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
     * @return string[] The written uids.
     *
     * @throws RuntimeException When the tours folder cannot be written.
     *
     * @since  2.0.0
     */
    public static function exportAll(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if (!is_dir(self::TOURS_PATH) && !mkdir(self::TOURS_PATH, 0o755, true)) {
            throw new RuntimeException('tours folder is not writable');
        }

        $written = [];

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

            $version = (int) (preg_match('/^alfa:v(\d+)$/', (string) $tour->note, $m) ? $m[1] : 1);

            $payload = [
                'uid' => $uid,
                'version' => $version,
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
            ];

            $file = self::TOURS_PATH . '/' . str_replace(self::UID_PREFIX, '', $uid) . '.json';

            file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            $written[] = $uid;
        }

        return $written;
    }
}
