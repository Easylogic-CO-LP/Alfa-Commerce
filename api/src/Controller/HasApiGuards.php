<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Extension\AlfaComponent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Catalog\DeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Media\MediaUrls;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Doctrine\Inflector\InflectorFactory;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Uri\Uri;
use Joomla\String\StringHelper;
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
 *      A PATCH also keeps the stored keys of a map it names only in part, and
 *      the stored translations of a required multilingual field it leaves out.
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
     * Keyed maps a PATCH merges into the stored map: a key the body
     * sends replaces that key, a key the body sends empty ('' or null) is
     * removed, and a key the body leaves out keeps its stored value. Each must
     * be readable back off getItem() under the same name, as an array.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function mergeOnPatch(): array
    {
        return [];
    }

    /**
     * Body keys this resource's write does not take. The admin models read more than their forms declare:
     * the jform bridge of {@see self::preprocessSaveData()} hands them the whole body, and
     * MultilingualHelper::mergeValidatedFormData() keeps every undeclared key, so a key the edit form's grids post
     * reaches the writer behind it from the API too. A POST or PATCH that carries one of these keys is refused with
     * 422, naming them, before anything is saved and before core's edit() checks the record out.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function unwrittenKeys(): array
    {
        return [];
    }

    /**
     * What the refusal of {@see self::unwrittenKeys()} says after their names: where they are written instead.
     *
     * @since  2.2.1
     */
    protected function unwrittenReason(): string
    {
        return '';
    }

    /**
     * Body keys this resource's update (PATCH) does not take, beyond {@see self::unwrittenKeys()}: where the
     * create and the update write different keys, the ones only the update leaves unwritten. A PATCH that carries one
     * is refused with 422 naming them, before anything is saved and before core's edit() checks the record out.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function unwrittenOnUpdate(): array
    {
        return [];
    }

    /**
     * Whether a record of this resource is deleted from the trash only: true where the
     * administrator's list moves a record to the trash before it deletes it, its Delete being the trashed view's Empty
     * trash. A DELETE of a record in any other state then answers 409 and deletes nothing; a PATCH of state -2 trashes
     * it first. Resources the administrator deletes without a trash step, and orders, keep their one-call delete.
     *
     * @since  2.2.1
     */
    protected function deletesFromTrash(): bool
    {
        return false;
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
     * Create an item: core's create, answered 201 Created with the new record's address in Location.
     *
     * Core answers a create with 200 and the record. The record in the body is the same; the status says that it
     * was created and the Location header where it now is (the collection's address and the new id).
     *
     * @return void
     *
     * @throws NotAllowed When the identity may not create the resource.
     * @throws Save When the save is refused.
     *
     * @since  2.2.1
     */
    public function add()
    {
        if (!$this->allowAdd()) {
            throw new NotAllowed('JLIB_APPLICATION_ERROR_CREATE_RECORD_NOT_PERMITTED', 403);
        }

        $this->refuseUnwrittenKeys(update: false);

        $recordId = (int) $this->save();

        // An image given by its address that was not stored is answered by its pointer, the new record's id beside it.
        if ($this->answerMediaRefusals(recordId: $recordId)) {
            return;
        }

        $this->displayItem($recordId);

        $this->app->setHeader('status', '201', true);
        $this->app->setHeader('Location', rtrim(Uri::current(), '/') . '/' . $recordId, true);
    }

    /**
     * Answer one record: core's, unless the write before it left an image given by its address unstored, which is
     * answered instead ({@see self::answerMediaRefusals()}).
     *
     * @param int|null $id The record.
     *
     * @return static
     *
     * @since  2.2.2
     */
    public function displayItem($id = null)
    {
        if ($this->answerMediaRefusals(recordId: (int) ($id ?? RequestScalar::read($this->input, 'id', 0, 'int')))) {
            return $this;
        }

        return parent::displayItem($id);
    }

    /**
     * Update a record: core's edit, refused first when the body carries a key the resource's update does not take
     * ({@see self::unwrittenKeys()}, {@see self::unwrittenOnUpdate()}), before core looks the record up and checks it
     * out, so a refused PATCH leaves it as it was.
     *
     * @return static
     *
     * @throws Save When the body carries an unwritten key (422).
     *
     * @since  2.2.1
     */
    public function edit()
    {
        $this->refuseUnwrittenKeys(update: true);

        return parent::edit();
    }

    /**
     * Answer 422, naming them, when the body carries keys of {@see self::unwrittenKeys()}, or on an update of
     * {@see self::unwrittenOnUpdate()}.
     *
     * @param bool $update Whether the write is an update (PATCH) rather than a create (POST).
     *
     * @throws Save When it does.
     *
     * @since  2.2.1
     */
    protected function refuseUnwrittenKeys(bool $update): void
    {
        $unwritten = $update ? array_merge($this->unwrittenKeys(), $this->unwrittenOnUpdate()) : $this->unwrittenKeys();
        $carried = array_values(array_intersect($unwritten, array_keys($this->requestBody())));

        if ($carried !== []) {
            throw new Save(implode(', ', $carried) . ' cannot be written through the API: ' . $this->unwrittenReason(), 422);
        }

        $this->refuseLanguageKeys();
    }

    /**
     * Answer 422, naming them, when the body names a text by a language suffix (name_el_gr): a write takes the
     * texts of each language in the translations object, {"translations": {"el_gr": {"name": "..."}}}, the object a
     * read with include=translations gives, so what a client reads it can send back as it is.
     *
     * @throws Save When the body carries a key ending in the suffix of a language of the site (422).
     *
     * @since  2.2.2
     */
    protected function refuseLanguageKeys(): void
    {
        $suffixes = array_map(static fn (string $tag): string => '_' . $tag, MultilingualHelper::getLanguageTags());
        $carried = array_values(array_filter(
            array_keys($this->requestBody()),
            static function ($key) use ($suffixes): bool {
                foreach ($suffixes as $suffix) {
                    if (\is_string($key) && \strlen($key) > \strlen($suffix) && str_ends_with($key, $suffix)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        if ($carried !== []) {
            $shown = array_map(fn (string $key): string => $this->shownKey(key: $key), $carried);

            throw new Save(implode(', ', $shown) . ' cannot be written through the API: send the texts of each language in'
                . ' translations, {"translations": {"en_gb": {"name": "..."}, "el_gr": {"name": "..."}}}, as a read with'
                . ' include=translations gives them. Nothing was saved.', 422);
        }
    }

    /**
     * A key the client sent, as a refusal shows it: its first 64 characters. A key is the client's text; it is
     * named so the client knows which one, and never repeated whole.
     *
     * @param string $key The key as sent.
     *
     * @since  2.2.2
     */
    protected function shownKey(string $key): string
    {
        return mb_strlen($key, 'UTF-8') > 64 ? mb_substr($key, 0, 64, 'UTF-8') . '...' : $key;
    }

    /**
     * The request body exactly as core's save() reads it.
     *
     * @since  2.0.0
     */
    protected function requestBody(): array
    {
        $body = $this->input->get(
            name: 'data',
            default: json_decode((string) $this->input->json->getRaw(), true),
            filter: 'array',
        );

        return \is_array($body) ? $body : [];
    }

    /**
     * Remove an item: core's delete, without its hard-coded core.delete check.
     *
     * The resource's own delete permission was already required by {@see self::execute()} and is
     * required again by the model's canDelete(); core's check would add a Global Configuration
     * right on top of both. Everything after that check is core's, except two answers: a
     * record of a resource deleted from the trash only that is not in the trash answers 409
     * ({@see self::deletesFromTrash()}), and a delete the model refused by its own rule
     * (a category that still has children or is a product's default, an entity whose delete
     * would widen a discount, tax or cart rule) answers 409 with the refusal's sentence as text.
     * A delete the model reports done while it kept the record is answered with the record
     * ({@see self::answerKept()}), never 204.
     *
     * @param int $id The primary key to delete item.
     *
     * @return void
     *
     * @throws Save When the record is not in the trash, or the model refused the delete by its own rule; nothing was
     *              deleted.
     *
     * @since  2.0.0
     */
    public function delete($id = null)
    {
        if ($id === null) {
            $id = RequestScalar::read($this->input, 'id', 0, 'int');
        }

        $inflector = InflectorFactory::create()->build();
        $modelName = RequestScalar::cmd($this->input, 'model', $inflector->singularize($this->contentType));

        /** @var \Joomla\CMS\MVC\Model\AdminModel $model */
        $model = $this->getModel($modelName, '', ['ignore_request' => true]);

        if (!$model) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_MODEL_CREATE'));
        }

        $this->refuseUntrashed(model: $model, id: (int) $id);

        // The ids the model reports deleted come back in $pks.
        $pks = [(int) $id];

        if (!$model->delete($pks)) {
            // Core's fallback handler would answer a bare 500 and hide the sentence.
            $refusal = $model->getError(null, false);

            if ($refusal instanceof DeleteRefusedException) {
                throw new Save($refusal->plain, 409);
            }

            if ($model->getError() !== false) {
                throw new RuntimeException($model->getError(), 500);
            }

            if ($this->app->getSession()->get('http_status_code_409', false)) {
                $this->app->getSession()->remove('http_status_code_409');
                $this->app->setHeader('status', '409', true);
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
            $this->app->getSession()->remove('http_status_code_404');
            $this->app->setHeader('status', '404', true);
            $this->app->setHeader('Content-Type', 'application/json');
            $this->app->sendHeaders();
            echo new JsonResponse([
                'status' => 'not found',
                'code' => 404,
                'message' => 'Resource not found',
            ]);
            $this->app->close();
        }

        // Done, and the record is not among the ids the model deleted: it kept it. The items model archives a product
        // that order lines were sold as instead of deleting it.
        if ((int) $id > 0 && !\in_array((int) $id, array_map('intval', (array) $pks), true)) {
            $this->answerKept(id: (int) $id);

            return;
        }

        $this->app->setHeader('status', '204');
    }

    /**
     * What meta.note says of a record a delete kept ({@see self::answerKept()}); '' for no note.
     *
     * @param int $id The record.
     *
     * @since  2.2.1
     */
    protected function keptNote(int $id): string
    {
        return '';
    }

    /**
     * Answer a delete that kept its record: 200 with the record as it now stands and meta.note saying why
     * ({@see self::keptNote()}), where a 204 would say it is gone.
     *
     * @param int $id The record.
     *
     * @since  2.2.1
     */
    private function answerKept(int $id): void
    {
        $note = $this->keptNote(id: $id);

        if ($note !== '') {
            $this->app->getDocument()->addMeta('note', $note);
        }

        $this->displayItem($id);
        $this->app->setHeader('status', '200', true);
    }

    /**
     * Answer 409 when this resource is deleted from the trash only ({@see self::deletesFromTrash()}) and the record is
     * not in the trash, before the model is asked: nothing is deleted, archived or checked. A record that does not
     * exist is left to the delete, which answers 404.
     *
     * @param AdminModel $model The resource's model.
     * @param int $id The record.
     *
     * @throws Save When the record exists and is not in the trash (409).
     *
     * @since  2.2.1
     */
    private function refuseUntrashed(AdminModel $model, int $id): void
    {
        if (!$this->deletesFromTrash() || $id <= 0) {
            return;
        }

        $table = $model->getTable();

        if (!$table->load($id)) {
            return;
        }

        $state = $table->getColumnAlias('published');

        if ((int) $table->{$state} === AlfaComponent::CONDITION_TRASHED) {
            return;
        }

        throw new Save(
            'Only a record in the trash is deleted, as in the administrator: move it to the trash first (PATCH v1/alfa/'
            . $this->contentType . '/' . $id . ' with state -2), then delete it. Nothing was deleted.',
            409,
        );
    }

    /**
     * Preserve omitted relations, merge partly named maps and keep the stored
     * translations of an omitted required field, then bridge the body to the
     * jform POST var.
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
        $data = $this->mergePatchedMaps($data);
        $data = $this->flattenTranslations($data);
        $data = $this->keepStoredRequiredTranslations($data);
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
        $sentAs = isset($data['media']) ? 'media' : 'medias';

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
            $normalised = [];

            foreach ($rows as $rowKey => $row) {
                $normalised[$rowKey] = $this->normaliseMediaRow((array) $row) + ['pointer' => '/' . $sentAs . '/' . $rowKey . '/url'];
            }

            $data['media'] = $this->fetchedMedia(rows: $normalised, recordId: (int) ($data['id'] ?? 0));

            return $data;
        }

        // Each row's place in the request, for a refusal's pointer, before the rows are put in order.
        foreach ($rows as $index => $row) {
            $rows[$index] = (array) $row + ['__sent' => $index];
        }

        // Declarative list: honour a supplied ordering, since saveMedia itself
        // overwrites the stored ordering with the array index.
        usort(
            $rows,
            static fn ($a, $b): int => ((int) (((array) $a)['ordering'] ?? 0)) <=> ((int) (((array) $b)['ordering'] ?? 0)),
        );

        $normalised = [];

        foreach ($rows as $index => $row) {
            $rowId = (int) ($row['id'] ?? 0);
            $rowKey = $rowId > 0 ? (string) $rowId : 'new-' . $index . '-' . bin2hex(random_bytes(4));

            $normalised[$rowKey] = $this->normaliseMediaRow($row) + ['pointer' => '/' . $sentAs . '/' . $row['__sent'] . '/url'];
        }

        $data['media'] = $this->fetchedMedia(rows: $normalised, recordId: (int) ($data['id'] ?? 0));

        return $data;
    }

    /**
     * The images the write gives as addresses, brought to the upload path ({@see MediaUrls::prepare()}): one already
     * fetched for the record is not fetched again, one or two are fetched now, before the save, and more are left for
     * the background job the save writes. An image refused is answered by its pointer once the save is done
     * ({@see self::answerMediaRefusals()}).
     *
     * @param array<string, array> $rows The media rows, keyed as the save reads them.
     * @param int $recordId The record, 0 for one the write creates.
     *
     * @return array<string, array>
     *
     * @since  2.2.2
     */
    protected function fetchedMedia(array $rows, int $recordId): array
    {
        $model = $this->getModel(InflectorFactory::create()->build()->singularize($this->contentType), '', ['ignore_request' => true]);

        return MediaUrls::prepare(rows: $rows, origin: \is_object($model) ? (string) $model->getName() : '', recordId: $recordId);
    }

    /**
     * Answer the images of a write that were not fetched or not stored: 422, one error each with its JSON pointer
     * into the request, the address and the reason, and the record's id in meta.id, since the record was saved with
     * everything else the write sent.
     *
     * @param int $recordId The record the write saved.
     *
     * @return bool Whether an image was refused and the answer is written.
     *
     * @since  2.2.2
     */
    protected function answerMediaRefusals(int $recordId): bool
    {
        $refused = MediaUrls::refusals();

        if ($refused === []) {
            return false;
        }

        $errors = [];

        foreach ($refused as $one) {
            $errors[] = [
                'title' => $one['pointer'] . ': the image at ' . $one['url'] . ' was not stored: ' . $one['reason']
                    . '. The record was saved with everything else the request sent.',
                'code' => 422,
                'source' => ['pointer' => $one['pointer']],
            ];
        }

        // The error document Joomla's renderer writes, beside the record's id, which it cannot carry.
        $document = $this->app->getDocument();
        $document->setErrors($errors);
        $document->addMeta('id', $recordId);
        $this->app->setHeader('status', '422', true);

        return true;
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

        // An image given by its address (type image with url, no path): the shop fetches it and stores it as an upload
        // (MediaUrls). type url keeps meaning an outside link shown from its address.
        if ($type === 'image' && (string) ($row['url'] ?? '') !== '' && ($row['path'] ?? null) === null && ($row['source'] ?? null) === null && empty($row['id'])) {
            $alt = (string) ($row['alt'] ?? '');

            if (StringHelper::strlen($alt) > MediaHelper::ALT_MAX_LENGTH) {
                throw new Save(Text::sprintf('COM_ALFA_MEDIA_ALT_TOO_LONG_API', MediaHelper::ALT_MAX_LENGTH), 400);
            }

            return ['source' => '', 'thumbnail' => '', 'alt' => $alt, 'type' => 'image', 'delete' => 0, 'fetch' => trim((string) $row['url'])];
        }

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

        $alt = (string) ($row['alt'] ?? '');

        // The column holds 75 characters (MediaHelper::ALT_MAX_LENGTH): a longer description is refused in plain words
        // before anything is saved, as the form's own input stops at that length.
        if (StringHelper::strlen($alt) > MediaHelper::ALT_MAX_LENGTH) {
            throw new Save(Text::sprintf('COM_ALFA_MEDIA_ALT_TOO_LONG_API', MediaHelper::ALT_MAX_LENGTH), 400);
        }

        return [
            'source' => $source,
            'thumbnail' => $thumbnail,
            'alt' => $alt,
            'type' => $type,
            'delete' => (int) ($row['delete'] ?? 0),
        ];
    }

    /**
     * Translate a nested translations map into the flat per-language keys the
     * save path understands.
     *
     * A GET with include=translations emits attributes.translations =
     * {"el_gr": {"name": "..."}, ...}, and a write takes the same object: the
     * one way a write names a text in a language (a key with a language
     * suffix, name_el_gr, is refused by {@see self::refuseLanguageKeys()}).
     * Both spellings of the tag are accepted ("el-GR" and "el_gr").
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
            // Never let a translations that is not an object reach the model as an unknown field.
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
        $missing = array_filter(
            $this->preserveOnPatch(),
            static fn (string $key): bool => !\array_key_exists($key, $data),
        );
        $current = $missing === [] ? null : $this->patchedRecord($data);

        if ($current === null) {
            return $data;
        }

        foreach ($missing as $key) {
            if (isset($current->$key)) {
                $data[$key] = $current->$key;
            }
        }

        return $data;
    }

    /**
     * Lay the keys of each map the body sends over the stored map.
     *
     * PATCH only, for the maps mergeOnPatch() names: a body that names one
     * key of the map changes that key and keeps the others, and a key it sends
     * empty ('' or null) leaves the map, so the record takes the shop's setting
     * for it. It leaves here, before the form's filter, which gives an empty
     * value the field's default when the field has one. The edit form posts the
     * whole map and does not come this way; on a create there is nothing stored
     * to merge into.
     *
     * @param array $data The incoming save data.
     *
     *
     * @since  2.2.1
     */
    protected function mergePatchedMaps(array $data): array
    {
        // Core backfills a column the body leaves out with its stored text, never an array: an array is what the
        // body sent.
        $sent = array_filter(
            $this->mergeOnPatch(),
            static fn (string $key): bool => \is_array($data[$key] ?? null),
        );
        $current = $sent === [] ? null : $this->patchedRecord($data);

        if ($current === null) {
            return $data;
        }

        foreach ($sent as $key) {
            $stored = $current->$key ?? [];
            $data[$key] = array_filter(
                array_replace(\is_array($stored) ? $stored : [], $data[$key]),
                static fn (mixed $value): bool => $value !== '' && $value !== null,
            );
        }

        return $data;
    }

    /**
     * Keep the stored translations of every required multilingual field a
     * PATCH leaves out.
     *
     * The form's required rule reads the default language's value, which a
     * PATCH that changes only, say, the state does not send, so such a PATCH
     * was refused with "Field required". A field the body names, in any
     * language or empty, is left to the rule, and a create is never helped:
     * the required rule still applies to both. The translations are read from
     * the field's own language tables and written back as they are.
     *
     * @param array $data The incoming save data, its translations flattened.
     *
     *
     * @since  2.2.1
     */
    protected function keepStoredRequiredTranslations(array $data): array
    {
        $recordId = (int) ($data['id'] ?? 0);

        if ($recordId <= 0 || strtoupper((string) $this->input->getMethod()) !== 'PATCH') {
            return $data;
        }

        $model = $this->getModel(InflectorFactory::create()->build()->singularize($this->contentType));

        if (!$model instanceof AdminModel) {
            return $data;
        }

        // Core adds the component's form path after this step (ApiController::save()); the form is read here first.
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/' . $this->option . '/forms');
        $form = $model->getForm([], false);

        if (!$form) {
            return $data;
        }

        $languages = MultilingualHelper::getLanguageTags();

        // The fields stored in language tables of their own, read off the form's definition: no field is set up here.
        foreach ($form->getXml()->xpath('//field[@multilingual_table and @multilingual_pk]') ?: [] as $element) {
            if (!\in_array((string) $element['required'], ['true', 'required', '1'], true)) {
                continue;
            }

            $name = (string) $element['name'];
            $keys = array_map(
                static fn (string $language): string => MultilingualHelper::buildLangFieldKey(field: $name, tag: $language),
                $languages,
            );

            // A field the body names, in any language or empty: the rule decides.
            if (\array_key_exists($name, $data) || array_intersect_key($data, array_flip($keys)) !== []) {
                continue;
            }

            $stored = MultilingualHelper::getMultilingualDataFlat(
                currentId:         $recordId,
                primaryColumnName: (string) $element['multilingual_pk'],
                tableName:         (string) $element['multilingual_table'],
            );

            foreach ($keys as $key) {
                if (\array_key_exists($key, $stored)) {
                    $data[$key] = $stored[$key];
                }
            }
        }

        return $data;
    }

    /**
     * The stored record a PATCH changes, read through the resource's edit
     * model (shared by the steps that keep what the body leaves out).
     *
     * @param array $data The incoming save data.
     *
     * @return object|null Null on a create, for a resource without an edit
     *                     model, or when the record does not exist.
     *
     * @since  2.2.1
     */
    private function patchedRecord(array $data): ?object
    {
        $recordId = (int) ($data['id'] ?? 0);

        if ($recordId <= 0 || strtoupper((string) $this->input->getMethod()) !== 'PATCH') {
            return null;
        }

        $model = $this->getModel(InflectorFactory::create()->build()->singularize($this->contentType));

        // Only an edit model reads the stored record (every model this path reaches is one).
        $current = $model instanceof AdminModel ? $model->getItem($recordId) : false;

        return \is_object($current) ? $current : null;
    }
}
