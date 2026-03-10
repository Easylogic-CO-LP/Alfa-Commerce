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

	<?php

	// TODO: Error handling for missing template.
	use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;

	echo PluginLayoutHelper::pluginLayout(
		$this->event->onOrderCompleteView->getLayoutPluginType(),
		$this->event->onOrderCompleteView->getLayoutPluginName(),
		$this->event->onOrderCompleteView->getLayout()
	)->render($this->event->onOrderCompleteView->getLayoutData());

	//    echo "<pre>";
	//    print_r($this->event);
	//    echo "</pre>";

	?>

    <a href="<?php echo \Joomla\CMS\Uri\Uri::root(); ?>"><?php echo \Joomla\CMS\Language\Text::_('COM_ALFA_CART_EMPTY_CONTINUE'); ?></a>

</div>