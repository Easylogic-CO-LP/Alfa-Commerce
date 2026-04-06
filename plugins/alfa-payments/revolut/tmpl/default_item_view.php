<?php defined('_JEXEC') or die;
use Alfa\Plugin\AlfaPayments\Revolut\Assets;
use Joomla\CMS\Language\Text;

extract($displayData);
if (!isset($method)) {
    return;
}
?>
<div class="revolut-item-badge d-flex flex-column align-items-start gap-1 mt-2 mb-2">
	<?php echo Assets::LOGO_SVG; ?>
    <small class="text-muted"><?php echo Text::_('PLG_ALFA_PAYMENTS_REVOLUT_ITEM_VIEW_DESC'); ?></small>
</div>
