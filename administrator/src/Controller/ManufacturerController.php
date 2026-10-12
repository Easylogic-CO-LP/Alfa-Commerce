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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Service\Catalog\EntityChangeNotifier;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Manufacturer controller class.
 *
 * @since  1.0.0
 */
class ManufacturerController extends FormController
{
    use HasFormPermissions;
    use HasFailedCopyRedirect;
    use HasInlineEditTrait;

    /**
     * Base table backing the inline quick-edit endpoints.
     *
     * @since  1.5.0
     */
    private const INLINE_TABLE = '#__alfa_manufacturers';

    /**
     * Primary column of this entity's per-language aux tables.
     *
     * @since  1.5.0
     */
    private const INLINE_PRIMARY_COLUMN = 'id_manufacturer';

    /**
     * Translatable fields this list may quick-edit. Anything else is refused, so
     * a crafted request cannot reach a field the list does not render.
     *
     * @since  1.5.0
     */
    private const INLINE_FIELDS = ['name'];

    protected $view_list = 'manufacturers';

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
     * Inline quick-edit: persist a translatable field edited from the
     * manufacturers list, then rebuild the search documents of every linked item
     * - the brand name is projected into them, so without this the old name keeps
     * matching and the new one never does.
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
                // Manufacturers are flat, so their slugs dedupe globally (no ::SCOPE entry).
                aliasFields:   MultilingualAliasConfig::FIELDS[self::INLINE_TABLE],
                aliasScope:    MultilingualAliasConfig::SCOPE[self::INLINE_TABLE] ?? [],
            );

            $this->resyncLinkedItems(manufacturerId: $id);

            // The inline rename bypasses ManufacturerModel::save(), so the entity
            // signal must be emitted here too, after the aux rows were written.
            EntityChangeNotifier::changed(entity: 'manufacturer', ids: [$id]);

            $this->sendInlineJson(true, Text::_('COM_ALFA_INLINE_SAVED'), ['display' => $display]);
        } catch (Throwable $e) {
            $this->sendInlineJson(false, $e->getMessage());
        }
    }

    /**
     * Re-sync the search documents of every item carrying this manufacturer.
     *
     * Mirrors ManufacturerModel::resyncLinkedItems(), which is private and so
     * unreachable from here; see the hand-off note for the visibility change that
     * collapses the two.
     *
     * Never fatal: a search index is downstream of the catalogue and must not be
     * able to fail a rename.
     *
     * @param int $manufacturerId The renamed manufacturer id.
     *
     *
     * @since  1.5.0
     */
    private function resyncLinkedItems(int $manufacturerId): void
    {
        if ($manufacturerId <= 0) {
            return;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $affectedItems = array_map('intval', (array) $db->setQuery(
                $db->getQuery(true)
                    ->select('DISTINCT ' . $db->quoteName('item_id'))
                    ->from($db->quoteName('#__alfa_items_manufacturers'))
                    ->where($db->quoteName('manufacturer_id') . ' = :manufacturerId')
                    ->bind(':manufacturerId', $manufacturerId, ParameterType::INTEGER),
            )->loadColumn());

            if ($affectedItems !== []) {
                (new ProjectionManager())->syncItems($affectedItems);
            }
        } catch (Throwable $e) {
            Log::add(
                '[ManufacturerController::inlineSave] Search re-sync failed for manufacturer '
                . $manufacturerId . ': ' . $e->getMessage(),
                Log::WARNING,
                'com_alfa',
            );
        }
    }
}
