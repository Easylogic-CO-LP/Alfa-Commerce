<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model\Traits;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * A payment or shipping method's plugin is fixed once an order uses it (2.2.0, design 2.15.5):
 * the type an order or a money or parcel row was taken with is its plugin of record, and changing the method's type
 * would point the method at another gateway or carrier. The form shows the type read-only with a note; a save that
 * changes it is refused. Deleting a referenced method was already refused.
 *
 * @since  2.2.0
 */
trait LocksMethodTypeTrait
{
    /**
     * Whether an order or a money or parcel row references the method.
     *
     * @param int $id The method.
     *
     * @since  2.2.0
     */
    protected function methodTypeLocked(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        [$column, $rows] = $this->methodTypeReferences();
        $db = $this->getDatabase();

        // Existence, not a count: the money or parcel rows first (an index leads with the method's column there, and a
        // referenced method nearly always has one), the orders last (no index on the column).
        foreach ([$rows, '#__alfa_orders'] as $table) {
            $used = $db->setQuery(
                $db->getQuery(true)
                    ->select('1')
                    ->from($db->quoteName($table))
                    ->where($db->quoteName($column) . ' = ' . $id),
                0,
                1,
            )->loadResult();

            if ($used !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The form of a referenced method: its type read-only, with the note saying why.
     *
     * @param Form $form The method's form.
     * @param int $id The method.
     *
     * @since  2.2.0
     */
    protected function lockMethodTypeField(Form $form, int $id): void
    {
        if (!$this->methodTypeLocked($id)) {
            return;
        }

        // Read only, so not asked for: no required star (UX-38); the field names the plugin, marked when it is not enabled.
        $form->setFieldAttribute('type', 'readonly', 'true');
        $form->setFieldAttribute('type', 'required', 'false');
        $form->setFieldAttribute('type', 'description', 'COM_ALFA_METHOD_TYPE_LOCKED');
    }

    /**
     * Refuse a save that changes a referenced method's type.
     *
     * @param array $data The data to save.
     *
     * @return bool False when refused; the error is set.
     *
     * @since  2.2.0
     */
    protected function methodTypeChangeAllowed(array $data): bool
    {
        $id = (int) ($data['id'] ?? 0);

        if ($id <= 0 || !\array_key_exists('type', $data) || !$this->methodTypeLocked($id)) {
            return true;
        }

        $table = $this->getTable();

        if (!$table->load($id) || (string) $table->type === (string) $data['type']) {
            return true;
        }

        $this->setError(Text::_('COM_ALFA_METHOD_TYPE_LOCKED'));

        return false;
    }

    /**
     * The method's column on the orders and on its rows, and the rows' table.
     *
     * @return array{0: string, 1: string}
     *
     * @since  2.2.0
     */
    abstract protected function methodTypeReferences(): array;
}
