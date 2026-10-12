<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFailedCopyRedirect;
use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Controller\Traits\HasInlineEditTrait;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Model\CategoryModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;
use RuntimeException;
use Throwable;

/**
 * Category controller class.
 *
 * @since  1.0.0
 */
class CategoryController extends FormController
{
    use HasFormPermissions;
    use HasFailedCopyRedirect;
    use HasInlineEditTrait;

    /**
     * Base table backing the inline quick-edit endpoints.
     *
     * @since  1.5.0
     */
    private const INLINE_TABLE = '#__alfa_categories';

    /**
     * Primary column of this entity's per-language aux tables.
     *
     * @since  1.5.0
     */
    private const INLINE_PRIMARY_COLUMN = 'id_category';

    /**
     * Translatable fields this list may quick-edit. Anything else is refused, so
     * a crafted request cannot reach a field the list does not render.
     *
     * @since  1.5.0
     */
    private const INLINE_FIELDS = ['name'];

    protected $view_list = 'categories';

    /**
     * Method to run batch operations.
     *
     * @param object|null $model Unused; the Category model is always loaded internally.
     *
     * @return bool True if successful, false otherwise and internal error is set.
     *
     * @since  1.5.0
     */
    public function batch($model = null)
    {
        $this->checkToken();

        $this->setRedirect(
            Route::_('index.php?option=com_alfa&view=categories' . $this->getRedirectToListAppend(), false),
        );

        // Reparenting is editing: refused before the model is even loaded.
        if (!AlfaHelper::can(section: 'categories', action: 'edit', user: $this->app->getIdentity())) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'), 'error');

            return false;
        }

        $model = $this->getModel('Category', 'Administrator', []);

        return parent::batch($model);
    }

    /**
     * Inline quick-edit: load a translatable field's per-language values for the
     * list editor. Returns each installed content language exactly as stored
     * (no fallback), so the editor shows empty where a translation is missing.
     *
     *
     * @since  1.5.0
     */
    public function inlineLoad(): void
    {
        try {
            $this->assertInlineAuthorised();

            $id = $this->input->getInt('id', 0);
            $field = $this->input->getCmd('element', '');

            if ($id <= 0 || !\in_array($field, self::INLINE_FIELDS, true)) {
                throw new RuntimeException(Text::_('COM_ALFA_INLINE_UNSUPPORTED_FIELD'));
            }

            $this->assertRecordUnlocked(id: $id, table: self::INLINE_TABLE);

            $html = $this->renderInlineMultilingualEditor(
                id:            $id,
                table:         self::INLINE_TABLE,
                primaryColumn: self::INLINE_PRIMARY_COLUMN,
                field:         $field,
            );

            $this->sendInlineJson(true, '', ['html' => $html]);
        } catch (Throwable $e) {
            $this->sendInlineJson(false, $e->getMessage());
        }
    }

    /**
     * Inline quick-edit: persist a translatable field edited from the categories
     * list, then run the same side effects the full form save runs: the
     * storefront category cache holds the language-resolved name and path, and
     * every assigned item's search document embeds the category name.
     *
     *
     * @since  1.5.0
     */
    public function inlineSave(): void
    {
        try {
            $this->assertInlineAuthorised();

            $payload = $this->readInlineJsonBody();
            $id = (int) ($payload['id'] ?? 0);
            $field = (string) ($payload['field'] ?? '');
            $values = $payload['values'] ?? [];

            if ($id <= 0 || !\in_array($field, self::INLINE_FIELDS, true)) {
                throw new RuntimeException(Text::_('COM_ALFA_INLINE_UNSUPPORTED_FIELD'));
            }

            $this->assertRecordUnlocked(id: $id, table: self::INLINE_TABLE);

            $display = $this->saveInlineMultilingualField(
                id:            $id,
                table:         self::INLINE_TABLE,
                primaryColumn: self::INLINE_PRIMARY_COLUMN,
                field:         $field,
                values:        \is_array($values) ? $values : [],
                // Alias uniqueness is scoped by parent_id: a rename must dedupe
                // within its own parent, never across the whole tree.
                aliasFields:   MultilingualAliasConfig::FIELDS[self::INLINE_TABLE],
                aliasScope:    MultilingualAliasConfig::SCOPE[self::INLINE_TABLE] ?? [],
            );

            $model = $this->getModel('Category', 'Administrator', ['ignore_request' => true]);

            if ($model instanceof CategoryModel) {
                $model->applyRenameSideEffects(categoryId: $id);
            }

            $this->sendInlineJson(true, Text::_('COM_ALFA_INLINE_SAVED'), ['display' => $display]);
        } catch (Throwable $e) {
            $this->sendInlineJson(false, $e->getMessage());
        }
    }
}
