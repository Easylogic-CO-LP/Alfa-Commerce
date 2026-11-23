<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaFilters\Site\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * The code a merchant copies to open this module's filters from a button or link of their
 * own: the attribute with this module's number in it, ready to paste. Until the module is
 * saved it has no number, and the field says so.
 *
 * @since  2.3.0
 */
class FiltersOpenerField extends FormField
{
    /**
     * Field type id: must match the filename and the XML `type` attribute.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'FiltersOpener';

    /**
     * The snippet, selected whole by one click.
     *
     * @return string
     *
     * @since  2.3.0
     */
    protected function getInput(): string
    {
        $id = (int) $this->form->getValue('id');

        if ($id < 1) {
            return '<p class="form-text">' . htmlspecialchars(Text::_('MOD_ALFAFILTERS_OPENER_UNSAVED'), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $snippet = '<button type="button" data-alfa-filters-toggle="' . $id . '">'
            . Text::_('MOD_ALFAFILTERS_TOGGLE_BUTTON_TXT') . '</button>';

        return '<code class="d-block p-2 user-select-all" id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') . '</code>';
    }
}
