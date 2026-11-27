<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field\Traits;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * A picker of a scope part keeps what the record holds, whatever happened to it since.
 *
 * A picker posts only the values it lists. A value the record holds that is no longer among its options (a customer
 * group or an account deleted in Joomla) would not be posted, the save would find the part empty, and an empty part is
 * stored as "every one": the rule or the method would widen by being saved, with nobody having chosen that. So a held
 * value the options do not have is listed too, selected, and said in words ("#12 (deleted)"). Saving the form
 * unchanged writes it back; the operator who takes it off the part does so on purpose.
 *
 * @since  2.3.0
 */
trait HasHeldDeletedOptionsTrait
{
    /**
     * The options, with one more for each value the record holds that they do not have.
     *
     * @param array $options The options the field offers (objects with value and text).
     * @param callable|null $nameOf Answers the name of a held value that still exists though it is not offered, or
     *                              null when it does not exist; omitted, every held value not offered is deleted.
     *
     * @return array
     *
     * @since  2.3.0
     */
    protected function withHeldDeleted(array $options, ?callable $nameOf = null): array
    {
        $offered = [];

        foreach ($options as $option) {
            $offered[(string) (\is_object($option) ? ($option->value ?? '') : ($option['value'] ?? ''))] = true;
        }

        foreach ((array) $this->value as $held) {
            if (!\is_scalar($held) || (int) $held <= 0 || isset($offered[(string) (int) $held])) {
                continue;
            }

            $id = (int) $held;
            $name = $nameOf !== null ? $nameOf($id) : null;
            $offered[(string) $id] = true;
            $options[] = (object) [
                'value' => $id,
                'text' => $name !== null && $name !== '' ? (string) $name : Text::sprintf('COM_ALFA_SCOPE_ENTITY_DELETED', $id),
            ];
        }

        return $options;
    }
}
