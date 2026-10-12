<?php
/**
 * Shared action button renderer for every MODAL action surface: payments,
 * shipments, item fields. Each button opens the action page (Alfa.openAction),
 * where the action runs once and renders its layout or a result notice. A plugin
 * can override by placing 'action_button.php' in its own tmpl/.
 *
 * The Tools screen is the OTHER interaction model (an AJAX card with in-place
 * progress, no modal) and renders through actions/toolcard.php instead. One
 * renderer per interaction model, not one per surface.
 *
 * Available $displayData:
 *   - action   (PluginAction)  The action definition
 *   - context  (string)        'payment' | 'shipment' | 'itemfield'
 *   - id       (int)           Entity PK (payment / shipment / field id)
 *   - data     (array)         Extra context (e.g. itemfield scope/ids)
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Alfa\Component\Alfa\Administrator\Plugin\PluginAction $action */
$action  = $displayData['action'] ?? null;
$context = $displayData['context'] ?? 'payment';
$id      = (int) ($displayData['id'] ?? 0);
$data    = (array) ($displayData['data'] ?? []);

if (!$action || !$action->isValid()) {
	return;
}

// Build onclick handler
$onclick = '';

if ($action->requires_confirmation) {
	// json_encode makes the translation a valid JS string literal (quotes, newlines,
	// unicode); the ONE htmlspecialchars at the echo below is what makes the whole
	// handler a valid HTML attribute. addslashes was neither: a double quote in a
	// translated message ended the attribute mid-handler.
	$confirmMsg = $action->confirmation_message ?? 'Are you sure?';
	$onclick   .= 'if(!confirm(' . json_encode(Text::_($confirmMsg)) . ')) return false; ';
}

// The action opens its own page (view=action), where it runs once and renders
// its layout or a result notice. Args travel as one JSON object.
$args = json_encode([
    'context' => $context,
    'id'      => $id,
    'action'  => $action->id,
    'data'    => $data + $action->data,
    'title'   => Text::_($action->label),
    // A state-changing action is submitted, not linked: the page it opens
    // refuses a GET so a refresh or a back button cannot re-run it.
    'mutates' => $action->mutates,
]);
$onclick .= "Alfa.openAction({$args}); return false;";
$onclick  = htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8');

// Tooltip
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