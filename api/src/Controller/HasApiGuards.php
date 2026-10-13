<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\DeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Doctrine\Inflector\InflectorFactory;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Response\JsonResponse;
use RuntimeException;

/**
 * Shared guards for every com_alfa API controller.
 *
 * Three things core's ApiController does not do for us:
 *
 *   1. MODEL LOCK: core resolves the model from a client-supplied ?model=
 *      on every task, which would let a request repoint any endpoint at any
 *      admin model, for reads AND writes.
 *   2. AUTHORISATION: ApiDispatcher never calls checkAccess() and
 *      ComponentDispatcher::checkAccess() only fires for the administrator
 *      client, so without this a token with core.login.api reads and writes
 *      every entity. Gated against the alfa.<entity>.<action> actions that
 *      already exist in access.xml.
 *   3. RELATION PRESERVATION: core backfills only real table COLUMNS on
 *      PATCH, but the admin models read their relations as
 *      $data['categories'] ?? [] and setAssocsToDb() DELETEs every row before
 *      re-inserting. An omitted key therefore reads as "remove them all".
 *      Same idea as com_content re-loading tags in preprocessSaveData().
 *
 * @since  2.0.0
 */
trait HasApiGuards
{
    /**
     * Relation keys the admin model destroys when they are absent from a PATCH.
     * Each must be readable back off getItem() under the same name.
     *
     * A method, not a property: a trait property and a class property with
     * different defaults are a composition fatal in PHP.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    protected function preserveOnPatch(): array
    {
        return [];
    }

    /**
     * Execute a task behind the model lock and the ACL gate.
     *
     * A task refused because its order names no currency answers 409 with the
     * refusal's own sentence, as any refused save does; one whose order has no
     * row answers 404. Left to the fallback error handler either would read
     * as a bare "Internal server error".
     *
     * @param string $task The task to perform.
     *
     * @return mixed
     *
     * @throws Save When the task's order names no currency.
     * @throws ResourceNotFound When the task's order has no row.
     *
     * @since  2.0.0
     */
    public function execute($task)
    {
        $this->input->set('model', null);

        $this->assertAuthorisedForTask((string) $task);

        try {
            return parent::execute($task);
        } catch (OrderCurrencyException $e) {
            throw $e->orderExists()
                ? new Save($e->getMessage(), 409, $e)
                : new ResourceNotFound($e->getMessage(), 404, $e);
        }
    }

    /**
     * The access.xml section this controller is gated by.
     *
     *
     * @since  2.0.0
     */
    protected function aclSection(): string
    {
        return $this->contentType;
    }

    /**
     * Map the task to its permission and enforce it.
     *
     * Unmapped tasks fall through to core, which has its own checks; this
     * only widens coverage, never narrows it.
     *
     * @param string $task The task about to run.
     *
     *
     * @since  2.0.0
     */
    protected function assertAuthorisedForTask(string $task): void
    {
        static $map = [
            'displayList' => 'view',
            'displayItem' => 'view',
            'add' => 'create',
            'edit' => 'edit',
            'delete' => 'delete',
        ];

        if (isset($map[$task])) {
            $this->assertAuthorised($map[$task]);
        }
    }

    /**
     * Require alfa.<section>.<action>: the resource's own permission, through the one Alfa
     * check. Nothing Joomla's core rights grant counts: a Super User passes, everyone else
     * needs what a Super User granted in Options -> Permissions.
     *
     * @param string $action view|create|edit|edit.state|delete
     *
     *
     * @throws NotAllowed When the identity does not hold the permission.
     *
     * @since  2.0.0
     */
    protected function assertAuthorised(string $action): void
    {
        if (AlfaHelper::can(section: $this->aclSection(), action: $action, user: $this->app->getIdentity())) {
            return;
        }

        throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
    }

    /**
     * Method to allow extended classes to check the create permission.
     *
     * @param array $data The data to create.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function allowAdd($data = [])
    {
        return AlfaHelper::can(section: $this->aclSection(), action: 'create', user: $this->app->getIdentity());
    }

    /**
     * Method to allow extended classes to check the edit permission.
     *
     * @param array $data The data to edit.
     * @param string $key The record key name.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function allowEdit($data = [], $key = 'id')
    {
        return AlfaHelper::can(section: $this->aclSection(), action: 'edit', user: $this->app->getIdentity());
    }

    /**
     * Remove an item: core's delete, without its hard-coded core.delete check.
     *
     * The resource's own delete permission was already required by {@see self::execute()} and is
     * required again by the model's canDelete(); core's check would add a Global Configuration
     * right on top of both. Everything after that check is core's, except one answer: a
     * delete the model refused by its own rule (a category that still has children or is a
     * product's default, an entity whose delete would widen a discount, tax or cart rule)
     * answers 409 with the refusal's sentence as text.
     *
     * @param int $id The primary key to delete item.
     *
     * @return void
     *
     * @throws Save When the model refused the delete by its own rule; nothing was deleted.
     *
     * @since  2.0.0
     */
    public function delete($id = null)
    {
        if ($id === null) {
            $id = $this->input->get('id', 0, 'int');
        }

        $inflector = InflectorFactory::create()->build();
        $modelName = $this->input->get('model', $inflector->singularize($this->contentType));

        /** @var \Joomla\CMS\MVC\Model\AdminModel $model */
        $model = $this->getModel($modelName, '', ['ignore_request' => true]);

        if (!$model) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_MODEL_CREATE'));
        }

        if (!$model->delete($id)) {
            // Core's fallback handler would answer a bare 500 and hide the sentence.
            $refusal = $model->getError(null, false);

            if ($refusal instanceof DeleteRefusedException) {
                throw new Save($refusal->plain, 409);
            }

            if ($model->getError() !== false) {
                throw new RuntimeException($model->getError(), 500);
            }

            if ($this->app->getSession()->get('http_status_code_409', false)) {
                $this->app->getSession()->clear('http_status_code_409');
                $this->app->setHeader('status', 409, true);
                $this->app->setHeader('Content-Type', 'application/json');
                $this->app->sendHeaders();
                echo new JsonResponse([
                    'status' => 'Conflict',
                    'code' => 409,
                    'message' => 'Resource not in state that can be deleted, must be trashed before it can be deleted',
                ]);
                $this->app->close();
            }
        }

        if ($this->app->getSession()->get('http_status_code_404', false)) {
            $this->app->getSession()->clear('http_status_code_404');
            $this->app->setHeader('status', 404, true);
            $this->app->setHeader('Content-Type', 'application/json');
            $this->app->sendHeaders();
            echo new JsonResponse([
                'status' => 'not found',
                'code' => 404,
                'message' => 'Resource not found',
            ]);
            $this->app->close();
        }

        $this->app->setHeader('status', 204);
    }

    /**
     * Preserve omitted relations, then bridge the body to the jform POST var.
     *
     * The bridge is what makes multilingual writes work at all: the admin
     * models recover per-language keys (name_el_gr, ...) from
     * $input->post->get('jform'), which a JSON request never populates.
     *
     * @param array $data An array of input data.
     *
     *
     * @since  2.0.0
     */
    protected function preprocessSaveData(array $data): array
    {
        $data = $this->preserveAbsentRelations($data);
        $data = $this->flattenTranslations($data);
        $data = $this->normaliseMediaPayload($data);

        $this->input->post->set('jform', $data);

        return $data;
    }

    /**
     * Accept a declarative media list and translate it to the shape saveMedia
     * expects (an assoc array keyed by row id, or "new-..." for a new row).
     *
     * Clients should not have to know the internal transport: the raw contract
     * uses "new-..." string keys, a picker:/blob:/http prefix smuggled into
     * `source`, and positional ordering. This accepts
     *   [{id?, type, path|url|source, alt?, thumbnail?, ordering?, delete?}]
     * and still passes the native keyed map straight through.
     *
     * SEMANTICS ARE MERGE, not replace: a row you do not send is left alone,
     * and removing one needs an explicit "delete": 1, the same rule as the
     * relation preservation above, so no omission can ever destroy media.
     *
     * @param array $data The incoming save data.
     *
     *
     * @since  2.0.0
     */
    protected function normaliseMediaPayload(array $data): array
    {
        // A GET emits `medias`; the save path reads `media`. Accept either.
        if (!isset($data['media']) && isset($data['medias'])) {
            $data['media'] = $data['medias'];
        }

        unset($data['medias']);

        if (!isset($data['media']) || !\is_array($data['media'])) {
            unset($data['media']);

            return $data;
        }

        $rows = $data['media'];

        // Native shape (keyed by id / "new-..."): normalise each row, keep keys.
        if (!array_is_list($rows)) {
            $data['media'] = array_map(
                fn ($row): array => $this->normaliseMediaRow((array) $row),
                $rows,
            );

            return $data;
        }

        // Declarative list: honour a supplied ordering, since saveMedia itself
        // overwrites the stored ordering with the array index.
        usort(
            $rows,
            static fn ($a, $b): int => ((int) (((array) $a)['ordering'] ?? 0)) <=> ((int) (((array) $b)['ordering'] ?? 0)),
        );

        $normalised = [];

        foreach ($rows as $index => $row) {
            $row = (array) $row;
            $rowId = (int) ($row['id'] ?? 0);
            $rowKey = $rowId > 0 ? (string) $rowId : 'new-' . $index . '-' . bin2hex(random_bytes(4));

            $normalised[$rowKey] = $this->normaliseMediaRow($row);
        }

        $data['media'] = $normalised;

        return $data;
    }

    /**
     * Normalise one media row to the keys saveMedia reads unconditionally.
     *
     * @param array $row Client-supplied media row.
     *
     *
     * @since  2.0.0
     */
    protected function normaliseMediaRow(array $row): array
    {
        $type = (string) ($row['type'] ?? 'image');
        $source = (string) ($row['source'] ?? $row['url'] ?? $row['path'] ?? '');

        // A site-relative path has to arrive picker://-prefixed to take the
        // media-picker branch; HTMLHelper::cleanImageURL only rewrites a bare
        // "picker:" when the value happens to contain a query string.
        if (
            $source !== ''
            && $type !== 'url'
            && !str_starts_with($source, 'picker:')
            && !str_starts_with($source, 'blob:')
            && !preg_match('#^https?://#i', $source)
        ) {
            $source = 'picker://' . ltrim($source, '/');
        }

        $thumbnail = (string) ($row['thumbnail'] ?? '');

        // A GET reports thumbnail = path when no thumbnail is stored. Echoing
        // that back would persist the FULL-SIZE image as the thumbnail, so a
        // naive read-modify-write silently doubles listing page weight.
        if ($thumbnail !== '' && $thumbnail === ltrim((string) ($row['path'] ?? ''), '/')) {
            $thumbnail = '';
        }

        return [
            'source' => $source,
            'thumbnail' => $thumbnail,
            'alt' => (string) ($row['alt'] ?? ''),
            'type' => $type,
            'delete' => (int) ($row['delete'] ?? 0),
        ];
    }

    /**
     * Translate a nested translations map into the flat per-language keys the
     * save path understands.
     *
     * A GET emits attributes.translations = {"el_gr": {"name": "..."}, ...} while
     * the write path only ever understood name_el_gr, so a client could not
     * send back what it had just read. Both spellings of the tag are accepted
     * ("el-GR" and "el_gr"); an explicitly supplied flat key always wins over
     * the nested map.
     *
     * Note the field must still be present for the DEFAULT language on every
     * write when the form marks it required: MultilingualTextField::validate()
     * reads jform[<field>_<default>] straight out of the POST bag.
     *
     * @param array $data The incoming save data.
     *
     *
     * @since  2.0.0
     */
    protected function flattenTranslations(array $data): array
    {
        if (!isset($data['translations']) || !\is_array($data['translations'])) {
            // Never let the read-only key reach the model as an unknown field.
            unset($data['translations']);

            return $data;
        }

        foreach ($data['translations'] as $tag => $fields) {
            if (!\is_string($tag) || $tag === '' || !\is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $value) {
                if (!\is_string($field) || $field === '' || \is_array($value)) {
                    continue;
                }

                $flatKey = MultilingualHelper::buildLangFieldKey($field, $tag);

                if (!\array_key_exists($flatKey, $data)) {
                    $data[$flatKey] = $value;
                }
            }
        }

        unset($data['translations']);

        return $data;
    }

    /**
     * Re-load, from the stored record, every relation the body left out.
     *
     * PATCH only: on a create there is nothing to preserve. Absent means
     * "leave unchanged"; an explicitly supplied empty array still clears the
     * relation, which is the only way a client can empty one.
     *
     * @param array $data The incoming save data.
     *
     *
     * @since  2.0.0
     */
    protected function preserveAbsentRelations(array $data): array
    {
        $preserve = $this->preserveOnPatch();

        if ($preserve === [] || strtoupper((string) $this->input->getMethod()) !== 'PATCH') {
            return $data;
        }

        $recordId = (int) ($data['id'] ?? 0);

        if ($recordId <= 0) {
            return $data;
        }

        $missing = array_filter(
            $preserve,
            static fn (string $key): bool => !\array_key_exists($key, $data),
        );

        if ($missing === []) {
            return $data;
        }

        $inflector = InflectorFactory::create()->build();
        $model = $this->getModel($inflector->singularize($this->contentType));

        if (!$model) {
            return $data;
        }

        $current = $model->getItem($recordId);

        if (!$current) {
            return $data;
        }

        foreach ($missing as $key) {
            if (isset($current->$key)) {
                $data[$key] = $current->$key;
            }
        }

        return $data;
    }
}
