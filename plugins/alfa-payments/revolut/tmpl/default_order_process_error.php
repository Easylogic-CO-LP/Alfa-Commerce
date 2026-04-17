<?php
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

extract($displayData);
$error = htmlspecialchars($error ?? Text::_('PLG_ALFA_PAYMENTS_REVOLUT_PAYMENT_FAILED'));
$order ??= null;
?>
<div class="revolut-error text-center py-4">
    <span class="icon-cancel-circle text-danger" style="font-size:3rem" aria-hidden="true"></span>
    <h3 class="text-danger mt-3"><?php echo Text::_('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_TITLE'); ?></h3>
    <p class="text-muted"><?php echo $error; ?></p>
    <?php if ($order && !empty($order->id)): ?>
    <div class="d-flex gap-2 justify-content-center mt-4">
        <a href="<?php echo Route::_('index.php?option=com_alfa&view=cart'); ?>" class="btn btn-outline-secondary"><?php echo Text::_('PLG_ALFA_PAYMENTS_REVOLUT_BACK_TO_CART'); ?></a>
    </div>
    <?php endif; ?>
</div>
