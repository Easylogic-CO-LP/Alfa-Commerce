<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\FormErrors;
use Joomla\CMS\Form\Form;
use Throwable;

defined('_JEXEC') or die;

/**
 * Are these customer details good enough to place an order with?
 *
 * The storefront has always answered this, and answered it well: the checkout
 * form is built from #__alfa_form_fields, a field's mandatoriness comes off its
 * shipping level, and the model validates the submitted buckets against the
 * form before anything is placed. What it has never done is answer it anywhere
 * else. Placement takes a plain array of column values, and the only thing it
 * checks is that a guest supplied an email, so a caller that hands it an array
 * directly, rather than a filled-in form, meets none of the merchant's own
 * requirements.
 *
 * That gap is harmless while every caller IS the form. It stops being harmless
 * the moment staff can type an order in, because "programmatic" would then mean
 * "exempt", and an operator would be able to create orders the shop's own rules
 * say are incomplete.
 *
 * So this asks the same question of the same rules. It builds the same form,
 * prepares it through the same FieldsHelper for the same context, and validates
 * the same buckets: the rules live in one place and are not restated here.
 * What it adds is that it needs no request, no session and no browser: the two
 * identities are passed in, so it can be asked about a customer who is not
 * present.
 *
 * AND IT HANDS BACK WHAT THE FORM MADE. The model filters before it validates
 * and returns the filtered copy, which is what the storefront stores: a
 * telephone number in E.164, not as typed. Validating and then keeping the
 * input would pass the rules on one shape and store another, so the answer
 * here carries the filtered buckets alongside the verdict.
 *
 * @since  2.0.0
 */
final class CustomerDetailsValidator
{
    /**
     * Filter, then validate, a set of typed details for a customer.
     *
     * The order is the model's: the form filters first and validates the
     * filtered copy, and the filtered copy is what comes back.
     *
     * @param array $delivery Column => value for the delivery details.
     * @param array $billing Column => value for the invoice details, or [].
     * @param int $customerId Whose fields these are; 0 for a guest.
     * @param int|null $actorId Who is filling them in; null = the customer.
     *
     * @return CheckedDetails The filtered buckets, and why they were refused if they were.
     *
     * @since  2.0.0
     */
    public static function validate(array $delivery, array $billing = [], int $customerId = 0, ?int $actorId = null): CheckedDetails
    {
        $form = self::form();

        if ($form === null) {
            // A form that will not build cannot be used to refuse anything.
            // Saying so is better than passing everything.
            return CheckedDetails::uncheckable(delivery: $delivery, billing: $billing);
        }

        $data = [FieldsHelper::FIELDS_KEY => $delivery];

        if ($billing !== []) {
            $data[FieldsHelper::BILLING_FIELDS_KEY] = $billing;
        }

        FieldsHelper::prepareForm(
            'cart.shipping',
            $form,
            $data,
            'cart',
            userId: $actorId ?? $customerId,
            customerId: $customerId,
        );

        if ($billing !== []) {
            FieldsHelper::prepareForm(
                'cart.billing',
                $form,
                $data,
                'cart_billing',
                group: FieldsHelper::BILLING_FIELDS_KEY,
                userId: $actorId ?? $customerId,
                customerId: $customerId,
            );
        } else {
            $form->removeGroup(FieldsHelper::BILLING_FIELDS_KEY);
        }

        // FILTER, THEN VALIDATE THE FILTERED COPY: the order FormModel keeps,
        // and the reason the copy is what is handed back: a value the rules
        // accepted is the value the filter made, not the one typed. A form
        // that cannot filter (no fields, or an unreadable definition) cannot
        // be used to accept anything either.
        try {
            $filtered = $form->filter($data);
        } catch (Throwable) {
            $filtered = false;
        }

        if (!\is_array($filtered)) {
            return CheckedDetails::uncheckable(delivery: $delivery, billing: $billing);
        }

        $failures = [];

        if ($form->validate($filtered) === false) {
            // FormErrors already knows how to tell a shopper's mistake from an
            // infrastructure failure by exception CLASS rather than by reading
            // its words. Only the first kind is a reason to refuse the details.
            $partitioned = FormErrors::partition($form->getErrors());

            foreach ($partitioned['messages'] as $message) {
                $failures[] = ['code' => (string) $message, 'params' => []];
            }

            if ($failures === [] && $partitioned['failures'] !== []) {
                $failures[] = ['code' => 'COM_ALFA_ORDER_PLACE_DETAILS_UNCHECKABLE', 'params' => []];
            }
        }

        return new CheckedDetails(
            delivery: (array) ($filtered[FieldsHelper::FIELDS_KEY] ?? []),
            billing: $billing === [] ? [] : (array) ($filtered[FieldsHelper::BILLING_FIELDS_KEY] ?? []),
            failures: $failures,
        );
    }

    /**
     * The checkout form, built without a model or a request.
     *
     *
     * @since  2.0.0
     */
    private static function form(): ?Form
    {
        try {
            Form::addFormPath(JPATH_SITE . '/components/com_alfa/forms');

            $form = Form::getInstance('com_alfa.cart.operator', 'cart', ['control' => 'cartform']);

            return $form ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
