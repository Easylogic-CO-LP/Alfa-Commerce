<?php
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

extract($displayData);
$order ??= null;
?>
<div class="viva-cancelled text-center py-4">
    <span class="icon-info-circle text-warning" style="font-size:3rem" aria-hidden="true"></span>
    <h3 class="mt-3"><?php echo Text::_('PLG_ALFA_PAYMENTS_VIVA_CANCELLED_TITLE'); ?></h3>
    <p class="text-muted"><?php echo Text::_('PLG_ALFA_PAYMENTS_VIVA_CANCELLED_MSG'); ?></p>
    <?php if ($order && !empty($order->id)): ?>
    <div class="d-flex gap-2 justify-content-center mt-4">
        <a href="<?php echo Route::_('index.php?option=com_alfa&task=checkout.process&order_id=' . (int) $order->id); ?>"
           class="btn btn-primary"><?php echo Text::_('PLG_ALFA_PAYMENTS_VIVA_TRY_AGAIN'); ?></a>
        <a href="<?php echo Route::_('index.php?option=com_alfa&view=cart'); ?>"
           class="btn btn-outline-secondary"><?php echo Text::_('PLG_ALFA_PAYMENTS_VIVA_BACK_TO_CART'); ?></a>
    </div>
    <?php endif; ?>
</div>
