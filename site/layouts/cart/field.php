<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Default cart-line FIELD presentation: the plain label:value summary. A field's
 * plugin may REPLACE this with its own layout via onCartItemView (e.g. render the
 * stored artwork); CartLinePresenter picks the descriptor, this is
 * the fallback when no plugin owns the field.
 *
 * @var array $displayData ['label' => string, 'value' => string, 'has_payload' => bool]
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$label      = $displayData['label'] ?? '';
$value      = $displayData['value'] ?? '';
$hasPayload = !empty($displayData['has_payload']);
?>
<span class="cart-item-field-label"><?php echo htmlspecialchars((string) $label); ?>:</span>
<span class="cart-item-field-value"><?php echo $hasPayload ? Text::_('COM_ALFA_CART_FIELD_CUSTOM') : htmlspecialchars((string) $value); ?></span>
