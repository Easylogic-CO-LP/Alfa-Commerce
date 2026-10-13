<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrencyException;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Throwable;

defined('_JEXEC') or die;

/**
 * HOW AN ISSUED DOCUMENT PRINTS ITS MONEY: ONE AUTHORITY, FOR EVERY PARTIAL.
 *
 * The document layouts used to format money with a hardcoded
 * `number_format($v, 2, ',', '.')`, written twice: once in the lines partial
 * and once in the totals partial. That had two consequences, and only the
 * second was ever noticed:
 *
 *   1. AN ISSUED DOCUMENT WAS IMMUNE TO A CURRENCY EDIT BY ACCIDENT. Nothing
 *      in rendering consulted the currency at all, so a merchant changing
 *      decimal_place never moved a printed figure. The right answer, reached
 *      for the wrong reason.
 *   2. AND IT HAS ALWAYS PRINTED WRONG FOR ANY CURRENCY THAT IS NOT
 *      TWO-DECIMAL. A shop selling in a zero-decimal currency printed
 *      "1.234,00" on every invoice; one at eight decimals printed a rounded
 *      figure its own ledger disagreed with.
 *
 * Both are fixed by the same move: a document freezes the monetary scale it
 * was issued under, and rendering asks THIS class rather than the currency
 * table. The accident becomes a rule, and the rule is right at every scale.
 *
 * @since  2.0.0
 */
final class DocumentMoney
{
    /**
     * The scale a document with no frozen fact was actually printed at.
     *
     * NOT a fallback to the live currency row, and deliberately not a guess
     * about what that document "should" have been: every pre-v1 document was
     * rendered by a hardcoded two decimals, so two decimals IS what it printed
     * and what it must keep printing. Inferring anything else, least of all
     * from today's configuration, would change a fiscal record after the
     * fact, which is the whole thing this item exists to prevent.
     */
    private const LEGACY_PRINTED_SCALE = 2;

    /**
     * A money formatter that speaks one document's frozen scale.
     *
     * Every partial of a document layout takes its formatter from here, so a
     * document cannot print its lines at one scale and its totals at another.
     *
     * @param object|null $document The document row; its `currency_scale` is the authority.
     *
     * @return callable(float): string
     *
     * @since  2.0.0
     */
    public static function formatter(?object $document): callable
    {
        $scale = self::scaleOf($document);

        return static fn (float $value): string => number_format($value, $scale, ',', '.');
    }

    /**
     * A unit-price formatter for one document: the one unit rule
     * ({@see Currency::unitFigure()}) at the document's frozen scale, under the
     * shop's unit display setting, in the same separators formatter() prints.
     *
     * A unit is presentation and the line's total is the money, so a unit is
     * shown from the line's own quantity and total in the same tax space: 100.81
     * over 1000 prints 0,10081 under Auto, never a 0,10 that times 1000 is not
     * the line, and a stored division tail that its line does not need is never
     * printed. The agreed custom rate is passed beside the gross unit only.
     * Totals keep formatter().
     *
     * @param object|null $document The document row; its `currency_scale` is the scale.
     *
     * @return callable(float|int|string, float|int|string, float|int|string, float|int|string|null=): string
     *                                                                                                        (unit, lineTotal, quantity, rate)
     *
     * @since  2.0.0
     */
    public static function unitFormatter(?object $document): callable
    {
        $scale = self::scaleOf(document: $document);
        $auto = Currency::unitPrecisionIsAuto();

        return static fn (float|int|string $unit, float|int|string $lineTotal, float|int|string $quantity, float|int|string|null $rate = null): string => Currency::groupFigure(
            figure: Currency::unitFigure(unit: $unit, lineTotal: $lineTotal, quantity: $quantity, scale: $scale, rate: $rate, auto: $auto)['figure'],
            decimalSeparator: ',',
            thousandSeparator: '.',
        );
    }

    /**
     * A document's currency AT ITS OWN FROZEN SCALE.
     *
     * The companion to {@see self::scaleOf()}, for the guards rather than the
     * layouts. An issued document's money means what it meant when it was
     * issued, so every exact comparison against it (credit caps, per-source
     * ceilings) must be asked in THIS currency and not in the order's and not
     * in today's configuration.
     *
     * THE DOCUMENT'S OWN FROZEN FACTS, IN ORDER. The currency row its header
     * names; for a header whose id names no row, the currency code it froze
     * beside it; and for a header that froze neither, the currency of its
     * order, which is the one it was issued in and which an order never
     * changes ({@see OrderCurrency}). No other currency is put in its place:
     * a document whose order names none has no money to correct.
     *
     * A missing document is not a document without a currency: the caller
     * refuses it for what it is, and the figures of nothing are read in the
     * currency a new order takes.
     *
     * @param object|null $document The document row (id_currency, currency_code,
     *                              currency_scale, id_order).
     *
     *
     * @throws OrderCurrencyException When the document names no currency and
     *                                neither does its order.
     *
     * @since  2.0.0
     */
    public static function currencyOf(?object $document): Currency
    {
        if ($document === null) {
            return OrderCurrency::forNewOrder();
        }

        $currency = null;
        $currencyId = (int) ($document->id_currency ?? 0);

        if ($currencyId > 0) {
            try {
                $currency = Currency::loadById($currencyId);
            } catch (Throwable $e) {
                $currency = null;
            }
        }

        $code = trim((string) ($document->currency_code ?? ''));

        if ($currency === null && $code !== '') {
            try {
                $currency = Currency::loadByCode($code);
            } catch (Throwable $e) {
                $currency = null;
            }
        }

        $currency ??= OrderCurrency::of(orderId: (int) ($document->id_order ?? 0));

        try {
            return $currency->atScale(self::scaleOf($document));
        } catch (Throwable $e) {
            // An out-of-range stored scale is a data defect reported
            // elsewhere; it must not be what refuses a document.
            return $currency;
        }
    }

    /**
     * The scale a document prints and rounds at.
     *
     * `=== null`, never a falsy test: **0 is a real zero-decimal scale** and
     * must never be mistaken for an absent one.
     *
     * @param object|null $document The document row.
     *
     *
     * @since  2.0.0
     */
    public static function scaleOf(?object $document): int
    {
        $frozen = $document->currency_scale ?? null;

        return $frozen === null ? self::LEGACY_PRINTED_SCALE : (int) $frozen;
    }
}
