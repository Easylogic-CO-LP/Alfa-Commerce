<?php
    /**
     * @version    1.0.1
     * @package    Com_Alfa
     * @author     Agamemnon Fakas <info@easylogic.gr>
     * @copyright  2024 Easylogic CO LP
     * @license    GNU General Public License version 2 or later; see LICENSE.txt
     */

    // No direct access
    defined('_JEXEC') or die;

    use Alfa\Component\Alfa\Site\Helper\PluginLayoutHelper;
    use \Joomla\CMS\HTML\HTMLHelper;
    use \Joomla\CMS\Factory;
    use \Joomla\CMS\Uri\Uri;
    use \Joomla\CMS\Router\Route;
    use \Joomla\CMS\Language\Text;
    use \Joomla\CMS\Session\Session;
    use Joomla\Utilities\ArrayHelper;
    use Joomla\CMS\Layout\LayoutHelper;
    use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;

    // Import CSS
    $wa = $this->document->getWebAssetManager();

    $wa->useStyle('com_alfa.item');
    $wa->useScript('com_alfa.item')
        ->useScript('com_alfa.item.recalculate')
        ->useScript('com_alfa.item.addtocart');

    $priceSettings = $this->priceSettings;
    $medias = $this->item->medias;
?>

<div class="item-container" data-item-id="<?php echo $this->item->id; ?>">
    <?php if (!empty($medias)): ?>
        <div class="item-images-wrapper">
            <?php echo $this->loadTemplate('images'); ?>
        </div>
    <?php endif; ?>

    <div class="item-info">
        <div>
            <h1 class="item-name">
                <?php echo $this->item->name; ?>
            </h1>
        </div>
        <?php if (!empty($this->item->short_desc)): ?>
            <div class="item-short-description">
                <?php echo $this->item->short_desc; ?>
            </div>
        <?php endif; ?>

        <?php echo LayoutHelper::render('price', ['item' => $this->item, 'settings' => $priceSettings]); //passed data as $displayData in layout ?>

        <?php echo LayoutHelper::render('stock_info', ['item' => $this->item]); ?>


        <div class="item-payment-methods">
            <span class="item-payment-method-title">Payment Methods:</span>

            <div class="item-payment-method-inner">
			    <?php
				    if (!empty($this->item->payment_methods))
				    {
					    foreach ($this->item->payment_methods as $payment_method): ?>
                            <div class="item-payment-method-entry">
                                <div>
								    <?php
									    echo PluginLayoutHelper::pluginLayout(
										    $payment_method->events->onItemView->getLayoutPluginType(),
										    $payment_method->events->onItemView->getLayoutPluginName(),
										    $payment_method->events->onItemView->getLayout(),
									    )->render($payment_method->events->onItemView->getLayoutData());
								    ?>
                                </div>
                            </div>
					    <?php endforeach; ?>
				    <?php } ?>
            </div>
        </div>

        <div class="item-shipment-methods">
            Shipment Methods :

            <?php
                if (!empty($this->item->shipment_methods))
                {
                    foreach ($this->item->shipment_methods as $shipment_method): ?>
                        <div class="item-shipment-method">
                            <?php
                                echo PluginLayoutHelper::pluginLayout(
                                    $shipment_method->events->onItemView->getLayoutPluginType(),
                                    $shipment_method->events->onItemView->getLayoutPluginName(),
                                    $shipment_method->events->onItemView->getLayout(),
                                )->render($shipment_method->events->onItemView->getLayoutData());
                            ?>
                        </div>
                    <?php endforeach; ?>
                <?php } ?>

        </div>

        <?php echo LayoutHelper::render('add_to_cart', $this->item); ?>

        <div class="item-full-description">
            <?php echo nl2br($this->item->full_desc); ?>
        </div>

        <?php echo $this->loadTemplate('manufacturers'); ?>
        <?php echo $this->loadTemplate('categories'); ?>

    </div>
</div>