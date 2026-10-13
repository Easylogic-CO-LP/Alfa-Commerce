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

/**
 * A field that posts ONE flat key per installed language: `short_desc` renders
 * jform[short_desc_el_gr] and jform[short_desc_en_gb].
 *
 * This is the whole contract, and it is what {@see \Alfa\Component\Alfa\Administrator\Model\AlfaAdminModel}
 * looks for when it declares the storage siblings that keep those keys alive through filtering,
 * a failed save and binding. A new translatable field type joins by implementing this and using
 * {@see \Alfa\Component\Alfa\Administrator\Field\Traits\HasMultilingualNamesTrait}; there is no
 * list of types to keep in step anywhere else.
 *
 * Declared by the CLASS, not by the form XML, because it is a property of how the field renders.
 * EmailPositions is the counter-example that makes this necessary: it is bound to a language
 * table like these are, but it posts `<field>_<lang>_<slot>`, so one sibling per language would
 * be the wrong shape.
 *
 * @since  2.0.0
 */
interface MultilingualFieldInterface
{
}
