<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Alfa\Component\Alfa\Administrator\Event\Shipments\CalculateShippingCostEvent;
use Alfa\Component\Alfa\Site\Service\Cart\CartLine;
use Alfa\Component\Alfa\Site\Service\Cart\HydratedCart;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The carriers' answers of this request, one per shipping method per cart state.
 *
 * ONE QUESTION, ONE ANSWER. A carrier is asked its price for every reading of the cart: the cart module and the cart
 * page of one request, the offer list and the totals, placement's test of the chosen method and its pricing. A carrier
 * with an outside service would be called for each. The dispatch asks this store first and puts the question to the
 * carrier only for a state not asked yet in this request; nothing is kept between requests.
 *
 * THE STATE IS WHAT THE CARRIER READS. The method as stored and whether the cart has it chosen; the payable lines
 * (product, combination, quantity, measurements, line totals); the merchandise subtotal; the currency, the customer,
 * the chosen shipping and payment methods; the delivery and invoice details, saved and as the checkout submitted them,
 * with the destination the request bound. Any of these changing is a new question.
 *
 * THE ANSWER, NOT THE CHARGE. What is kept is the carrier's own answer (the price it quoted or the amounts it stated,
 * its refusal, its silence, its failure); the entry mode and the tax chain are applied to it on every reading from the
 * method's settings as they are. A carrier's answer must follow from the cart it is handed: state of its own that
 * changes within the request (a session value, a table of its own) is read on the next request.
 *
 * @since  2.3.0
 */
final class CarrierAnswers
{
    /**
     * How many answers one request keeps; past it the store starts again, so a long command-line run that prices many
     * carts does not hold every answer.
     *
     * @since  2.3.0
     */
    private const LIMIT = 256;

    /**
     * The answers, by the key of the question.
     *
     * @var array<string, array{event: CalculateShippingCostEvent, asked: bool, failed: bool}>
     *
     * @since  2.3.0
     */
    private static array $answers = [];

    /**
     * The key of a question: the method, whether it is the chosen one, and the cart state the carrier reads.
     *
     * @param object $method The method as stored.
     * @param bool $chosen Whether the cart has it chosen (or placement assumes it).
     * @param HydratedCart $cart The payable cart the carrier is handed.
     * @param string $subtotal The merchandise subtotal, as a decimal text.
     * @param array $delivery The delivery values the checkout submitted, with the bound destination.
     * @param array $invoice The invoice values the checkout submitted.
     *
     * @since  2.3.0
     */
    public static function keyOf(object $method, bool $chosen, HydratedCart $cart, string $subtotal, array $delivery, array $invoice): string
    {
        $lines = [];

        foreach ($cart->lines() as $row) {
            $line = CartLine::of($row);
            $price = $row->data->price ?? null;
            $lines[] = [
                $line->id(), $line->itemId(), $line->combinationId(), $line->quantity(),
                $line->width(), $line->height(), $line->depth(), $line->weight(), $line->isVirtual(),
                \is_object($price) ? $price->getTotal()->getMinorUnits() : null,
                \is_object($price) ? $price->getSubtotal()->getMinorUnits() : null,
            ];
        }

        $subject = $cart->subject();

        return sha1((string) json_encode([
            'method' => (array) $method,
            'chosen' => $chosen,
            'cart' => [$cart->id(), $cart->shipmentId(), $cart->paymentId(), $cart->currencyId(),
                $subject->customerId(), $subject->isOperatorPlacement()],
            'lines' => $lines,
            'subtotal' => $subtotal,
            'addresses' => [(array) ($cart->deliveryAddress() ?? []), (array) ($cart->invoiceAddress() ?? []), $delivery, $invoice],
        ], JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * The answer given to this question in this request, or null when it was not asked yet.
     *
     * @param string $key The question's key ({@see self::keyOf()}).
     *
     * @return array{event: CalculateShippingCostEvent, asked: bool, failed: bool}|null
     *
     * @since  2.3.0
     */
    public static function recall(string $key): ?array
    {
        return self::$answers[$key] ?? null;
    }

    /**
     * Keep the answer given to this question.
     *
     * @param string $key The question's key.
     * @param CalculateShippingCostEvent $event The event as the carrier left it.
     * @param bool $asked Whether the carrier was asked (one switched off or without the hook was not).
     * @param bool $failed Whether the carrier threw.
     *
     * @return array{event: CalculateShippingCostEvent, asked: bool, failed: bool}
     *
     * @since  2.3.0
     */
    public static function remember(string $key, CalculateShippingCostEvent $event, bool $asked, bool $failed): array
    {
        if (\count(self::$answers) >= self::LIMIT) {
            self::$answers = [];
        }

        return self::$answers[$key] = ['event' => $event, 'asked' => $asked, 'failed' => $failed];
    }
}
