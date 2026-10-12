<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;

/**
 * Carrier for ONE per-language value of a Multilingual* field. Renders nothing.
 *
 * The Multilingual* fields draw one input per language named jform[short_desc_el_gr] while the
 * form XML declares only short_desc, and Joomla carries declared fields and nothing else:
 * Form::filter() copies a key only when a field matched it, and Form::bindLevel() keeps a scalar
 * only when findField() does. The typed translations were dropped on the way out AND on the way
 * back, and the fields papered over it by re-reading the language tables in setup().
 *
 * Declaring one of these per language ({@see \Alfa\Component\Alfa\Administrator\Model\AlfaAdminModel})
 * makes the flat key a REAL field, so it survives filtering, the session stash a failed save
 * writes, and binding, and the field can read what the admin typed back out of the ordinary
 * form registry instead of overwriting it from the database.
 *
 * It renders NOTHING on purpose: the visible input is already drawn by the owning Multilingual*
 * field, and a second input with the same name would be appended after the first and silently win.
 *
 * @since  1.10.0
 */
class MultilingualStorageField extends FormField
{
    /**
     * The field type: must match the filename and the XML type attribute.
     *
     * @var string
     *
     * @since  1.10.0
     */
    protected $type = 'MultilingualStorage';

    /**
     * Render nothing: the owning Multilingual* field draws the real input.
     *
     *
     * @since  1.10.0
     */
    protected function getInput(): string
    {
        return '';
    }

    /**
     * Render no wrapper either, so a template looping a fieldset draws nothing.
     *
     * @param array $options Ignored; this field is never visible.
     *
     *
     * @since  1.10.0
     */
    public function renderField($options = []): string
    {
        return '';
    }

    /**
     * And no label.
     *
     *
     * @since  1.10.0
     */
    protected function getLabel(): string
    {
        return '';
    }
}
