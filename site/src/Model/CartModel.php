<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Site\Service\ShowOn\SubmitAuthority;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormModel;

/**
 * Alfa model.
 *
 * @since  1.0.0
 */
class CartModel extends FormModel
{
    protected $form_name = 'cart';

    /**
     * Load the cart checkout form (control name "cartform") and apply the custom Alfa form fields.
     *
     * @param array $data Data to bind to the form.
     * @param bool $loadData Whether to load the form data from getData().
     *
     * @return \Joomla\CMS\Form\Form|false The prepared form, or false on failure.
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.cart',
            'cart',
            [
                'control' => 'cartform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
            return false;
        }

        // Two buckets, same field names: the shipping section posts under
        // FIELDS_KEY, the billing copy under BILLING_FIELDS_KEY; the bucket
        // carries the role, the field name carries the user_info column.
        FieldsHelper::prepareForm('cart.shipping', $form, $data, 'cart');
        FieldsHelper::prepareForm('cart.billing', $form, $data, 'cart_billing', group: FieldsHelper::BILLING_FIELDS_KEY);

        // Field values survive redirects through loadFormData (user state);
        // with no state yet, a sole saved row pre-fills as the default.
        $stateData = $this->loadFormData();

        if (!empty($stateData)) {
            $form->bind($stateData);
        } elseif (!$this->isNewUserInfoPicked()) {
            $rows = $this->getSavedUserInfo();

            if (\count($rows) === 1) {
                $values = get_object_vars($rows[0]);
                unset($values['id'], $values['id_user']);
                $form->bind([FieldsHelper::FIELDS_KEY => $values]);
            }
        }

        // A member's name pre-fills from the account when nothing else did;
        // the recipient may still be someone else.
        $user = Factory::getApplication()->getIdentity();
        $nameColumn = RoleRegistry::column(RoleRegistry::NAME);

        if (
            $nameColumn !== null
            && (int) $user->id > 0
            && trim((string) $form->getValue($nameColumn, FieldsHelper::FIELDS_KEY, '')) === ''
        ) {
            $form->setValue($nameColumn, FieldsHelper::FIELDS_KEY, (string) $user->name);
        }

        return $form;
    }

    /**
     * Validate the checkout submission, after taking off the form everything the
     * customer was never shown: the browser posts neither, so demanding either
     * made the order unplaceable:
     *
     *   - the whole INVOICE section unless "different details" is ticked (its
     *     fields are required for an invoice, not for every order);
     *   - each field a ShowOn rule hides, per section ({@see SubmitAuthority}).
     *
     * Removed fields also drop out of `Form::filter()`, so nothing the customer
     * could not see reaches the order or a saved user-info row.
     *
     * @param \Joomla\CMS\Form\Form $form The checkout form.
     * @param array $data The posted data.
     * @param string|null $group The form group.
     *
     * @return array|false The filtered data, or false on validation failure.
     *
     * @since  2.0.0
     */
    public function validate($form, $data, $group = null)
    {
        $data = (array) $data;

        $data[FieldsHelper::FIELDS_KEY] = SubmitAuthority::prune(
            form: $form,
            fields: FieldsHelper::getFields('cart.shipping'),
            values: (array) ($data[FieldsHelper::FIELDS_KEY] ?? []),
            group: FieldsHelper::FIELDS_KEY,
            // The toggle is a surface of the delivery scope in the DOM, so a rule
            // may address it; the invoice section is its own scope and never sees it.
            extra: ['invoice_differs' => empty($data['invoice_differs']) ? null : '1'],
        );

        if (empty($data['invoice_differs'])) {
            $form->removeGroup(FieldsHelper::BILLING_FIELDS_KEY);
            unset($data[FieldsHelper::BILLING_FIELDS_KEY]);
        } else {
            $data[FieldsHelper::BILLING_FIELDS_KEY] = SubmitAuthority::prune(
                form: $form,
                fields: FieldsHelper::getFields('cart.billing'),
                values: (array) ($data[FieldsHelper::BILLING_FIELDS_KEY] ?? []),
                group: FieldsHelper::BILLING_FIELDS_KEY,
            );
        }

        return parent::validate($form, $data, $group);
    }

    /**
     * The delivery place chosen on the checkout form, the delivery
     * section's country field as currently bound: typed values kept in
     * user state, else the sole saved address, else the field default.
     *
     * @return int|null The #__alfa_places id, or null when the form has no country field or no value yet.
     *
     * @since  1.0.0
     */
    public function getDeliveryPlaceId(): ?int
    {
        $field = FieldsHelper::deliveryPlaceField();

        if ($field === null) {
            return null;
        }

        $form = $this->getForm();

        if (!$form) {
            return null;
        }

        $placeId = (int) $form->getValue((string) $field->field_name, FieldsHelper::FIELDS_KEY, 0);

        return $placeId > 0 ? $placeId : null;
    }

    /**
     * Checkout form data held in user state, written by the controller
     * boundaries (saved-info pick, failed validation) and cleared on
     * successful placement.
     *
     * @return array
     *
     * @since  2.0.0
     */
    protected function loadFormData()
    {
        return (array) Factory::getApplication()->getUserState('com_alfa.cart.form_data', []);
    }

    /**
     * The current user's saved info rows for the checkout picker. Rows
     * with no value on any ACTIVE cart field are omitted (leftovers of
     * removed/renamed fields would render as empty choices).
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    public function getSavedUserInfo(): array
    {
        $userId = $this->currentUserId();

        if ($userId <= 0) {
            return [];
        }

        // A saved record is an ADDRESS: a row holding only identity values
        // (the name a registration wrote) is not one to pick from.
        $roleColumns = array_filter(array_map([RoleRegistry::class, 'column'], RoleRegistry::roles()));
        $activeKeys = array_diff(array_column(FieldsHelper::getFields('cart.form'), 'field_name'), $roleColumns);
        $rows = [];

        foreach (UserInfoHelper::getRowsByUser(userId: $userId) as $row) {
            foreach ($activeKeys as $key) {
                if (trim((string) ($row->$key ?? '')) !== '') {
                    $rows[] = $row;
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * The saved-info row picked for this checkout (user state), validated
     * against ownership on every read.
     *
     * @return int Row id, 0 = none.
     *
     * @since  2.0.0
     */
    public function getPickedUserInfoId(): int
    {
        if ($this->isNewUserInfoPicked()) {
            return 0;
        }

        $picked = (int) Factory::getApplication()->getUserState('com_alfa.cart.userinfo_pick', 0);

        if ($picked > 0) {
            return UserInfoHelper::getRow(userId: $this->currentUserId(), id: $picked) !== null ? $picked : 0;
        }

        // A sole saved row is the implicit default (mirrors the pre-fill).
        $rows = $this->getSavedUserInfo();

        return \count($rows) === 1 ? (int) $rows[0]->id : 0;
    }

    /**
     * The saved-info row picked for the BILLING section (user state),
     * ownership-validated. No implicit default; billing defaults to
     * "same as delivery" via the checkbox, not to a row.
     *
     * @return int Row id, 0 = none.
     *
     * @since  2.0.0
     */
    public function getPickedBillingInfoId(): int
    {
        $picked = Factory::getApplication()->getUserState('com_alfa.cart.userinfo_pick_billing');

        if ($picked === 'new' || (int) $picked <= 0) {
            return 0;
        }

        return UserInfoHelper::getRow(userId: $this->currentUserId(), id: (int) $picked) !== null ? (int) $picked : 0;
    }

    /**
     * Whether the billing picker explicitly chose "New details".
     *
     *
     * @since  2.0.0
     */
    public function isNewBillingPicked(): bool
    {
        return Factory::getApplication()->getUserState('com_alfa.cart.userinfo_pick_billing') === 'new';
    }

    /**
     * Whether the customer asked for different billing (invoice) details,
     * held in the same checkout state as the rest of the form.
     *
     *
     * @since  2.0.0
     */
    public function isInvoiceDifferent(): bool
    {
        return !empty($this->loadFormData()['invoice_differs']);
    }

    /**
     * Whether the customer explicitly chose "New details": the empty
     * form, with the sole-row default disabled.
     *
     *
     * @since  2.0.0
     */
    public function isNewUserInfoPicked(): bool
    {
        return Factory::getApplication()->getUserState('com_alfa.cart.userinfo_pick') === 'new';
    }

    /**
     * @return int The logged-in user id (0 for guests).
     *
     * @since  2.0.0
     */
    private function currentUserId(): int
    {
        return (int) Factory::getApplication()->getIdentity()?->id;
    }
}
