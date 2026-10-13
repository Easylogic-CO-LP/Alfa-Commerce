<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller\Traits;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

defined('_JEXEC') or die;

/**
 * Shared plumbing for admin list inline quick-edit endpoints (inlineLoad / inlineSave).
 *
 * Provides the JSON envelope, request-body parsing, and the CSRF+ACL gate so each
 * list controller only implements its own field read/write. The response shape
 * mirrors OrderController::updateOrder so the client (inline-edit.js) is uniform:
 *   { success: bool, message: string, data: array|null, messages: array }
 *
 * @since  2.0.0
 */
trait HasInlineEditTrait
{
    /**
     * CSRF (token rides in the query, like inline-edit.js posts it) + the edit permission of
     * the controller's own section (items, categories or manufacturers, from `view_item`).
     * Throws so the caller's try/catch converts it into a JSON error.
     *
     *
     * @throws RuntimeException When the token is invalid or the user cannot edit.
     *
     * @since  2.0.0
     */
    protected function assertInlineAuthorised(): void
    {
        if (!Session::checkToken('request')) {
            throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        if (!AlfaHelper::can(section: $this->view_item, action: 'edit', user: Factory::getApplication()->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }
    }

    /**
     * Assert the record exists and is not checked out by another user, so
     * inline quick-edit can neither write to a row that is gone nor silently
     * overwrite someone else's in-progress edit.
     *
     * The existence half matters: without it a save against a deleted id runs a
     * zero-row UPDATE and still reports success with a fabricated display value.
     *
     * @param int $id Record id.
     * @param string $table Table with prefix (e.g. "#__alfa_items").
     * @param string $pkColumn Primary-key column (default "id").
     *
     *
     * @throws RuntimeException When the row is missing or checked out by a different user.
     *
     * @since  2.0.0
     */
    protected function assertRecordUnlocked(int $id, string $table, string $pkColumn = 'id'): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $load = static function (array $columns) use ($db, $table, $pkColumn, $id): ?object {
            return $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName($columns))
                    ->from($db->quoteName($table))
                    ->where($db->quoteName($pkColumn) . ' = :id')
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->loadObject();
        };

        try {
            $row = $load([$pkColumn, 'checked_out']);
        } catch (Throwable $e) {
            // Table without a checkout column: only existence can be guarded.
            $row = $load([$pkColumn]);
        }

        if ($row === null) {
            throw new RuntimeException(Text::_('COM_ALFA_INLINE_RECORD_MISSING'), 404);
        }

        $checkedOut = (int) ($row->checked_out ?? 0);
        $userId = (int) Factory::getApplication()->getIdentity()->id;

        if ($checkedOut > 0 && $checkedOut !== $userId) {
            throw new RuntimeException(Text::_('COM_ALFA_INLINE_LOCKED'), 409);
        }
    }

    /**
     * Render the on-demand editor for a translatable field: one input per
     * installed content language, values exactly as stored (no fallback) so the
     * editor shows empty where a translation is missing.
     *
     * Shared by every list that offers inline rename: the markup lives once,
     * in layouts/inline/*, and matches MultilingualTextField in the edit form.
     *
     * @param int $id Record id.
     * @param string $table Base table with prefix (e.g. "#__alfa_categories").
     * @param string $primaryColumn Aux-table primary column (e.g. "id_category").
     * @param string $field Translatable field name (e.g. "name").
     *
     * @return string The rendered editor chrome (inputs + Save/Cancel).
     *
     * @since  2.0.0
     */
    protected function renderInlineMultilingualEditor(
        int $id,
        string $table,
        string $primaryColumn,
        string $field,
    ): string {
        $languages = MultilingualHelper::getFieldTranslations(
            id:                $id,
            tableName:         $table,
            primaryColumnName: $primaryColumn,
            field:             $field,
        );

        // Attach the language flag (same source as MultilingualTextField) so the
        // inline editor matches the in-form multilingual look.
        $meta = LanguageHelper::getLanguages('lang_code');

        foreach ($languages as &$language) {
            $info = $meta[$language['tag']] ?? null;

            $language['flag'] = [
                'img' => ($info && !empty($info->image))
                    ? Uri::root() . 'media/mod_languages/images/' . $info->image . '.gif'
                    : null,
                'sef' => $info->sef ?? '',
                'title' => $info->title_native ?? ($language['label'] ?? ''),
            ];
        }
        unset($language);

        $base = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';

        $inner = LayoutHelper::render(
            'inline.multilingual',
            ['languages' => $languages, 'single' => \count($languages) <= 1],
            $base,
        );

        return LayoutHelper::render('inline.editor', ['inner' => $inner], $base);
    }

    /**
     * Persist a translatable field edited from a list, then return the value to
     * show in the cell (the current admin language, i.e. what the column renders).
     *
     * Only the edited field is written: MultilingualHelper::upsertLangRow updates
     * column-by-column, so the row's other translations survive.
     *
     * @param int $id Record id.
     * @param string $table Base table with prefix (e.g. "#__alfa_categories").
     * @param string $primaryColumn Aux-table primary column (e.g. "id_category").
     * @param string $field Translatable field name (e.g. "name").
     * @param array $values Posted per-language values keyed by language tag.
     * @param string[] $aliasFields Slug fields for the table (MultilingualAliasConfig::FIELDS).
     * @param string[] $aliasScope Main-table columns scoping alias uniqueness (::SCOPE).
     *
     * @return string The value to display in the cell after the save.
     *
     * @throws RuntimeException When nothing was posted or the write failed.
     *
     * @since  2.0.0
     */
    protected function saveInlineMultilingualField(
        int $id,
        string $table,
        string $primaryColumn,
        string $field,
        array $values,
        array $aliasFields = [],
        array $aliasScope = [],
    ): string {
        if ($values === []) {
            throw new RuntimeException(Text::_('COM_ALFA_ERROR_NO_DATA'));
        }

        $data = [];

        foreach ($values as $tag => $value) {
            $data[MultilingualHelper::buildLangFieldKey($field, (string) $tag)] = (string) $value;
        }

        $saved = MultilingualHelper::saveMultilingualData(
            currentId:         $id,
            primaryColumnName: $primaryColumn,
            tableName:         $table,
            data:              $data,
            aliasFields:       $aliasFields,
            aliasUniqueScope:  $aliasScope,
        );

        if (!$saved) {
            throw new RuntimeException(Text::_('COM_ALFA_INLINE_SAVE_FAILED'));
        }

        return MultilingualHelper::getTranslatedValue(
            id:                $id,
            tableName:         $table,
            primaryColumnName: $primaryColumn,
            field:             $field,
        );
    }

    /**
     * Decode the JSON request body sent by inline-edit.js.
     *
     * @return array The decoded payload (empty array when the body is absent/invalid).
     *
     * @since  2.0.0
     */
    protected function readInlineJsonBody(): array
    {
        $decoded = json_decode((string) file_get_contents('php://input'), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Emit the standard inline-edit JSON envelope and close the application.
     * Captures the message queue enqueued during the operation (stock info,
     * warnings, ...) so the client can surface them.
     *
     * @param bool $success Whether the operation succeeded.
     * @param string $message Human-readable outcome.
     * @param array|null $data Optional payload (e.g. ['display' => '...']).
     *
     *
     * @since  2.0.0
     */
    protected function sendInlineJson(bool $success, string $message, ?array $data = null): void
    {
        $app = Factory::getApplication();

        $response = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'messages' => $app->getMessageQueue(true),
        ];

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo json_encode($response);
        $app->close();
    }
}
