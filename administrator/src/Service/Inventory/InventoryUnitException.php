<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * A stock command named a unit the kernel cannot move stock for.
 *
 * Thrown by receive() and adjust() for every unit that is not a tracked stock unit,
 * by the identity check taken under the unit's row locks, and by the commands that
 * need a tracked unit when its tracking changed while they waited for the lock.
 * Nothing is written when this is thrown.
 *
 * getMessage() is the English text for logs; operatorMessage() is the translated
 * sentence for a screen.
 *
 * @since  1.14.0
 */
final class InventoryUnitException extends RuntimeException
{
    /** The unit exists but does not track stock. */
    public const NOT_TRACKED = 'not_tracked';

    /** The combination no longer exists, or belongs to another item. */
    public const COMBINATION_GONE = 'combination_gone';

    /** The item no longer exists. */
    public const ITEM_GONE = 'item_gone';

    /** The item has combinations, so the item itself is not a stock unit. */
    public const ITEM_BEARS_COMBINATIONS = 'item_bears_combinations';

    /** The combination is archived: kept only as the stock identity of order history. */
    public const COMBINATION_ARCHIVED = 'combination_archived';

    /** The combination is current, so it cannot be brought back from the archive. */
    public const COMBINATION_NOT_ARCHIVED = 'combination_not_archived';

    /** The product is archived: it and every stock identity of it are kept only for order history. */
    public const ITEM_ARCHIVED = 'item_archived';

    /**
     * @param int $itemId The item named by the command.
     * @param int $combinationId The combination named by the command, 0 for the item unit.
     * @param string $reason One of the class constants.
     *
     * @since  1.14.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly int $combinationId,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Inventory: %s of item #%d is not a stock unit (%s); nothing was moved.',
            $combinationId > 0 ? 'combination #' . $combinationId : 'the product itself',
            $itemId,
            match ($reason) {
                self::NOT_TRACKED => 'it does not track stock',
                self::COMBINATION_GONE => 'the combination no longer exists for this item',
                self::ITEM_GONE => 'the item no longer exists',
                self::ITEM_BEARS_COMBINATIONS => 'the item holds its stock in its combinations',
                self::COMBINATION_ARCHIVED => 'the combination is archived',
                self::COMBINATION_NOT_ARCHIVED => 'the combination is not archived',
                self::ITEM_ARCHIVED => 'the product is archived',
                default => $reason,
            },
        ));
    }

    /**
     * Whether the unit's identity itself is gone (or is no longer a current unit:
     * an archived combination, any identity of an archived product, the bare item
     * of a product with combinations), as opposed to its tracking or a
     * reactivation of a combination that is current.
     *
     *
     * @since  1.14.0
     */
    public function identityLost(): bool
    {
        return !\in_array($this->reason, [self::NOT_TRACKED, self::COMBINATION_NOT_ARCHIVED], true);
    }

    /**
     * The refusal as the operator reads it, in the current language: the unit by its
     * product's name (and its variant's label), and why its stock did not change.
     *
     * The names are read, read-only, when the message is asked for. The administrator
     * strings are loaded here when the running application has not loaded them: plugin
     * screens and other extensions' requests report these refusals too.
     *
     *
     * @since  1.14.0
     */
    public function operatorMessage(): string
    {
        self::loadAdministratorStrings();

        return Text::sprintf(
            'COM_ALFA_INVENTORY_UNIT_REFUSED',
            $this->unitLabel(),
            Text::_(match ($this->reason) {
                self::NOT_TRACKED => 'COM_ALFA_COMBI_STOCK_REASON_UNTRACKED',
                self::COMBINATION_GONE => 'COM_ALFA_COMBI_STOCK_REASON_GONE',
                self::COMBINATION_ARCHIVED => 'COM_ALFA_COMBI_STOCK_REASON_ARCHIVED',
                self::ITEM_BEARS_COMBINATIONS => 'COM_ALFA_ITEM_STOCK_REASON_VARIANTS_OWN',
                self::ITEM_GONE => 'COM_ALFA_ITEM_STOCK_REASON_GONE',
                self::ITEM_ARCHIVED => 'COM_ALFA_ITEM_STOCK_REASON_ARCHIVED',
                default => 'COM_ALFA_INVENTORY_UNIT_REASON_OTHER',
            }),
        );
    }

    /**
     * The unit this refusal names, as the operator knows it ({@see self::labelFor()}).
     *
     *
     * @since  1.14.0
     */
    private function unitLabel(): string
    {
        return self::labelFor(itemId: $this->itemId, combinationId: $this->combinationId);
    }

    /**
     * A stock identity as the operator knows it: the product's name, followed by the
     * variant's label in parentheses for a combination. Read-only; the one label every
     * inventory refusal and notice shown to an operator uses for a unit.
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination, 0 for the product itself.
     *
     *
     * @since  1.14.0
     */
    public static function labelFor(int $itemId, int $combinationId): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $label = self::productName(db: $db, itemId: $itemId);

        if ($combinationId > 0) {
            $label .= ' (' . self::variantLabel(db: $db, itemId: $itemId, combinationId: $combinationId) . ')';
        }

        return $label;
    }

    /**
     * The product's name in the current language, or in the site's default language
     * when it has none there. The product's id stands in only when its row is gone or
     * carries no name in either language.
     *
     * @param DatabaseInterface $db The database driver.
     * @param int $itemId The product.
     *
     *
     * @since  1.14.0
     */
    private static function productName(DatabaseInterface $db, int $itemId): string
    {
        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = ' . $itemId),
        )->loadResult() > 0;

        $name = $exists
            ? MultilingualHelper::getTranslatedValue(
                id: $itemId,
                tableName: '#__alfa_items',
                primaryColumnName: 'id_item',
                field: 'name',
            )
            : '';

        return $name !== '' ? $name : '#' . $itemId;
    }

    /**
     * The variant's label as the combinations grid shows it: its value names joined
     * by " / ", in the product's dimension order, each in the current language (or
     * the site's default language). The combination's id stands in when its row is
     * gone, belongs to another product or links no value.
     *
     * @param DatabaseInterface $db The database driver.
     * @param int $itemId The product.
     * @param int $combinationId The combination.
     *
     *
     * @since  1.14.0
     */
    private static function variantLabel(DatabaseInterface $db, int $itemId, int $combinationId): string
    {
        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->where($db->quoteName('id') . ' = ' . $combinationId)
                ->where($db->quoteName('item_id') . ' = ' . $itemId),
        )->loadResult() > 0;

        if (!$exists) {
            return '#' . $combinationId;
        }

        $valueIds = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('cv.value_id'))
                ->from($db->quoteName('#__alfa_item_combination_values', 'cv'))
                ->join('INNER', $db->quoteName('#__alfa_item_field_values', 'v') . ' ON v.id = cv.value_id')
                ->join('INNER', $db->quoteName('#__alfa_item_fields', 'f') . ' ON f.id = v.item_field_id')
                ->join(
                    'LEFT',
                    $db->quoteName('#__alfa_item_field_overrides', 'ovr')
                    . ' ON ovr.field_id = f.id AND ovr.item_id = ' . $itemId,
                )
                ->where($db->quoteName('cv.combination_id') . ' = ' . $combinationId)
                ->order('COALESCE(ovr.ordering, f.ordering) ASC, f.ordering ASC, v.ordering ASC'),
        )->loadColumn() ?: []);

        $names = array_map(
            static fn (int $valueId): string => MultilingualHelper::getTranslatedValue(
                id: $valueId,
                tableName: '#__alfa_item_field_values',
                primaryColumnName: 'id_item_field_value',
                field: 'name',
            ) ?: '#' . $valueId,
            $valueIds,
        );

        return $names === [] ? '#' . $combinationId : implode(' / ', $names);
    }

    /**
     * Load the component's administrator strings into the application language when
     * they are not there yet. A failure is logged; the message then shows its keys.
     *
     *
     * @since  1.14.0
     */
    private static function loadAdministratorStrings(): void
    {
        try {
            $language = Factory::getApplication()->getLanguage();

            if (!$language->hasKey(string: 'COM_ALFA_INVENTORY_UNIT_REFUSED')) {
                $language->load(extension: 'com_alfa', basePath: JPATH_ADMINISTRATOR);
            }
        } catch (Throwable $e) {
            Log::add(
                entry:    'Stock unit refusal: the administrator strings could not be loaded: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }
}
