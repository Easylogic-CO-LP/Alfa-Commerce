<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The order screen's Status e-mail card: sends the e-mail of the order's CURRENT status again, with an optional note
 * to the customer, to the customer and to the staff the status tells, with the order as it stands now.
 *
 * Shown while the status select holds the stored status (Joomla's showon rule on the card): once the operator picks
 * another status, the change's own note and e-mail box take over and this card hides. The note and the button post
 * the sibling form the template names (the order screen's own form must not be nested), so the textarea carries
 * the form attribute. The field is defined in forms/order_status_email.xml, which the controller validates the post
 * through. Prints nothing for an operator who may not edit orders. An order whose customer has no e-mail address
 * gets the sentence that says so and no button.
 *
 * Override html/layouts/com_alfa/order/status_email.php in the administrator template to change it.
 *
 * Display data:
 *   order       object  the order on screen (id, id_order_status, id_user, customer_email, customer_name,
 *                       customer_is_guest)
 *   statusName  string  the stored status's name in the administrator's language
 *   recipient   string  the customer's address the e-mail goes to; '' when the order has none
 *   canEdit     bool    whether the operator may edit orders
 *   formId      string  the id of the sibling form the card posts
 *   formControl string  the name the posted field is nested under (status_email)
 *
 * @since  2.3.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$order       = $displayData['order'];
$statusName  = (string) ($displayData['statusName'] ?? '');
$recipient   = (string) ($displayData['recipient'] ?? '');
$canEdit     = !empty($displayData['canEdit']);
$formId      = (string) ($displayData['formId'] ?? 'alfa-order-email-form');
$formControl = (string) ($displayData['formControl'] ?? 'status_email');
$orderId     = (int) ($order->id ?? 0);
$statusId    = (int) ($order->id_order_status ?? 0);

if (!$canEdit || $orderId <= 0 || $statusId <= 0) {
    return;
}

// Shown while the select holds the stored status: the same rule the change's own fields read inverted.
$showon = json_encode(FormHelper::parseShowOnConditions('id_order_status:' . $statusId, 'jform'));
$noteId = $formControl . '_note';
?>
<div class="card mt-3" data-showon='<?php echo $this->escape($showon); ?>'>
    <div class="card-body">
        <h6 class="card-title"><?php echo Text::_('COM_ALFA_ORDER_STATUS_EMAIL_TITLE'); ?></h6>
        <?php if ($recipient === '') : ?>
            <p class="small text-muted mb-0"><?php echo Text::_('COM_ALFA_ORDER_STATUS_EMAIL_NO_ADDRESS'); ?></p>
        <?php else : ?>
            <p class="small text-muted mb-2">
                <?php echo Text::sprintf('COM_ALFA_ORDER_STATUS_EMAIL_HINT', $this->escape($statusName), $this->escape($recipient)); ?>
            </p>
            <div class="mb-2">
                <label class="form-label" for="<?php echo $this->escape($noteId); ?>"><?php echo Text::_('COM_ALFA_ORDER_STATUS_NOTE_LABEL'); ?></label>
                <textarea class="form-control" id="<?php echo $this->escape($noteId); ?>"
                          name="<?php echo $this->escape($formControl); ?>[note]" rows="3" maxlength="2000"
                          form="<?php echo $this->escape($formId); ?>"></textarea>
                <div class="form-text"><?php echo Text::_('COM_ALFA_ORDER_STATUS_EMAIL_NOTE_DESC'); ?></div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" form="<?php echo $this->escape($formId); ?>"
                    formaction="<?php echo Route::_('index.php?option=com_alfa', false); ?>&task=order.resendStatusEmail&id_order=<?php echo $orderId; ?>">
                <?php echo Text::_('COM_ALFA_ORDER_STATUS_EMAIL_BUTTON'); ?>
            </button>
        <?php endif; ?>
    </div>
</div>
