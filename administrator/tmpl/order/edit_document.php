<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Sales-document issue form, rendered inside a modal iframe, the same shell
 * the shipment, payment and order-item popups use.
 *
 * There is no edit twin: an issued document is immutable. The toolbar's only
 * write is Issue, and it posts to order.issueDocument, which hands the payload
 * to DocumentIssuer, the owner of every guard. This template collects input;
 * it decides nothing.
 *
 * Path: administrator/components/com_alfa/tmpl/order/edit_document.php
 *
 * @since  1.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('showon')
	->useScript('com_alfa.validate.engine')
	->useStyle('com_alfa.validate')
	->useScript('com_alfa.admin')
	->useScript('com_alfa.admin.document-issue')
	->useStyle('com_alfa.admin');

$orderID = (int) $this->order->id;

?>

    <!-- Toolbar -->
    <div class="subhead noshadow mb-3">
		<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
    </div>

    <div class="container-popup">

        <form
                action="<?php echo Route::_("index.php?option=com_alfa&layout=edit_document&tmpl=component&id_order={$orderID}"); ?>"
                method="post"
                name="adminForm"
                id="document-form"
                class="form-validate">

			<?php // Every field in document order: the picks, the lines and
			      // charges pickers, then the buyer and movement groups. Each of
			      // those two is led by a spacer that carries the same showon as
			      // its fields, so a group that does not apply to the picked type
			      // disappears whole instead of leaving an empty heading behind. ?>
			<?php echo $this->form->renderFieldset('document'); ?>

            <!-- Hidden Fields -->
			<?php echo HTMLHelper::_('form.token'); ?>
            <input type="hidden" name="task" value="" />
            <input type="hidden" name="id_order" value="<?php echo $orderID; ?>" />
        </form>
    </div>
