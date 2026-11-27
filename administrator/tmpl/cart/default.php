<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The administrator order builder: one live named cart, on one page.
 *
 * The sections follow in the order an operator works an order: the customer and the addresses, the items with the
 * coupon and the totals, the shipment and the payment, then the summary with the placement. Each section mutates the
 * cart through its own forms and the page re-renders from the settled snapshot. A plugin adds a section of its own by
 * loading a <fieldset> into the builder form through onAlfaAdminForm for the target 'cart', the same way it adds a tab
 * to the order and product screens; its sections follow the core ones, at the end of the page.
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this */

$wa = $this->document->getWebAssetManager();
$wa->useScript('com_alfa.core-loader')
    // The item-configuration and checkout fields carry ShowOn rules; the builder
    // must judge visibility exactly as the storefront does.
    ->useScript('com_alfa.showon.engine')
    ->useStyle('com_alfa.admin');

Text::script('COM_ALFA_CART_CHECKOUT_FAILED');

$cart      = $this->item;
$cartId    = (int) $this->builderCartId;
$badge     = ['active' => 'bg-primary', 'abandoned' => 'bg-warning text-dark', 'empty' => 'bg-light text-dark border'];
$statusKey = ['active' => 'COM_ALFA_CARTS_STATUS_ACTIVE', 'abandoned' => 'COM_ALFA_CARTS_STATUS_ABANDONED', 'empty' => 'COM_ALFA_CARTS_STATUS_EMPTY'];
?>

<div id="cart-builder" class="cart-builder" data-cart-id="<?php echo $cartId; ?>">

    <?php // A cart a storefront shopper created says so, above everything else. ?>
    <?php echo $this->loadTemplate('shopper'); ?>

    <section id="builder-customer" class="builder-section" aria-labelledby="builder-customer-title">
        <h2 class="h3 mb-3" id="builder-customer-title">
            <span class="icon-user me-2" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_CART_BUILDER_TAB_CUSTOMER'); ?>
        </h2>
        <?php echo $this->loadTemplate('customer'); ?>
    </section>

    <section id="builder-lines" class="builder-section" aria-labelledby="builder-lines-title">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h3 mb-0" id="builder-lines-title">
                <span class="icon-cube me-2" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_CART_BUILDER_TAB_ITEMS'); ?>
            </h2>

            <?php // The popup and cart.addLine both ask carts checkout, and Carts: Edit on a shopper's cart. ?>
            <?php if ($this->canOperate) : ?>
                <a href="#addCartLineModal" data-bs-toggle="modal" class="btn btn-success">
                    <span class="icon-plus" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_CART_BUILDER_ADD_ITEM'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php echo $this->loadTemplate('lines'); ?>
    </section>

    <section id="builder-methods" class="builder-section" aria-labelledby="builder-methods-title">
        <h2 class="h3 mb-3" id="builder-methods-title">
            <span class="icon-credit me-2" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_CART_BUILDER_TAB_METHODS'); ?>
        </h2>
        <?php echo $this->loadTemplate('methods'); ?>
    </section>

    <section id="builder-summary" class="builder-section" aria-labelledby="builder-summary-title">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h2 class="h3 mb-0 me-2" id="builder-summary-title">
                <span class="icon-checkmark me-2" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_CART_BUILDER_TAB_SUMMARY'); ?>
            </h2>
            <span class="badge <?php echo $badge[$cart->bucket]; ?>"><?php echo Text::_($statusKey[$cart->bucket]); ?></span>
            <span class="text-muted small">
                <?php echo Text::sprintf('COM_ALFA_CART_UNITS_IN_LINES', (int) $cart->unit_count, (int) $cart->line_count); ?>
            </span>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><?php echo Text::_('COM_ALFA_CART_DETAIL_ACTIVITY'); ?></div>
                    <div class="card-body small">
                        <div><?php echo Text::_('COM_ALFA_CARTS_HEADING_CREATED'); ?>:
                            <?php echo HTMLHelper::_('date', $cart->added, Text::_('DATE_FORMAT_LC5')); ?></div>
                        <div><?php echo Text::_('COM_ALFA_CARTS_HEADING_ACTIVITY'); ?>:
                            <?php echo HTMLHelper::_('date', $cart->updated, Text::_('DATE_FORMAT_LC5')); ?></div>
                    </div>
                </div>
            </div>

            <?php if ($cart->codes !== []) : ?>
                <div class="col-lg-4">
                    <div class="card mb-3">
                        <div class="card-header"><?php echo Text::_('COM_ALFA_CART_COUPON_LABEL'); ?></div>
                        <div class="card-body">
                            <?php foreach ($cart->codes as $code) : ?>
                                <span class="badge text-bg-info me-1"><?php echo $this->escape((string) $code); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php // Placement: everything commercial about this cart was decided in the sections above and lives on the
              // cart; what is left to say is placement intent, the status the order lands in and whether the
              // customer's credit is spent. The methods, the customer and the addresses are stated here as facts,
              // not offered again as inputs: a second copy of them in this form would be a second authority, and the
              // one that arrived in a POST is exactly the one nobody should trust. ?>
        <?php echo $this->loadTemplate('place'); ?>
    </section>

    <?php
    // Plugin sections. A plugin that loads a named <fieldset> into the builder form through onAlfaAdminForm (target
    // 'cart') gets it here as a section of its own, after the core ones, headed by the fieldset's label. The one core
    // fieldset the form ships is skipped; it exists only to make the form valid.
    if ($this->form) :
        foreach ($this->form->getFieldsets() as $fieldsetName => $fieldset) :
            if ($fieldsetName === 'builder' || !empty($fieldset->target)) {
                continue;
            }

            $fieldsetLabel = !empty($fieldset->label)
                ? Text::_($fieldset->label)
                : ucwords(str_replace('_', ' ', $fieldsetName));
            $sectionId = 'builder-plg-' . $fieldsetName;
            ?>
            <section id="<?php echo $this->escape($sectionId); ?>" class="builder-section"
                     aria-labelledby="<?php echo $this->escape($sectionId); ?>-title">
                <h2 class="h3 mb-3" id="<?php echo $this->escape($sectionId); ?>-title">
                    <span class="icon-puzzle me-2" aria-hidden="true"></span>
                    <?php echo $fieldsetLabel; ?>
                </h2>
                <div class="card mb-3">
                    <div class="card-body">
                        <?php echo $this->form->renderFieldset($fieldsetName); ?>
                    </div>
                </div>
            </section>
        <?php endforeach;
    endif;
    ?>

    <?php // Plugin render slot: contributed markup that belongs to the builder as a whole. ?>
    <?php echo $this->renderSlot('cart-main'); ?>

</div>
