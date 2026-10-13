<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Alfa\Component\Alfa\Site\Service\Pricing\UnitAxis;
use Alfa\Component\Alfa\Site\Service\Pricing\UnitOrigin;
use InvalidArgumentException;

/**
 * The unit prices a priced line is shown with, as text.
 *
 * A unit price is presentation: the line's money is what is charged. Every text
 * here is printed by {@see Currency::formatUnit()}, the one unit rule and the
 * only reader of the shop's unit display setting, from facts the PriceResult
 * states, its runtime provenance included. Nothing here computes money, and no
 * text is read back.
 *
 * @since  2.0.0
 */
final class PriceUnits
{
    /**
     * The per-unit figures of a price block, in the order it shows them.
     *
     * @since  2.0.0
     */
    public const FIGURES = ['base', 'discount', 'subtotal', 'tax', 'base_with_tax', 'final'];

    /**
     * What one piece of a cart line costs before discounts, from the line's own whole amount and quantity.
     *
     * An ordinary line's unit is its line per piece: the fewest decimals that
     * give the line back under Auto, the currency's scale otherwise. A
     * negotiated line's gross unit is the agreed rate itself, and its net unit
     * is the negotiated net line per piece.
     *
     * @param PriceResult $price The line's priced result.
     * @param bool $withTax True for the unit with tax, false for the unit before tax.
     * @param bool $includeSymbol Whether to include the currency symbol.
     *
     *
     * @since  2.0.0
     */
    public static function line(PriceResult $price, bool $withTax, bool $includeSymbol = true): string
    {
        [$unit, $line, $rate] = self::lineSource(price: $price, withTax: $withTax);
        $currency = $price->getCurrency();

        return $rate !== null
            ? self::stated(currency: $currency, unit: $rate, line: $line, quantity: $price->getQuantity(), includeSymbol: $includeSymbol, auto: null)
            : self::derived(currency: $currency, unit: $unit, line: $line, quantity: $price->getQuantity(), includeSymbol: $includeSymbol, auto: null);
    }

    /**
     * A cart line's unit published as data: the unit {@see self::line()} prints, as its exact decimal text.
     *
     * The amount is the line rule's figure in the Auto form (the agreed rate as
     * agreed, any other unit the fewest decimals that give the line back), never
     * the money of one piece: 0.004 x 1000 at 24% is 0.00496, not 0.00.
     * minor_units is set only when that figure sits on the scale; formatted is the
     * text the cart prints for it.
     *
     * @param PriceResult $price The line's priced result.
     * @param bool $withTax True for the unit with tax, false for the unit before tax.
     *
     * @return array{amount: string, formatted: string, minor_units: int|null}
     *
     * @since  2.0.0
     */
    public static function publishedLine(PriceResult $price, bool $withTax): array
    {
        [$unit, $line, $rate] = self::lineSource(price: $price, withTax: $withTax);
        $scale = $price->getCurrency()->getDecimalPlaces();
        $figure = Currency::unitFigure(
            unit: $rate ?? PublishedDecimal::money($unit),
            lineTotal: PublishedDecimal::money($line),
            quantity: $price->getQuantity(),
            scale: $scale,
            rate: $rate,
            auto: true,
        )['figure'];

        return [
            'amount' => PublishedDecimal::unit(value: $figure, scale: $scale),
            'formatted' => self::line(price: $price, withTax: $withTax),
            'minor_units' => PublishedDecimal::minorUnits(value: $figure, scale: $scale),
        ];
    }

    /**
     * What a cart line's unit is read from: the per-piece money, the whole line, and the agreed rate it states.
     *
     * An ordinary line's unit is its line per piece; a negotiated line's gross
     * unit is the agreed rate itself, and its net unit the negotiated net line
     * per piece.
     *
     * @param PriceResult $price The line's priced result.
     * @param bool $withTax True for the unit with tax, false for the unit before tax.
     *
     * @return array{0: Money|null, 1: Money, 2: string|null} [per piece (null beside a rate), line, the agreed rate or null]
     *
     * @since  2.0.0
     */
    private static function lineSource(PriceResult $price, bool $withTax): array
    {
        $explicit = $price->getExplicitPrice();

        if ($explicit !== null) {
            return $withTax
                ? [null, $price->getExplicitPriceTotalWithTax(), $explicit->rate]
                : [$price->getSubtotalPrice(), $price->getExplicitPriceTotal(), null];
        }

        return $withTax
            ? [$price->getBasePriceWithTax(), $price->getBaseTotalWithTax(), null]
            : [$price->getBasePrice(), $price->getBaseTotal(), null];
    }

    /**
     * The per-unit figures a product page, a listing or a payload shows for its quantity.
     *
     * - A figure that IS the stated unit prints that unit's own decimals: a
     *   price row's stored value on the net, a sale's own price, an agreed
     *   rate; the PriceResult's provenance says which figure that is.
     * - A computed unit (a variant's adjust, a gross target), a gross reached
     *   through tax, a discounted figure, a tax or a saving per piece is
     *   derived: the money of the page's quantity per piece.
     * - A positive payable unit whose one piece is zero money prints the
     *   decimals that make it positive (a net of 0.004 at 24% is 0.00496 with
     *   tax), on the figures that are that payable unit; a positive discounted
     *   unit likewise, from the exact unit after the discounts (0.004 less 10%
     *   is 0.0036 net, 0.004464 with tax).
     *
     * @param PriceResult $price The priced result at the page's quantity.
     * @param string[]|null $figures The figures the caller shows (of FIGURES); null for all.
     * @param bool $includeSymbol Whether to include the currency symbol.
     * @param bool|null $auto Null: the shop's setting. True: the Auto form, for data.
     *
     * @return array<string, string> The text of each figure asked for, by name.
     *
     * @since  2.0.0
     */
    public static function page(PriceResult $price, ?array $figures = null, bool $includeSymbol = true, ?bool $auto = null): array
    {
        $currency = $price->getCurrency();
        $quantity = $price->getQuantity();
        $texts = [];

        foreach ($figures ?? self::FIGURES as $figure) {
            [$unit, $line, $exact] = self::source(price: $price, figure: $figure);
            $texts[$figure] = $exact !== null
                ? self::stated(currency: $currency, unit: $exact, line: $line, quantity: $quantity, includeSymbol: $includeSymbol, auto: $auto)
                : self::derived(currency: $currency, unit: $unit, line: $line, quantity: $quantity, includeSymbol: $includeSymbol, auto: $auto);
        }

        return $texts;
    }

    /**
     * The exact value behind one page figure, as a plain decimal: the stated unit or the payable unit where the
     * figure is that unit, the exact unit after the discounts for a discounted figure, otherwise the line per piece
     * at the fewest decimals that give the line back.
     *
     * A unit price published as data carries this, never the money of one
     * piece: a payable 0.00496 is 0.00496, not 0.00.
     *
     * @param PriceResult $price The priced result.
     * @param string $figure One of FIGURES.
     *
     *
     * @since  2.0.0
     */
    public static function exact(PriceResult $price, string $figure): string
    {
        [$unit, $line, $exact] = self::source(price: $price, figure: $figure);

        if ($exact !== null) {
            return $exact;
        }

        if (self::payable(price: $price, figure: $figure)) {
            return $price->getPayableUnitExact();
        }

        $discounted = self::discounted(price: $price, figure: $figure);

        if ($discounted !== null) {
            return $discounted;
        }

        return Currency::unitFigure(
            unit: PublishedDecimal::money($unit),
            lineTotal: PublishedDecimal::money($line),
            quantity: $price->getQuantity(),
            scale: $price->getCurrency()->getDecimalPlaces(),
            rate: null,
            auto: true,
        )['figure'];
    }

    /**
     * One page figure published as data: its exact value beside the text a surface prints for it.
     *
     * The amount is exact() as an exact decimal string, never the money of one
     * piece (a payable 0.00496 is "0.00496", not "0.00") and never a float;
     * minor_units is set only when that value sits on the currency's scale, so a
     * count of minor units never contradicts it. Every surface that publishes a
     * unit as data (the REST catalogue, the storefront search payload) uses this
     * one rule.
     *
     * @param PriceResult $price The priced result.
     * @param string $figure One of FIGURES.
     * @param string $text The text the surface prints for the figure.
     *
     * @return array{amount: string, formatted: string, minor_units: int|null}
     *
     * @since  2.0.0
     */
    public static function published(PriceResult $price, string $figure, string $text): array
    {
        $exact = self::exact(price: $price, figure: $figure);
        $scale = $price->getCurrency()->getDecimalPlaces();

        return [
            'amount' => PublishedDecimal::unit(value: $exact, scale: $scale),
            'formatted' => $text,
            'minor_units' => PublishedDecimal::minorUnits(value: $exact, scale: $scale),
        ];
    }

    /**
     * One figure's per-piece money, its line, and the exact unit it prints when it is one.
     *
     * @param PriceResult $price The priced result.
     * @param string $figure One of FIGURES.
     *
     * @return array{0: Money, 1: Money, 2: string|null} [per piece, line, the exact unit to print or null when derived]
     *
     * @throws InvalidArgumentException When the figure is not one of FIGURES.
     *
     * @since  2.0.0
     */
    private static function source(PriceResult $price, string $figure): array
    {
        [$unit, $line] = match ($figure) {
            'base' => [$price->getBasePrice(), $price->getBaseTotal()],
            'discount' => [$price->getSavingsPrice(), $price->getSavingsTotal()],
            'subtotal' => [$price->getSubtotalPrice(), $price->getSubtotal()],
            'tax' => [$price->getTaxPrice(), $price->getTaxTotal()],
            'base_with_tax' => [$price->getBasePriceWithTax(), $price->getBaseTotalWithTax()],
            'final' => [$price->getPrice(), $price->getTotal()],
            default => throw new InvalidArgumentException(sprintf("'%s' is not a figure of a price block.", $figure)),
        };

        $stated = self::statedValue(price: $price, figure: $figure);

        if ($stated !== null) {
            return [$unit, $line, $stated];
        }

        // A positive payable unit that one piece settles at zero money.
        $payable = ExactAmount::of($price->getPayableUnitExact());

        if (self::payable(price: $price, figure: $figure) && $payable->isPositive() && $payable->toMoney($price->getCurrency())->isZero()) {
            return [$unit, $line, $price->getPayableUnitExact()];
        }

        // A positive discounted unit that one piece settles at zero money: the
        // exact unit after the discounts, never 0.00.
        $discounted = self::discounted(price: $price, figure: $figure);

        if ($discounted !== null) {
            $exact = ExactAmount::of($discounted);

            if ($exact->isPositive() && $exact->toMoney($price->getCurrency())->isZero()) {
                return [$unit, $line, $discounted];
            }
        }

        return [$unit, $line, null];
    }

    /**
     * The stored value a figure IS, by the result's provenance, or null when the figure is not one.
     *
     * A price row's own value is the base, and the subtotal and the gross alike
     * while nothing was taken off and no tax changes it; a sale's own price is
     * the figure on its side after the sale, and the list it is measured
     * against is the base; an agreed rate is the final unit. A computed unit is
     * never a stored value.
     *
     * @param PriceResult $price The priced result.
     * @param string $figure One of FIGURES.
     *
     * @return string|null The exact decimal text of the stored value.
     *
     * @since  2.0.0
     */
    private static function statedValue(PriceResult $price, string $figure): ?string
    {
        $origin = $price->getStatedOrigin();
        $onNet = $price->getStatedAxis() !== UnitAxis::Gross;
        $onGross = $price->getStatedAxis() !== UnitAxis::Net;
        $reduced = $price->isReduced();
        $stated = $price->getAuthoritativeUnit();

        return match (true) {
            $origin === UnitOrigin::PriceRow => match ($figure) {
                'base' => $onNet ? $stated : null,
                'subtotal' => $onNet && !$reduced ? $stated : null,
                'base_with_tax' => $onGross ? $stated : null,
                'final' => $onGross && !$reduced ? $stated : null,
                default => null,
            },
            $origin === UnitOrigin::Sale => match ($figure) {
                'base' => $reduced ? $price->getListUnit() : ($onNet ? $stated : null),
                'subtotal' => $onNet ? $stated : null,
                'base_with_tax' => $reduced ? ($onNet && $onGross ? $price->getListUnit() : null) : ($onGross ? $stated : null),
                'final' => $onGross ? $stated : null,
                default => null,
            },
            $origin === UnitOrigin::AgreedRate => $figure === 'final' && $onGross ? $stated : null,
            default => null,
        };
    }

    /**
     * Whether a gross figure of a net-stated line is the line's payable unit (what one piece costs before discounts).
     *
     * @param PriceResult $price The priced result.
     * @param string $figure One of FIGURES.
     *
     *
     * @since  2.0.0
     */
    private static function payable(PriceResult $price, string $figure): bool
    {
        $origin = $price->getStatedOrigin();
        $reduced = $price->isReduced();

        if ($price->getStatedAxis() !== UnitAxis::Net || $origin === UnitOrigin::AgreedRate) {
            return false;
        }

        // Before discounts the gross is the stated unit through the tax, unless a
        // sale replaced a higher list; after them, only while nothing was taken.
        return match ($figure) {
            'base_with_tax' => $origin === UnitOrigin::PriceRow || !$reduced,
            'final' => $origin === UnitOrigin::Sale || !$reduced,
            default => false,
        };
    }

    /**
     * The exact unit after every discount behind a figure after the discounts, or null for another figure.
     *
     * @param PriceResult $price The priced result.
     * @param string $figure One of FIGURES.
     *
     *
     * @since  2.0.0
     */
    private static function discounted(PriceResult $price, string $figure): ?string
    {
        return match ($figure) {
            'subtotal' => $price->getDiscountedUnitExact(withTax: false),
            'final' => $price->getDiscountedUnitExact(withTax: true),
            default => null,
        };
    }

    /**
     * A stated unit: its own decimals under Auto, the currency's scale otherwise.
     *
     * @param Currency $currency The line's currency.
     * @param string $unit The stated unit, as its exact decimal text.
     * @param Money $line The whole amount it states, in the same tax space.
     * @param int $quantity The quantity.
     * @param bool $includeSymbol Whether to include the currency symbol.
     * @param bool|null $auto Null: the shop's setting. True: the Auto form.
     *
     *
     * @since  2.0.0
     */
    private static function stated(Currency $currency, string $unit, Money $line, int $quantity, bool $includeSymbol, ?bool $auto): string
    {
        return $currency->formatUnit(unit: $unit, lineTotal: $line->getAmount(), quantity: $quantity, rate: $unit, includeSymbol: $includeSymbol, auto: $auto);
    }

    /**
     * A derived unit: the line per piece, at the fewest decimals that give the line back under Auto.
     *
     * @param Currency $currency The line's currency.
     * @param Money $unit The line per piece at the currency's scale.
     * @param Money $line The whole amount.
     * @param int $quantity The quantity.
     * @param bool $includeSymbol Whether to include the currency symbol.
     * @param bool|null $auto Null: the shop's setting. True: the Auto form.
     *
     *
     * @since  2.0.0
     */
    private static function derived(Currency $currency, Money $unit, Money $line, int $quantity, bool $includeSymbol, ?bool $auto): string
    {
        return $currency->formatUnit(unit: $unit->getAmount(), lineTotal: $line->getAmount(), quantity: $quantity, includeSymbol: $includeSymbol, auto: $auto);
    }
}
