<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The administrator order builder: one live named cart, worked a tab at a time.
 *
 * The tab set is the shell. Its core tabs (Items, Customer, Shipment & Payment,
 * Summary) mutate the cart through the B1B.1c request seam and re-render from
 * the authoritative snapshot; a plugin adds its own tab by contributing a
 * <fieldset> through onAlfaAdminForm for entity 'cart', rendered by the
 * contributed-tab loop below with no change to this template: the same seam the
 * Order and Item edit screens use. This increment establishes the shell and the
 * seam; the core tabs' interiors are built in the increments that follow.
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

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

// A tab whose interior arrives in a later increment: a stable mount point the
// builder script fills, carrying the cart id every endpoint is scoped to.
$pending = static function (string $region) use ($cartId): string {
    return '<div class="alfa-builder-region" data-region="' . $region . '" data-cart-id="' . $cartId . '">'
        . '<div class="alert alert-info mb-0">' . Text::_('COM_ALFA_CART_BUILDER_REGION_PENDING') . '</div>'
        . '</div>';
};
?>

<div id="alfa-cart-builder" data-cart-id="<?php echo $cartId; ?>">

    <?php // A cart a storefront shopper created says so, above everything else. ?>
    <?php echo $this->loadTemplate('shopper'); ?>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'cartBuilder', ['active' => 'summary', 'recall' => true, 'breakpoint' => 768]); ?>

    <?php // TAB: Items - product search, the config modal, and the live lines ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'cartBuilder', 'items', Text::_('COM_ALFA_CART_BUILDER_TAB_ITEMS', true)); ?>
        <?php echo $this->loadTemplate('items'); ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php // TAB: Customer & addresses ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'cartBuilder', 'customer', Text::_('COM_ALFA_CART_BUILDER_TAB_CUSTOMER', true)); ?>
        <?php echo $this->loadTemplate('customer'); ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php // TAB: Shipment & payment ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'cartBuilder', 'methods', Text::_('COM_ALFA_CART_BUILDER_TAB_METHODS', true)); ?>
        <?php echo $this->loadTemplate('methods'); ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php // TAB: Summary & placement - today's read + place surface, unchanged, until its own increment ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'cartBuilder', 'summary', Text::_('COM_ALFA_CART_BUILDER_TAB_SUMMARY', true)); ?>

        <?php // The statement: every line, every price, and every total the
              // engine reached, full width, so nothing is abbreviated away. ?>
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge <?php echo $badge[$cart->bucket]; ?>"><?php echo Text::_($statusKey[$cart->bucket]); ?></span>
                <span class="text-muted small">
                    <?php echo Text::sprintf('COM_ALFA_CART_UNITS_IN_LINES', (int) $cart->unit_count, (int) $cart->line_count); ?>
                </span>
            </div>

            <div class="card-body p-0">
                <?php echo $this->loadTemplate('statement'); ?>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><?php echo Text::_('COM_ALFA_CART_DETAIL_OWNER'); ?></div>
                    <div class="card-body">
                        <?php if ($cart->owner->name !== '' || $cart->owner->email !== '') : ?>
                            <div><?php echo $this->escape($cart->owner->name); ?></div>
                            <div class="small text-muted"><?php echo $this->escape($cart->owner->email); ?></div>
                        <?php else : ?>
                            <span class="text-muted"><?php echo Text::_('COM_ALFA_CART_DETAIL_OWNER_UNKNOWN'); ?></span>
                        <?php endif; ?>
                        <div class="mt-2">
                            <span class="badge bg-light text-dark border">
                                <?php // ONE VOCABULARY. "Account or guest" is the distinction the Order screen
                                      // and the Carts filter already name; a third word for it
                                      // ("Member") only made the same cart read two ways. ?>
                                <?php echo Text::_($cart->owner->is_member ? 'COM_ALFA_CUSTOMER_KIND_ACCOUNT' : 'COM_ALFA_CUSTOMER_KIND_GUEST'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><?php echo Text::_('COM_ALFA_CART_DETAIL_ACTIVITY'); ?></div>
                    <div class="card-body small">
                        <div><?php echo Text::_('COM_ALFA_CARTS_HEADING_CREATED'); ?>:
                            <?php echo HTMLHelper::_('date', $cart->added, Text::_('DATE_FORMAT_LC5')); ?></div>
                        <div><?php echo Text::_('COM_ALFA_CARTS_HEADING_ACTIVITY'); ?>:
                            <?php echo HTMLHelper::_('date', $cart->updated, Text::_('DATE_FORMAT_LC5')); ?></div>
                    </div>
                </div>

                <?php if ($cart->codes !== []) : ?>
                    <div class="card mb-3">
                        <div class="card-header"><?php echo Text::_('COM_ALFA_CART_COUPON_LABEL'); ?></div>
                        <div class="card-body">
                            <?php foreach ($cart->codes as $code) : ?>
                                <span class="badge bg-info text-dark me-1"><?php echo $this->escape((string) $code); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // PLACEMENT. Everything commercial about this cart was decided on
              // the tabs beside this one and lives on the cart; what is left to
              // say is placement INTENT: the status the order lands in, and
              // whether the customer's credit is spent. The methods, the
              // customer and the addresses are stated here as facts, not
              // offered again as inputs: a second copy of them in this form
              // would be a second authority, and the one that arrived in a POST
              // is exactly the one nobody should trust. ?>
        <?php echo $this->loadTemplate('place'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php
    // CONTRIBUTED TABS. A plugin that loads a named <fieldset> into the builder
    // form through onAlfaAdminForm (target 'cart') gets it rendered here as its
    // own tab: the same convention the Order and Item screens use. The one core
    // fieldset the form ships is skipped; it exists only to make the form valid.
    if ($this->form) :
        foreach ($this->form->getFieldsets() as $fieldsetName => $fieldset) :
            if ($fieldsetName === 'builder' || !empty($fieldset->target)) {
                continue;
            }

            $fieldsetLabel = !empty($fieldset->label)
                ? Text::_($fieldset->label)
                : ucwords(str_replace('_', ' ', $fieldsetName));

            echo HTMLHelper::_('uitab.addTab', 'cartBuilder', 'plg-' . $fieldsetName, $fieldsetLabel);
            echo '<div class="row"><div class="col-12"><fieldset class="options-form"><legend>'
                . $fieldsetLabel . '</legend><div>'
                . $this->form->renderFieldset($fieldsetName)
                . '</div></fieldset></div></div>';
            echo HTMLHelper::_('uitab.endTab');
        endforeach;
    endif;
    ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php // Plugin render slot: contributed markup that belongs to the builder as a whole. ?>
    <?php echo $this->renderSlot('cart-main'); ?>

</div>
