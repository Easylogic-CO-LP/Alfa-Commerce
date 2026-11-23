<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use InvalidArgumentException;

/**
 * The pricing instructions of an order line written by a client: the REST line verbs and an extension read
 * them through this one reader, then hand them to OrderModel::saveOrderItem(), so no door keeps a rule of its own.
 *
 * unit_price_tax_incl is a DERIVED column and is refused as an input: overloading it as the custom-price instruction
 * would make one field mean two things. The custom price goes through the one decoder the order popup uses
 * ({@see CustomPriceCommand::fromPayload()}), so "abc", true, "", "1e1" and a negative amount are refused naming the
 * field, and so is an amount beyond Alfa's unit-rate precision (8 decimal places, 14 significant digits).
 *
 * THE MANUAL DISCOUNT HAS THE POPUP'S NAME, manual_discount_percent, and the popup's meaning: the line's stored
 * setting, which a value sets and 0 clears, so a retried write changes nothing. It is read by the writer's own rule
 * ({@see OrderModel::manualDiscountPercent()}): 0-100 in whole hundredths. The stored whole-line rate,
 * discount_percent, is refused as an input like unit_price_tax_incl. THE TICK HAS THE COLUMN'S NAME,
 * manual_discount_scope, and its two values; omitted, the line's choice stands.
 *
 * @api READ ONLY: the line pricing instructions of a payload (custom_unit_price_tax_incl, manual_discount_percent,
 *      manual_discount_scope), validated, as OrderModel::saveOrderItem() takes them; a refusal names the field.
 *
 * @since  2.2.2
 */
final class LinePricing
{
    /**
     * The pricing instructions of a line payload, validated before anything is written.
     *
     * @param array<string, mixed> $payload The line's fields as the client sent them.
     *
     * @return array{price: CustomPriceCommand, manualDiscountPercent: ?float, manualDiscountScope: ?ManualDiscountScope}
     *
     * @throws InvalidArgumentException When an input is not a usable instruction; the message names the field.
     *
     * @since  2.2.2
     */
    public static function fromPayload(array $payload): array
    {
        if (\array_key_exists('unit_price_tax_incl', $payload)) {
            throw new InvalidArgumentException(
                'unit_price_tax_incl is derived and cannot be written; send ' . CustomPriceCommand::FIELD
                . ' (a number to set, null to clear, omitted to leave unchanged)',
            );
        }

        if (\array_key_exists('discount_percent', $payload)) {
            throw new InvalidArgumentException(
                'discount_percent is the line\'s derived discount rate and cannot be written; send '
                . 'manual_discount_percent (0-100) to take a manual discount off the line',
            );
        }

        try {
            $price = CustomPriceCommand::fromPayload(payload: $payload);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(CustomPriceCommand::FIELD . ': ' . $e->getMessage(), 0, $e);
        }

        $manual = null;

        if (\array_key_exists('manual_discount_percent', $payload)) {
            $manual = OrderModel::manualDiscountPercent($payload['manual_discount_percent']);

            if ($manual === null) {
                throw new InvalidArgumentException('manual_discount_percent must be a number from 0 to 100 with at most two decimal places (0 clears the manual discount)');
            }
        }

        $scope = null;

        if (\array_key_exists('manual_discount_scope', $payload)) {
            $value = $payload['manual_discount_scope'];
            $scope = \is_string($value) ? ManualDiscountScope::tryFrom($value) : null;

            if ($scope === null) {
                throw new InvalidArgumentException(
                    'manual_discount_scope must be ' . ManualDiscountScope::ExcludeCustomPrice->value
                    . ' or ' . ManualDiscountScope::ApplyToCustomPrice->value,
                );
            }
        }

        return ['price' => $price, 'manualDiscountPercent' => $manual, 'manualDiscountScope' => $scope];
    }
}
