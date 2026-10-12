<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields\Concern;

defined('_JEXEC') or die;

/**
 * The buyer's CURRENT sibling selections, exposed to every per-field event so a
 * field can react to what the OTHER fields resolved to (e.g. the configurator
 * picks its page dimensions from the chosen "Size" value). Provenance-agnostic:
 * the same shape whether it came from the live request (sel[]/opt[]), a stored
 * cart line re-edit, or the admin order-line editor.
 *
 * Shape: ['sel' => [fieldId => pool value id], 'opt' => [pool value id, ...]]:
 * exactly requestSelection()'s parse. Both empty when nothing is chosen yet
 * (catalog price, first render).
 *
 * @since  1.3.0
 */
trait HasSelections
{
    /**
     * The sibling selections snapshot for this dispatch.
     *
     * @return array{sel: array<int, int>, opt: int[]}
     *
     * @since  1.3.0
     */
    public function getSelections(): array
    {
        $selections = (array) ($this->arguments['selections'] ?? []);

        return [
            'sel' => (array) ($selections['sel'] ?? []),
            'opt' => (array) ($selections['opt'] ?? []),
        ];
    }

    /**
     * Every chosen pool value id, variant and option merged: the convenient
     * form for "does the buyer's selection include value X?" lookups.
     *
     * @return int[]
     *
     * @since  1.3.0
     */
    public function getSelectedValueIds(): array
    {
        $selections = $this->getSelections();

        return array_values(array_unique(array_map(
            'intval',
            array_merge(array_values($selections['sel']), $selections['opt']),
        )));
    }
}
