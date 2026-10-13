<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\ListField;

/**
 * The "Measured on" choice, offering only what the chosen target can honour.
 *
 * A line-item rule ALWAYS measures on its matching lines: the engine takes
 * that branch whatever this says, so "Whole cart" is a choice with no effect
 * (proved across 100 condition/quantity/tax/discount combinations). Offering it
 * invites a merchant to configure something that silently does nothing, so it
 * is simply not offered there.
 *
 * Joomla has no per-option showon, hence a field rather than an attribute.
 *
 * The list is resolved at RENDER time from the sibling `discount_class`. Switch
 * the target without reloading and the list is briefly stale: harmless,
 * because CartruleTable::check() folds product+cart onto matching on save, so
 * the stored value can never lie regardless of what the browser showed.
 *
 * @since  2.0.0
 */
class CartruleBaseField extends ListField
{
    /**
     * The form field type.
     *
     * @var string
     *
     * @since  2.0.0
     */
    protected $type = 'CartruleBase';

    /**
     * Which bases the current target can actually honour.
     *
     *
     * @since  2.0.0
     */
    protected function getOptions(): array
    {
        $class = (string) ($this->form?->getValue('discount_class') ?: 'order');

        $allowed = match ($class) {
            // Matching lines is implicit; only "cheapest" changes the outcome.
            'product' => ['matching', 'cheapest'],
            default => ['cart', 'matching', 'cheapest'],
        };

        return array_values(array_filter(
            parent::getOptions(),
            static fn ($option): bool => \in_array((string) $option->value, $allowed, true),
        ));
    }
}
