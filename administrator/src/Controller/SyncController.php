<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Throwable;

/**
 * Maintenance controller for Alfa ↔ Joomla / multilingual sync tasks.
 *
 * Every task is AJAX (returns a Joomla JsonResponse) and drives a per-action
 * progress bar in the Tools view:
 *   - languages       — (re)create the per-language translation tables.
 *   - users           — bulk-sync Joomla users + usergroups into the Alfa tables.
 *   - backfillPlan     — list translatable tables + row counts for the backfill.
 *   - backfillChunk    — backfill one chunk of records for a single table.
 *
 * @since  1.0.1
 */
class SyncController extends BaseController
{
    /** Records processed per backfill AJAX chunk. */
    private const BACKFILL_CHUNK = 500;

    /**
     * AJAX: resync the per-language translation tables — ensure the auxiliary
     * lang tables (#__alfa_*_<langtag>) + columns exist for every translatable
     * entity (discovered from the form XML), across all installed content
     * languages. Idempotent — only creates missing tables / columns.
     *
     *
     * @since   1.0.1
     */
    public function languages(): void
    {
        $this->ajaxGuard();

        try {
            $result = SyncHelper::syncLanguageSchema();
            $created = 0;

            foreach ($result['tables'] as $tableSummary) {
                $created += count($tableSummary['created'] ?? []);
            }

            $message = Text::sprintf('COM_ALFA_SYNC_LANGUAGES_DONE', count($result['tables']), $created);

            if (!empty($result['errors'])) {
                $message .= ' ' . Text::sprintf('COM_ALFA_SYNC_LANGUAGES_ERRORS', implode(', ', array_keys($result['errors'])));
            }

            $this->ajaxRespond(['message' => $message]);
        } catch (Throwable $e) {
            $this->ajaxRespond([], false, $e->getMessage());
        }
    }

    /**
     * AJAX: bulk-sync Joomla users + usergroups into the Alfa tables (catches up
     * any that were created before the plugin was active, or outside the app).
     *
     *
     * @since   1.0.1
     */
    public function users(): void
    {
        $this->ajaxGuard();

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $users = SyncHelper::bulkSyncUsers($db);
            $groups = SyncHelper::bulkSyncUsergroups($db);

            $this->ajaxRespond(['message' => Text::sprintf('COM_ALFA_SYNC_USERS_DONE', $users, $groups)]);
        } catch (Throwable $e) {
            $this->ajaxRespond([], false, $e->getMessage());
        }
    }

    /**
     * AJAX: return the backfill plan — each translatable table with its PK,
     * field list and total record count — so the Tools view can drive the
     * chunked backfill and compute overall progress.
     *
     *
     * @since   1.0.1
     */
    public function backfillPlan(): void
    {
        $this->ajaxGuard();

        $db = Factory::getContainer()->get('DatabaseDriver');
        $schema = SyncHelper::getMultilingualSchema();
        $plan = [];

        foreach ($schema as $table => $definition) {
            $total = (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName($db->replacePrefix($table))),
            )->loadResult();

            $plan[] = [
                'table' => $table,
                'total' => $total,
                'chunk' => self::BACKFILL_CHUNK,
            ];
        }

        $this->ajaxRespond(['plan' => $plan]);
    }

    /**
     * AJAX: backfill one chunk of records for a single table. The PK + field
     * list are resolved server-side from the discovered schema (the client only
     * supplies the table name + offset) so they can't be tampered with.
     *
     *
     * @since   1.0.1
     */
    public function backfillChunk(): void
    {
        $this->ajaxGuard();

        $table = (string) $this->input->get('table', '', 'raw');
        $offset = (int) $this->input->get('offset', 0, 'int');
        $schema = SyncHelper::getMultilingualSchema();

        if (!isset($schema[$table])) {
            $this->ajaxRespond([], false, 'Unknown table');
            return;
        }

        try {
            $result = MultilingualHelper::backfillEmptyTranslations(
                tableName:         $table,
                primaryColumnName: $schema[$table]['pk'],
                fields:            $schema[$table]['fields'],
                aliasFields:       $schema[$table]['aliasFields'],
                aliasUniqueScope:  $schema[$table]['aliasScope'],
                offset:            $offset,
                limit:             self::BACKFILL_CHUNK,
            );

            $this->ajaxRespond([
                'table' => $table,
                'written' => $result['written'],
                'processed' => $result['processed'],
                'total' => $result['total'],
                'next' => $offset + self::BACKFILL_CHUNK,
                'done' => ($offset + self::BACKFILL_CHUNK) >= $result['total'],
            ]);
        } catch (Throwable $e) {
            $this->ajaxRespond([], false, $e->getMessage());
        }
    }

    /**
     * Shared AJAX guard: admin permission + CSRF token (accepted in GET or POST).
     *
     *
     * @since   1.0.1
     */
    private function ajaxGuard(): void
    {
        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            $this->ajaxRespond([], false, Text::_('JERROR_ALERTNOAUTHOR'));
            return;
        }

        if (!Session::checkToken('request')) {
            $this->ajaxRespond([], false, Text::_('JINVALID_TOKEN'));
            return;
        }
    }

    /**
     * Emit a Joomla JsonResponse ({success, message, data}) and close the app.
     *
     * @param array $data Payload (returned under `data` on success).
     * @param bool $success Success flag.
     * @param string $message Message (used for the error case).
     *
     *
     * @since   1.0.1
     */
    private function ajaxRespond(array $data = [], bool $success = true, string $message = ''): void
    {
        $this->app->setHeader('Content-Type', 'application/json', true);
        $this->app->sendHeaders();

        echo $success
            ? new JsonResponse($data, $message !== '' ? $message : null)
            : new JsonResponse(null, $message, true);

        $this->app->close();
    }
}
