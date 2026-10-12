<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The builder's Customer & addresses tab.
 *
 * Three things live here, and each belongs to somebody else:
 *
 *  - WHO the cart is for. Read from the cart ROW and shown, never edited. A
 *    cart's customer is the axis its prices, rules, coupons, credit and offered
 *    methods were all computed on, and there is no operation in this component
 *    that moves a cart between customers, so this tab does not pretend there
 *    is one. Selling to somebody else means starting that customer's own cart.
 *
 *  - The customer's ADDRESS BOOK, owned by UserInfo. Rows are listed, labelled,
 *    added and edited through the canonical admin address editor: the very
 *    same modal, task and permission the User screen uses. This template writes
 *    no address SQL and duplicates no address form.
 *
 *  - Which two addresses THIS CART points at. Delivery and invoice are separate
 *    columns and separate choices; picking one posts the existing
 *    cart.pointAddress seam, which is what re-hydrates the cart, drops the
 *    offered methods and re-settles. Nothing here decides shipping, tax or
 *    eligibility; changing the pointer is the whole of this screen's part in it.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Cart\HtmlView $this
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$customer = $this->builderCustomer;
$cartId   = (int) $this->builderCartId;

if ($customer === null) {
    echo '<div class="alert alert-warning">' . Text::_('COM_ALFA_CART_NOT_FOUND') . '</div>';

    return;
}

/** The canonical admin address editor, opened for THIS customer. */
$editorUrl = static fn (int $infoId): string => Route::_(sprintf(
    'index.php?option=com_alfa&view=user&layout=edit_userinfo&tmpl=component&user_id=%d&info_id=%d',
    $customer->customerId,
    $infoId,
));
?>

<div class="row">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_CART_DETAIL_OWNER'); ?></div>

            <div class="card-body">
                <?php if ($customer->isGuest) : ?>
                    <p class="mb-2"><?php echo Text::_('COM_ALFA_CART_BUILDER_CUSTOMER_GUEST'); ?></p>
                    <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ALFA_CUSTOMER_KIND_GUEST'); ?></span>
                    <?php // A guest's cart is priced and decided by the guest group
                          // and the groups above it. ?>
                    <?php foreach ($customer->groups as $groupTitle) : ?>
                        <span class="badge bg-info text-dark"><?php echo $this->escape($groupTitle); ?></span>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="fs-5 fw-semibold"><?php echo $this->escape($customer->name); ?></div>
                    <div class="text-muted"><?php echo $this->escape($customer->email); ?></div>

                    <?php if ($customer->username !== '') : ?>
                        <div class="small text-muted">
                            <?php echo Text::_('JGLOBAL_USERNAME'); ?>: <?php echo $this->escape($customer->username); ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-2">
                        <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ALFA_CUSTOMER_KIND_ACCOUNT'); ?></span>
                        <?php // The groups are the PRICING axis, so they are worth
                              // seeing beside the cart they priced. ?>
                        <?php foreach ($customer->groups as $groupTitle) : ?>
                            <span class="badge bg-info text-dark"><?php echo $this->escape($groupTitle); ?></span>
                        <?php endforeach; ?>
                    </div>

                    <?php // The User screen opens only with users edit, the same decision
                          // the address buttons use; the task opens it through the edit session. ?>
                    <?php if ($this->canEditAddresses) : ?>
                        <div class="mt-3">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?php echo Route::_('index.php?option=com_alfa&task=user.edit&id=' . (int) $customer->customerId); ?>"
                               target="_blank" rel="noopener">
                                <span class="icon-user" aria-hidden="true"></span>
                                <?php echo Text::_('COM_ALFA_CART_BUILDER_CUSTOMER_OPEN'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php // A CART BELONGS TO ITS CUSTOMER FOR LIFE. Everything already
                  // computed on it (prices, rules, coupons, credit, eligible
                  // methods, and the addresses it points at) was computed for
                  // this person. There is no operation that re-bases all of that
                  // onto somebody else, so the honest offer is a different cart,
                  // not a different owner on this one. ?>
            <div class="card-footer small text-muted">
                <?php echo Text::_('COM_ALFA_CART_BUILDER_CUSTOMER_FIXED'); ?>
                <a href="<?php echo Route::_('index.php?option=com_alfa&view=carts'); ?>">
                    <?php echo Text::_('COM_ALFA_CART_BUILDER_CUSTOMER_OTHER'); ?>
                </a>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <?php echo Text::_('COM_ALFA_TITLE_USERINFO_VIEW_USERINFO'); ?>
                    <?php if ($customer->addresses !== []) : ?>
                        <span class="badge bg-secondary"><?php echo \count($customer->addresses); ?></span>
                    <?php endif; ?>
                </span>

                <?php // The address editor saves through user.saveUserinfo, which asks users edit. ?>
                <?php if (!$customer->isGuest && $this->canEditAddresses) : ?>
                    <a href="#builderAddressAddModal" data-bs-toggle="modal" class="btn btn-sm btn-success">
                        <span class="icon-plus" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_USERINFO_ADD'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="card-body">
                <?php if ($customer->isGuest) : ?>
                    <p class="text-muted mb-0"><?php echo Text::_('COM_ALFA_CART_BUILDER_ADDRESSES_GUEST'); ?></p>
                <?php elseif ($customer->addresses === []) : ?>
                    <p class="text-muted mb-0"><?php echo Text::_('COM_ALFA_USERINFO_EMPTY'); ?></p>
                <?php else : ?>
                    <?php // ONE FORM, TWO INDEPENDENT CHOICES. The seam takes both
                          // ids together, so both are posted together and neither
                          // can be left behind by the other. ?>
                    <form action="<?php echo Route::_('index.php?option=com_alfa'); ?>" method="post">
                        <table class="table align-middle mb-3">
                            <thead>
                                <tr>
                                    <th><?php echo Text::_('COM_ALFA_CART_BUILDER_ADDRESS'); ?></th>
                                    <th class="text-center" style="width:8rem;"><?php echo Text::_('COM_ALFA_CART_BUILDER_DELIVERY'); ?></th>
                                    <th class="text-center" style="width:8rem;"><?php echo Text::_('COM_ALFA_CART_BUILDER_INVOICE'); ?></th>
                                    <th style="width:6rem;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($customer->addresses as $address) :
                                $addressId = (int) $address->id;
                                $modalId   = 'builderAddressModal' . $addressId;
                                ?>
                                <tr>
                                    <td>
                                        <?php echo $this->escape($address->label); ?>
                                        <?php // A row an order already references is
                                              // frozen history: it may be pointed at,
                                              // never rewritten. ?>
                                        <?php if ($address->frozen) : ?>
                                            <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_USERINFO_BADGE_IN_ORDERS'); ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <input class="form-check-input" type="radio" name="delivery_id"
                                               value="<?php echo $addressId; ?>"
                                               <?php echo $addressId === (int) $customer->deliveryId ? 'checked' : ''; ?>>
                                    </td>

                                    <td class="text-center">
                                        <input class="form-check-input" type="radio" name="invoice_id"
                                               value="<?php echo $addressId; ?>"
                                               <?php echo $addressId === (int) $customer->invoiceId ? 'checked' : ''; ?>>
                                    </td>

                                    <td class="text-end">
                                        <?php if ($this->canEditAddresses) : ?>
                                            <a href="#<?php echo $modalId; ?>" data-bs-toggle="modal"
                                               class="btn btn-sm btn-outline-secondary">
                                                <?php echo Text::_('JACTION_EDIT'); ?>
                                            </a>

                                            <?php echo HTMLHelper::_('bootstrap.renderModal', $modalId, [
                                                'title'      => Text::_('COM_ALFA_USERINFO_EDIT'),
                                                'url'        => $editorUrl($addressId),
                                                'width'      => '80%',
                                                'bodyHeight' => '70vh',
                                            ]); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php // Leaving the invoice unchosen means "invoice to the
                              // delivery address", the same meaning the order
                              // screen and placement give it. ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="invoice_id" value="0"
                                   id="alfa-builder-invoice-same"
                                   <?php echo $customer->sameAddress ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="alfa-builder-invoice-same">
                                <?php echo Text::_('COM_ALFA_CART_BUILDER_INVOICE_SAME'); ?>
                            </label>
                        </div>

                        <?php // Pointing the cart at an address is a builder mutation (cart.pointAddress). ?>
                        <?php if ($this->canOperate) : ?>
                        <button type="submit" class="btn btn-primary">
                            <span class="icon-checkbox" aria-hidden="true"></span>
                            <?php echo Text::_('COM_ALFA_CART_BUILDER_ADDRESS_APPLY'); ?>
                        </button>
                        <?php endif; ?>

                        <input type="hidden" name="task" value="cart.pointAddress">
                        <input type="hidden" name="form" value="page">
                        <input type="hidden" name="cart_id" value="<?php echo $cartId; ?>">
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$customer->isGuest && $this->canEditAddresses) : ?>
                <?php echo HTMLHelper::_('bootstrap.renderModal', 'builderAddressAddModal', [
                    'title'      => Text::_('COM_ALFA_USERINFO_ADD'),
                    'url'        => $editorUrl(0),
                    'width'      => '80%',
                    'bodyHeight' => '70vh',
                ]); ?>
            <?php endif; ?>
        </div>

        <?php // What the cart is pointing at right now, in the one address
              // renderer this component has. ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header"><?php echo Text::_('COM_ALFA_CART_BUILDER_DELIVERY'); ?></div>
                    <div class="card-body">
                        <?php echo $customer->delivery !== null
                            ? $this->escape(\Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper::label($customer->delivery))
                            : '<span class="text-muted">' . Text::_('COM_ALFA_CART_BUILDER_ADDRESS_NONE') . '</span>'; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header"><?php echo Text::_('COM_ALFA_CART_BUILDER_INVOICE'); ?></div>
                    <div class="card-body">
                        <?php if ($customer->invoice !== null && !$customer->sameAddress) : ?>
                            <?php echo $this->escape(\Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper::label($customer->invoice)); ?>
                        <?php elseif ($customer->deliveryId > 0) : ?>
                            <span class="text-muted"><?php echo Text::_('COM_ALFA_CART_BUILDER_INVOICE_SAME'); ?></span>
                        <?php else : ?>
                            <span class="text-muted"><?php echo Text::_('COM_ALFA_CART_BUILDER_ADDRESS_NONE'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
