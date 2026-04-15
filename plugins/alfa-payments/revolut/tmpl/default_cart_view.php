<?php
defined('_JEXEC') or die;

use Alfa\Plugin\AlfaPayments\Revolut\Assets;
use Joomla\CMS\Language\Text;

extract($displayData);
if (!isset($method)) {
    return;
}

?>
<div class="revolut-cart-info d-flex flex-column align-items-start gap-3 p-3 border rounded">
    <div class="flex-shrink-0"><?php echo Assets::LOGO_SVG; ?></div>
    <div>
        <strong><?php echo Text::_('PLG_ALFA_PAYMENTS_REVOLUT_PAY_WITH_REVOLUT'); ?></strong>
        <p class="mb-0 text-muted small mt-1">
			<?php echo Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CHECKOUT_DESC'); ?>
        </p>
    </div>
</div>
