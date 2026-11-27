<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The order's card disputes, after its payments: one card a case (layouts/order/dispute_card.php), shown only when the
 * order has a dispute. Each card's buttons open the action page of the disputed payment, the one way a dispute act is
 * shown, checked and submitted.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Order\HtmlView $this
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\Order\DisputeLedger;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

$disputes = DisputeLedger::forOrder(orderId: (int) $this->order->id);

if ($disputes === [] || $this->order->currency === null) {
    return;
}

$canEdit = AlfaHelper::can(section: 'orders', action: 'edit', user: $this->getCurrentUser());
$payments = [];

foreach ((array) $this->order->payments as $payment) {
    $payments[(int) $payment->id] = $payment;
}
?>
<div class="disputes mt-4">
    <h3>
        <span class="icon-warning me-2" aria-hidden="true"></span>
        <?php echo Text::_('COM_ALFA_DISPUTES'); ?>
        <span class="badge bg-secondary"><?php echo \count($disputes); ?></span>
    </h3>
    <?php foreach ($disputes as $dispute) : ?>
        <?php $payment = $payments[(int) $dispute->id_payment] ?? null; ?>
        <?php echo LayoutHelper::render('order.dispute_card', [
            'dispute' => $dispute,
            'payment' => $payment,
            'currency' => $this->order->currency,
            'actions' => $canEdit && $payment !== null ? ActionRegistry::disputeActions(dispute: $dispute, payment: $payment) : [],
        ], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
    <?php endforeach; ?>
</div>
