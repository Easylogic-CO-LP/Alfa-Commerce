<?php

/**
 * The confirmation of a shipping method delete that products' shipping lists still name beside other methods: one
 * button that sends the same delete again, confirmed. Printed after the sentence that says how many products name
 * what was chosen (ShipmentModel::delete()), in the message of the delete that was not done.
 *
 * The model judges the confirmed delete again as it judged the first: a product limited to the methods alone since
 * then still refuses it.
 *
 * Available $displayData:
 *   - ids (int[]) The shipping methods chosen for the delete.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$ids = array_values(array_filter(array_map('intval', (array) ($displayData['ids'] ?? [])), static fn (int $id): bool => $id > 0));

if ($ids === []) {
    return;
}
?>
<form action="<?php echo Route::_('index.php?option=com_alfa&view=shipments'); ?>" method="post" class="mt-2" id="shipments-delete-confirm">
    <?php foreach ($ids as $id) : ?>
        <input type="hidden" name="cid[]" value="<?php echo $id; ?>">
    <?php endforeach; ?>
    <input type="hidden" name="task" value="shipments.delete">
    <input type="hidden" name="confirm" value="1">
    <?php echo HTMLHelper::_('form.token'); ?>
    <button type="submit" class="btn btn-danger btn-sm"><?php echo Text::_('COM_ALFA_SHIPMENT_DELETE_CONFIRM_BUTTON'); ?></button>
</form>
