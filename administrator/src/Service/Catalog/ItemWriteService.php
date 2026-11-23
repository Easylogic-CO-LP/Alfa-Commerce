<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Model\ItemModel;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Factory;
use RuntimeException;
use Throwable;

/**
 * The one way a product is saved: the item screen, the REST API and an extension's code build the same write and
 * call save(), which runs the item save (ItemModel::write(), one transaction) and answers an ItemWriteResult.
 *
 *     $result = ItemWriteService::create()
 *         ->set('sku', 'SMX-128')
 *         ->set('name_en_gb', 'Smartphone X')
 *         ->set('categories', [3])
 *         ->save();
 *
 *     $result = ItemWriteService::change(42)->set('sku', 'SMX-128-B')->save();
 *
 * The fields are the item form's (administrator/forms/item.xml), a translated one by its language key (name_en_gb).
 * A write built in code is checked by the item form as the screen's is; on a change, what it leaves out keeps its
 * stored value, as a REST PATCH keeps it. The caller checks its own permission: the service writes what it is given.
 *
 * @api CHANGE CATALOGUE: save a product with the item save's rules, refusals and transaction.
 *
 * @since  2.3.0
 */
final class ItemWriteService
{
    /**
     * The lists a product's save empties when its data leaves them out; a change built in code, and a REST PATCH, keep
     * the stored list instead.
     *
     * @since  2.3.0
     */
    public const KEPT_WHEN_ABSENT = ['categories', 'manufacturers', 'allowedUsers', 'allowedUserGroups', 'prices', 'robots', 'related'];

    /**
     * The stored fields a change never lays back when it leaves them out: the stock figures, the images, and the lists
     * the variants and options are written by.
     *
     * @since  2.3.0
     */
    private const NEVER_LAID_BACK = ['stock', 'inventory_stock', 'inventory_stock_baseline', 'available_quantity', 'media', 'options', 'combinations',
        'informational', 'combinations_default', 'deleted_combination_ids', 'deleted_option_value_ids', 'deleted_info_value_ids', 'info_values',
        'field_order', 'field_required', 'item_downloads'];

    /**
     * The fields given, by the item form's names.
     *
     * @var array<string, mixed>
     * @since  2.3.0
     */
    private array $data = [];

    /**
     * The form as posted, when a controller gives the data it validated; null for a write built in code.
     *
     * @var array<string, mixed>|null
     * @since  2.3.0
     */
    private ?array $form = null;

    /**
     * The image files uploaded with the form.
     *
     * @since  2.3.0
     */
    private array $files = [];

    /**
     * Whether the write saves a copy, and the product it copies.
     *
     * @since  2.3.0
     */
    private bool $copy = false;

    /**
     * @since  2.3.0
     */
    private int $copyOf = 0;

    /**
     * The warnings the save gives as it goes.
     *
     * @var list<array{message: string, type: string}>
     * @since  2.3.0
     */
    private array $warnings = [];

    /**
     * @param int $id The product changed; 0 for a new one.
     *
     * @since  2.3.0
     */
    private function __construct(private readonly int $id)
    {
    }

    /**
     * A new product.
     *
     * @since  2.3.0
     */
    public static function create(): self
    {
        return new self(id: 0);
    }

    /**
     * A change of a product.
     *
     * @param int $id The product.
     *
     * @since  2.3.0
     */
    public static function change(int $id): self
    {
        return new self(id: max(0, $id));
    }

    /**
     * One field, by the item form's name.
     *
     * @param string $field The field (a translated one by its language key, name_en_gb).
     * @param mixed $value Its value.
     *
     * @since  2.3.0
     */
    public function set(string $field, mixed $value): self
    {
        $this->data[$field] = $value;

        return $this;
    }

    /**
     * Several fields, by the item form's names.
     *
     * @param array<string, mixed> $fields The fields.
     *
     * @since  2.3.0
     */
    public function with(array $fields): self
    {
        $this->data = $fields + $this->data;

        return $this;
    }

    /**
     * The data a controller validated through the item form, with the form as posted (its language keys, the stock
     * per location and the figures the screen showed, which the form's filter leaves out).
     *
     * @param array<string, mixed> $data The validated data.
     * @param array<string, mixed> $form The form as posted.
     *
     * @since  2.3.0
     */
    public function validated(array $data, array $form): self
    {
        $this->data = $data;
        $this->form = $form;

        return $this;
    }

    /**
     * Image files uploaded with the form, as it posts them.
     *
     * @param array $files The files.
     *
     * @since  2.3.0
     */
    public function files(array $files): self
    {
        $this->files = $files;

        return $this;
    }

    /**
     * Save a copy of a product: its addresses are made its own.
     *
     * @param int $originalId The product copied.
     *
     * @since  2.3.0
     */
    public function asCopyOf(int $originalId): self
    {
        $this->copy = true;
        $this->copyOf = $originalId;

        return $this;
    }

    /**
     * Save the product.
     *
     * @param ItemModel|null $model The item model of the controller that saves (it keeps the saved id and the errors
     *                              for Joomla's controller); null for a write built in code.
     *
     * @since  2.3.0
     */
    public function save(?ItemModel $model = null): ItemWriteResult
    {
        $this->warnings = [];
        $tasksBefore = Tasks::requestNotes();

        if ($model === null) {
            $model = self::freshModel();

            if ($this->form === null && !$this->prepare(model: $model)) {
                return new ItemWriteResult(saved: false, id: $this->id, refusals: self::errorsOf(model: $model));
            }
        }

        $saved = $model->write(write: $this);
        $id = $saved ? (int) $model->getState($model->getName() . '.id') : $this->id;

        return new ItemWriteResult(
            saved: $saved,
            id: $id,
            refusals: $saved ? [] : self::errorsOf(model: $model),
            warnings: $this->warnings,
            tasks: array_values(array_map('intval', array_keys(array_diff_key(Tasks::requestNotes(), $tasksBefore)))),
        );
    }

    /**
     * The data the save writes.
     *
     * @internal ItemModel::write() reads the write.
     *
     * @since  2.3.0
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * The form as posted; for a write built in code, its own fields.
     *
     * @internal ItemModel::write() reads the write.
     *
     * @since  2.3.0
     */
    public function form(): array
    {
        return $this->form ?? $this->data;
    }

    /**
     * @internal ItemModel::write() reads the write.
     *
     * @since  2.3.0
     */
    public function uploads(): array
    {
        return $this->files;
    }

    /**
     * @internal ItemModel::write() reads the write.
     *
     * @since  2.3.0
     */
    public function isCopy(): bool
    {
        return $this->copy;
    }

    /**
     * @internal ItemModel::write() reads the write.
     *
     * @since  2.3.0
     */
    public function copyOf(): int
    {
        return $this->copyOf;
    }

    /**
     * Note a warning of the save, for the caller to show.
     *
     * @internal ItemModel::write() reports through it.
     *
     * @param string $msg The sentence.
     * @param string $type warning or error.
     *
     * @since  2.3.0
     */
    public function warn(string $msg, string $type = 'warning'): void
    {
        $this->warnings[] = ['message' => $msg, 'type' => $type];
    }

    /**
     * A write built in code: on a change, what it leaves out taken from the stored product (the lists the save would
     * empty, the translated fields in every language); then checked by the item form as the screen's data is.
     *
     * @param ItemModel $model The model the save runs on.
     *
     * @return bool False when the form refuses the data (the model's errors say why).
     *
     * @since  2.3.0
     */
    private function prepare(ItemModel $model): bool
    {
        $data = $this->data;

        if ($this->id > 0) {
            $stored = $model->getItem($this->id);

            if (!\is_object($stored) || (int) ($stored->id ?? 0) !== $this->id) {
                $model->setError('Product ' . $this->id . ' does not exist.');

                return false;
            }

            $data['id'] = $this->id;

            // The product's own fields as stored, as a REST PATCH takes them: never a translated field, a stock figure (an instruction
            // only when the caller sends it), the images (a row not sent stays) or the variants' and options' lists
            // (written on their own paths).
            $form = $model->getForm(data: [], loadData: false);

            foreach ($form ? $form->getFieldset() : [] as $field) {
                $key = (string) $field->getAttribute('name');

                // A translated field is stored per language; the required ones are taken below.
                if ((string) $field->getAttribute('multilingual_table') !== '') {
                    continue;
                }

                if (!\array_key_exists($key, $data) && !\in_array($key, self::NEVER_LAID_BACK, true) && isset($stored->$key)) {
                    $data[$key] = $stored->$key;
                }
            }

            foreach (self::KEPT_WHEN_ABSENT as $key) {
                if (!\array_key_exists($key, $data) && isset($stored->$key)) {
                    $data[$key] = $stored->$key;
                }
            }

            // A required translated field left out takes its stored text in every language, as a REST PATCH takes
            // it; the others are left to the save, which keeps what it is not sent.
            $texts = MultilingualHelper::getMultilingualDataFlat(currentId: $this->id, primaryColumnName: 'id_item', tableName: '#__alfa_items');

            foreach ($form ? $form->getXml()->xpath('//field[@multilingual_table and @multilingual_pk]') ?: [] : [] as $element) {
                if (!\in_array((string) $element['required'], ['true', 'required', '1'], true)) {
                    continue;
                }

                foreach (MultilingualHelper::getLanguageTags() as $language) {
                    $key = MultilingualHelper::buildLangFieldKey(field: (string) $element['name'], tag: $language);

                    if (!\array_key_exists($key, $data) && \array_key_exists($key, $texts)) {
                        $data[$key] = $texts[$key];
                    }
                }
            }
        }

        $form = $model->getForm(data: $data, loadData: false);

        if (!$form) {
            return false;
        }

        $valid = $model->validate($form, $data);

        if ($valid === false) {
            return false;
        }

        $this->data = $valid;
        $this->form = $data;

        return true;
    }

    /**
     * The administrator's item model, without the request's state.
     *
     * @since  2.3.0
     */
    private static function freshModel(): ItemModel
    {
        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Item', 'Administrator', ['ignore_request' => true]);

        if (!$model instanceof ItemModel) {
            throw new RuntimeException('The item model of Alfa Commerce could not be made.');
        }

        return $model;
    }

    /**
     * The model's errors as sentences.
     *
     * @param ItemModel $model The model.
     *
     * @return list<string>
     *
     * @since  2.3.0
     */
    private static function errorsOf(ItemModel $model): array
    {
        $errors = [];

        foreach ($model->getErrors() as $error) {
            $errors[] = $error instanceof Throwable ? ErrorText::of($error) : (string) $error;
        }

        return array_values(array_filter($errors, static fn (string $error): bool => $error !== ''));
    }
}
