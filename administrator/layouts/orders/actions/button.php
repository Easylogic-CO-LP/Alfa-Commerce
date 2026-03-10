<?php
/**
 * Shared Action Button Layout
 *
 * The ONE and ONLY default button renderer for all plugin actions.
 * No duplication — payments and shipments both use this file.
 *
 * Plugin can override by placing 'action_button.php' in its own tmpl/.
 *
 * Path: administrator/components/com_alfa/layouts/orders/actions/button.php
 *
 * Available $displayData:
 *   - action   (PluginAction)  The action definition
 *   - context  (string)        'payment' or 'shipment'
 *   - id       (int)           Entity PK (payment or shipment ID)
 *
 * @package     Alfa.Component
 * @subpackage  Administrator.Layout
 * @version     3.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2025-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * @since  3.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Alfa\Component\Alfa\Administrator\Plugin\PluginAction $action */
$action  = $displayData['action'] ?? null;
$context = $displayData['context'] ?? 'payment';
$id      = (int) ($displayData['id'] ?? 0);

if (!$action || !$action->isValid()) {
	return;
}

// ── Build onclick handler ────────────────────────────────────
$onclick = '';

if ($action->requires_confirmation) {
	$confirmMsg = $action->confirmation_message ?? 'Are you sure?';
	$message    = addslashes(Text::_($confirmMsg));
	$onclick   .= "if(!confirm('{$message}')) return false; ";
}

$onclick .= "Alfa.executeAction('{$context}', {$id}, '{$action->id}', this); return false;";

// ── Tooltip ──────────────────────────────────────────────────
$tooltipAttr = '';
if (!empty($action->tooltip)) {
	$tooltipAttr = 'title="' . htmlspecialchars(Text::_($action->tooltip)) . '"';
}
?>

<button type="button"
        class="btn <?php echo htmlspecialchars($action->class); ?> btn-sm me-1"
        onclick="<?php echo $onclick; ?>"
	<?php echo $tooltipAttr; ?>
	<?php echo !$action->enabled ? 'disabled' : ''; ?>>
    <span class="icon-<?php echo htmlspecialchars($action->icon); ?>" aria-hidden="true"></span>
	<?php echo Text::_($action->label); ?>
</button>