<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Void a sales document: rendered inside a modal iframe, the same shell the
 * order screen's popups use.
 *
 * One form, one write. It replaces two copies of a hand-built POST: the order
 * screen's Documents panel and this view's own list each carried a <div> whose
 * two values a script copied into a form it created at submit time. Both now
 * open this layout.
 *
 * Path: administrator/components/com_alfa/tmpl/documents/void.php
 *
 * @since  1.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->document->getWebAssetManager();
$wa->useScript('keepalive')
	->useScript('form.validate')
	->useScript('com_alfa.validate.engine')
	->useStyle('com_alfa.validate')
	->useScript('com_alfa.admin')
	->useStyle('com_alfa.admin');

$documentID = (int) $this->voidDocument->id;

?>

    <!-- Toolbar -->
    <div class="subhead noshadow mb-3">
		<?php echo $this->getDocument()->getToolbar('toolbar')->render(); ?>
    </div>

    <div class="container-popup">

        <form
                action="<?php echo Route::_("index.php?option=com_alfa&view=documents&layout=void&tmpl=component&id={$documentID}"); ?>"
                method="post"
                name="adminForm"
                id="document-void-form"
                class="form-validate">

			<?php // Which document, said in the operator's terms. A number is
			      // what they recognise; the id is the form's business. ?>
            <div class="alert alert-warning">
				<?php echo Text::sprintf(
					'COM_ALFA_DOCUMENTS_VOID_PROMPT',
					$this->escape((string) $this->voidDocument->number_formatted)
				); ?>
            </div>

			<?php echo $this->form->renderFieldset('void'); ?>

            <!-- Hidden Fields -->
			<?php echo HTMLHelper::_('form.token'); ?>
            <input type="hidden" name="task" value="" />
        </form>
    </div>
