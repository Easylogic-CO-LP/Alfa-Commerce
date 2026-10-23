<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
defined('_JEXEC') or die;
?>
<div>

    <?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_ORDER_COMPLETED_MESSAGE'); ?><br>

    <?php if ($this->orderNumber !== '') : ?>
        <p class="order-number"><?php echo \Joomla\CMS\Language\Text::sprintf('COM_ALFA_ORDER_COMPLETED_NUMBER', $this->escape($this->orderNumber)); ?></p>
    <?php endif; ?>

    <?php // The address the confirmation went to; a guest sees a typo at once. ?>
    <?php if (!empty($this->customerEmail)) : ?>
        <p class="order-confirmation-notice">
            <?php echo \Joomla\CMS\Language\Text::sprintf('COM_ALFA_ORDER_COMPLETED_CONFIRMATION_SENT', $this->escape($this->customerEmail)); ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($this->hasDownloads)) : ?>
        <p class="order-downloads-notice">
            <?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_ORDER_COMPLETED_DOWNLOADS_EMAILED'); ?>
        </p>
    <?php endif; ?>

	<?php

	use Alfa\Component\Alfa\Site\Helper\Layout;

	// Safe to call unconditionally: Layout::render returns a no-output layout
	// when the type/name/layout is empty (e.g. an order with no payment method).
	echo Layout::render(
		$this->event->onOrderCompleteView->getLayout(),
		$this->event->onOrderCompleteView->getLayoutData(),
		$this->event->onOrderCompleteView->getLayoutPluginType(),
		$this->event->onOrderCompleteView->getLayoutPluginName()
	);


	?>

    <a href="<?php echo \Joomla\CMS\Uri\Uri::root(); ?>"><?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>

</div>
