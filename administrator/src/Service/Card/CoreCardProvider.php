<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Card;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Card\CardRegistry;
use Alfa\Component\Alfa\Administrator\Event\Card\CollectCardsEvent;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ChartHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Order\PaymentLedger;
use Alfa\Component\Alfa\Administrator\Service\Stats\DashboardPeriod;
use Alfa\Component\Alfa\Administrator\Service\Stats\DashboardStatsService;
use Alfa\Component\Alfa\Administrator\Service\Stats\StatsCards;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The cards Alfa itself places on the store's home screen: what came in today,
 * what is waiting for a human, what it earned, what is running out, the last few
 * orders, the takings hero, and the alert strip. Everything else (the six analysis
 * cards, search terms, per-plugin panels) arrives through the same event from
 * whoever owns that data; the analysis cards from the Analytics plugin.
 *
 * THE HERO DRAWS THROUGH `card.chart`, which any plugin may name, from a DTO that
 * {@see StatsCards} builds and {@see ChartHelper} publishes. Core owns no chart
 * privilege: it places the card exactly as a contributor would.
 *
 * Core has no privileges here. It declares through {@see CollectCardsEvent} like
 * any plugin, names its surface, position and ordering like any plugin, and draws
 * through layouts any plugin may name: `card.metric` for a figure, `card.orders`
 * for a list. There is no built-in card shape to fall back on.
 *
 * Money rule, kept literally: takings are summed PER CURRENCY and the card shows
 * the store's own. Adding 100 EUR to 100 USD is not 200 of anything, so a shop
 * that also sold in another currency is told so instead of being handed a number
 * that means nothing.
 *
 * @since  1.10.0
 */
final class CoreCardProvider
{
    /**
     * @var string Ownership stamp for cards declared here.
     * @since  1.10.0
     */
    public const OWNER_GROUP = 'core';

    /**
     * @var string Ownership stamp for cards declared here.
     * @since  1.10.0
     */
    public const OWNER_NAME = 'com_alfa';

    /**
     * @var int How many rows the latest-orders card lists.
     * @since  1.10.0
     */
    private const LATEST_ROWS = 5;

    /**
     * @var DashboardStatsService|null Built once per request, shared by every chart card.
     * @since  1.10.0
     */
    private ?DashboardStatsService $stats = null;

    /**
     * @var StatsCards|null The card shapes over that same window.
     * @since  1.10.0
     */
    private ?StatsCards $cards = null;

    /**
     * Declare the core cards for whichever surface is being built.
     *
     * @param CollectCardsEvent $event The broadcast.
     *
     *
     * @since  1.10.0
     */
    public function onAlfaCollectCards(CollectCardsEvent $event): void
    {
        if ($event->screen() !== CardRegistry::SCREEN_DASHBOARD) {
            return;
        }

        // Each card shows its data only to a user who may view that data's own section.
        $canSeeOrders = static fn (): bool => AlfaHelper::can(section: 'orders', action: 'view');
        $canSeeItems = static fn (): bool => AlfaHelper::can(section: 'items', action: 'view');

        // The money tiles land on the documents list: without documents.view the figure stays
        // and the link goes, rather than offering a page the dispatcher would refuse.
        $documentsUrl = static fn (string $url): string => AlfaHelper::can(section: 'documents', action: 'view') ? $url : '';

        $event->metrics('orders.today', 'COM_ALFA_DASHCARD_ORDERS_TODAY')
            ->ordering(100)
            ->setLayout('card.metric')
            ->width(25)
            ->subtitle('COM_ALFA_DASHCARD_ORDERS_TODAY_SUB')
            ->icon('receipt')
            ->url(Route::_('index.php?option=com_alfa&view=orders&' . http_build_query(['filter' => ['date_range' => 'today']]), false))
            ->when($canSeeOrders)
            ->setLayoutData(fn (): array => ['value' => (string) $this->ordersToday()]);

        $event->metrics('orders.awaiting', 'COM_ALFA_DASHCARD_ORDERS_AWAITING')
            ->ordering(200)
            ->setLayout('card.metric')
            ->width(25)
            ->subtitle('COM_ALFA_DASHCARD_ORDERS_AWAITING_SUB')
            ->icon('clock')
            ->url(Route::_('index.php?option=com_alfa&view=orders&' . http_build_query(['filter' => ['id_order_status' => (int) OrderStatusHelper::getInitialId(), 'date_range' => '', 'date_start' => '', 'date_end' => '']]), false))
            ->when($canSeeOrders)
            ->setLayoutData(fn (): array => ['value' => (string) $this->ordersAwaiting()]);

        // TODAY, not a fixed seven days.
        //
        // This tile used to read "Takings, 7 days" while the switcher at the top of the
        // screen said 30: two windows on one dashboard, and the tile ignored the
        // control. Making it FOLLOW the switcher would have been the other fix, but the
        // hero already states the period's takings as its headline figure, so the tile
        // would then have been the same number twice. Today is the reading nothing else
        // gives, and it makes the whole metrics row consistent: four figures about
        // right now, with the period cards below them.
        $event->metrics('money.takings', 'COM_ALFA_DASHCARD_TAKINGS')
            ->ordering(300)
            ->setLayout('card.metric')
            ->width(25)
            ->subtitle('COM_ALFA_DASHCARD_TAKINGS_SUB')
            ->icon('coins')
            ->url($documentsUrl(Route::_('index.php?option=com_alfa&view=documents&' . http_build_query(['filter' => ['date_range' => 'today']]), false)))
            ->when($canSeeOrders)
            ->setLayoutData(fn (): array => ['value' => $this->takingsToday()]);

        $event->metrics('stock.low', 'COM_ALFA_DASHCARD_STOCK_LOW')
            ->ordering(400)
            ->setLayout('card.metric')
            ->width(25)
            ->subtitle('COM_ALFA_DASHCARD_STOCK_LOW_SUB')
            ->icon('box')
            ->url(Route::_('index.php?option=com_alfa&view=items&' . http_build_query(['filter' => ['stock' => 'low']]), false))
            ->when($canSeeItems)
            ->setLayoutData(fn (): array => ['value' => (string) $this->lowStockUnits()]);

        $event->main('orders.latest', 'COM_ALFA_DASHCARD_LATEST')
            ->ordering(110)
            ->setLayout('card.orders')
            ->icon('list')
            ->url(Route::_('index.php?option=com_alfa&view=orders', false))
            ->width(100)
            ->when($canSeeOrders)
            ->setLayoutData(fn (): array => ['rows' => $this->latestOrders()]);

        // THE TAKINGS HERO IS THE ONE CHART CORE KEEPS. Every other chart card (best
        // sellers, how they paid, where the orders are, the promotions, the week's rhythm,
        // who spends) is placed by the Analytics plugin, which also draws them over any
        // range on its own screen. Core keeps what every shop needs on opening the admin:
        // right-now tiles, the latest orders, the hero, and the alert strip. Ordering 100
        // and 110, well below AdminCard::ORDERING_DEFAULT, so a contributor with no
        // opinion lands after both.
        $event->main('money.trend', 'COM_ALFA_DASHCARD_TREND')
            ->ordering(100)
            ->setLayout('card.chart')
            ->width(100)
            ->url($documentsUrl(Route::_('index.php?option=com_alfa&view=documents', false)))
            ->when($canSeeOrders)
            ->setLayoutData(fn (): array => $this->cards()->trend());

        // ON TOP, not at the bottom. Money that did not land and orders nobody has
        // touched are the one thing on this screen that asks to be acted on, and a
        // merchant should not have to scroll past every chart to find out. It renders
        // NOTHING when both are zero, so the quiet shop still opens on its figures.
        $event->top('orders.attention', 'COM_ALFA_DASHCARD_ATTENTION')
            ->ordering(100)
            ->setLayout('card.attention')
            ->width(100)
            ->when($canSeeOrders)
            ->setLayoutData(fn (): array => $this->attentionCard());
    }

    /**
     * The stats service over the period this viewer chose, built once and shared, so
     * every card on the screen resolves the same window rather than each its own.
     *
     *
     * @since  1.10.0
     */
    private function stats(): DashboardStatsService
    {
        return $this->stats ??= new DashboardStatsService(DashboardPeriod::days());
    }

    /**
     * The same readings, shaped for `card.chart`.
     *
     *
     * @since  1.10.0
     */
    private function cards(): StatsCards
    {
        return $this->cards ??= new StatsCards($this->stats());
    }

    /**
     * The findings, dressed: what kind of trouble it is, a number, a sentence that
     * says what the number means, and a verb that goes where it can be dealt with.
     *
     * The SERVICE decides what is true; this decides how it reads. Severity is a
     * presentation judgement ("money taken and nothing shipped" is a different colour
     * from "five products are out of stock"), so it lives here and not in the query.
     *
     * Returns an empty array when nothing is wrong, which the card contract reads as
     * "do not render". A dashboard that reports daily that everything is fine is a
     * dashboard nobody reads.
     *
     *
     * @since  1.10.0
     */
    private function attentionCard(): array
    {
        $stats = $this->stats();
        $findings = $stats->attention(DashboardStatsService::STALE_HOURS);

        if ($findings === []) {
            return [];
        }

        $days = $stats->days();
        $rows = [];

        // WHERE A TILE LANDS IS THE LIST, ALREADY NARROWED TO THE FINDING: the same
        // predicate the figure was counted with, as that list's own filter, so what
        // the merchant reads here is what they see there. Only the keys named travel:
        // the request replaces the list's remembered filters, so a stale status or
        // search left behind last week cannot shrink the landing.
        $window = 'past_' . $days . 'days';
        // The money on a tile is the shop currency's; orders in other currencies are
        // counted, and said so.
        $others = static fn (int $n): string => $n > 0 ? ' ' . Text::plural('COM_ALFA_DASHCARD_ATTENTION_OTHERS', $n) : '';
        // An order that names no currency is counted, has no value to add, and is said to have none.
        $noCurrency = static fn (int $n): string => $n > 0 ? ' ' . Text::plural('COM_ALFA_DASHCARD_ATTENTION_NO_CURRENCY', $n) : '';
        $list = static fn (string $view, array $filter): string
            => Route::_('index.php?option=com_alfa&view=' . $view . '&' . http_build_query(['filter' => $filter]), false);

        // The card itself is gated by orders.view; the out-of-stock and best-seller findings are
        // catalogue data, the same the low-stock tile shows only to a user who may view items,
        // and the promotion finding is cart-rule data.
        $canSeeItems = AlfaHelper::can(section: 'items', action: 'view');
        $canSeeCartrules = AlfaHelper::can(section: 'cartrules', action: 'view');

        // Those two tiles open an edit screen; a viewer who may see the data but not edit it
        // keeps the tile without the link. They open through the edit task, which checks the
        // record out and clears a post kept for another record.
        $canEditItems = AlfaHelper::can(section: 'items', action: 'edit');
        $canEditCartrules = AlfaHelper::can(section: 'cartrules', action: 'edit');

        foreach ($findings as $finding) {
            if (
                (\in_array($finding['key'], ['outofstock', 'bestseller'], true) && !$canSeeItems)
                || ($finding['key'] === 'promo' && !$canSeeCartrules)
            ) {
                continue;
            }

            $extra = (array) $finding['extra'];
            $money = $this->cards()->money($finding['money']);

            $rows[] = match ($finding['key']) {
                // The weight is the service's own ranking tier, as a colour: red for money
                // the shop holds or gave away, amber for money in play, blue for
                // housekeeping nobody has paid for yet.
                // Money in, nothing out. Red on any shop.
                'unfulfilled' => [
                    'severity' => 'danger',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_UNFULFILLED'),
                    'value' => number_format($finding['count']),
                    'text' => Text::sprintf('COM_ALFA_DASHCARD_ATTENTION_UNFULFILLED', $money, (int) $extra['hours']) . $others((int) ($extra['others'] ?? 0)) . $noCurrency((int) ($extra['noCurrency'] ?? 0)),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_ORDERS'),
                    'url' => $list('orders', ['payment' => 'paid', 'fulfilment' => 'unshipped', 'waiting' => 'untouched_48', 'date_range' => '', 'date_start' => '', 'date_end' => '']),
                ],
                // Still winnable, which is why the value is stated and not just the count.
                'unpaid' => [
                    'severity' => 'warning',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_UNPAID'),
                    'value' => number_format($finding['count']),
                    'text' => Text::sprintf('COM_ALFA_DASHCARD_ATTENTION_UNPAID', $money, (int) $extra['hours']) . $others((int) ($extra['others'] ?? 0)) . $noCurrency((int) ($extra['noCurrency'] ?? 0)),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_ORDERS'),
                    'url' => $list('orders', ['payment' => 'unpaid', 'waiting' => 'placed_48', 'date_range' => $window]),
                ],
                // The finding a bare failure count cannot make: they have a culprit,
                // and the list opens on that method's failed attempts.
                'gateway' => [
                    'severity' => 'warning',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_GATEWAY'),
                    'value' => number_format($finding['count']),
                    'text' => Text::sprintf(
                        'COM_ALFA_DASHCARD_ATTENTION_GATEWAY',
                        (int) $extra['total'],
                        (string) $extra['gateway'],
                        (int) $extra['share'],
                    ),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_PAYMENTS'),
                    'url' => $list('orders', ['payment' => 'failed', 'date_range' => $window] + ((int) ($extra['method_id'] ?? 0) > 0 ? ['id_payment_method' => (int) $extra['method_id']] : [])),
                ],
                'failed' => [
                    'severity' => 'warning',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_FAILED'),
                    'value' => number_format($finding['count']),
                    'text' => Text::sprintf('COM_ALFA_DASHCARD_ATTENTION_FAILED', $money, $days),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_ORDERS'),
                    'url' => $list('orders', ['payment' => 'failed', 'date_range' => $window]),
                ],
                // The cross-reference: what sells, against what is left of it.
                'bestseller' => [
                    'severity' => 'info',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_BESTSELLER'),
                    'value' => number_format($finding['count']),
                    'text' => Text::sprintf(
                        'COM_ALFA_DASHCARD_ATTENTION_BESTSELLER',
                        (string) $extra['name'],
                        $money,
                    ),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_ITEM'),
                    'url' => $canEditItems ? Route::_('index.php?option=com_alfa&task=item.edit&id=' . (int) $extra['id'], false) : '',
                ],
                'outofstock' => [
                    'severity' => 'info',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_OUTOFSTOCK'),
                    'value' => number_format($finding['count']),
                    'text' => Text::_('COM_ALFA_DASHCARD_ATTENTION_OUTOFSTOCK'),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_ITEMS'),
                    'url' => $list('items', ['stock' => 'out', 'state' => 1]),
                ],
                // A rule that gave away at least as much as it brought in.
                'promo' => [
                    'severity' => 'danger',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_PROMO'),
                    'value' => $money,
                    'text' => Text::sprintf(
                        'COM_ALFA_DASHCARD_ATTENTION_PROMO',
                        (string) $extra['name'],
                        $this->cards()->money((float) $extra['revenue']),
                        $finding['count'],
                    ),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_CARTRULE'),
                    'url' => $canEditCartrules ? Route::_('index.php?option=com_alfa&task=cartrule.edit&id=' . (int) $extra['id'], false) : '',
                ],
                'refunds' => [
                    'severity' => 'warning',
                    'kind' => Text::_('COM_ALFA_DASHCARD_ATTENTION_KIND_REFUNDS'),
                    'value' => '+' . number_format($finding['count']) . '%',
                    'text' => Text::sprintf(
                        'COM_ALFA_DASHCARD_ATTENTION_REFUNDS',
                        $money,
                        $this->cards()->money((float) $extra['previous']),
                        $days,
                    ),
                    'go' => Text::_('COM_ALFA_DASHCARD_ATTENTION_GO_DOCUMENTS'),
                    'url' => $list('orders', ['payment' => 'refunded', 'date_range' => $window]),
                ],
                default => null,
            };
        }

        // EVERY FINDING, NO CAP. There are at most eight, the layout is a grid, and a
        // merchant told "one more finding" has been told something is being withheld.
        $rows = array_values(array_filter($rows));

        // Every finding may have been one this viewer is not shown: then, as with none, no card.
        return $rows === [] ? [] : ['rows' => $rows];
    }

    /**
     * @since  1.10.0
     */
    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Orders created since midnight, in the site's own timezone: a merchant's
     * "today" is the one on their wall, not UTC's.
     *
     *
     * @since  1.10.0
     */
    private function ordersToday(): int
    {
        $db = $this->db();
        $start = Factory::getDate('today midnight', Factory::getApplication()->get('offset', 'UTC'))
            ->toSql();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('created') . ' >= :start')
                ->where($db->quoteName('state') . ' <> -2')
                ->bind(':start', $start),
        )->loadResult();
    }

    /**
     * Orders still sitting in the initial status: the queue a human works from.
     *
     *
     * @since  1.10.0
     */
    private function ordersAwaiting(): int
    {
        $initial = (int) OrderStatusHelper::getInitialId();

        if ($initial <= 0) {
            return 0;
        }

        $db = $this->db();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id_order_status') . ' = :initial')
                ->where($db->quoteName('state') . ' <> -2')
                ->bind(':initial', $initial, ParameterType::INTEGER),
        )->loadResult();
    }

    /**
     * Settled takings since the merchant's own midnight, in the store's own currency.
     *
     * Refund rows carry their own payment_type, so netting them off is a sum, not
     * a subtraction the caller has to remember.
     *
     * @return string Formatted money, with a note when other currencies also sold.
     *
     * @since  1.10.0
     */
    private function takingsToday(): string
    {
        $db = $this->db();
        // The merchant's midnight, not UTC's: the same boundary the orders tile uses,
        // so "3 orders today" and "142 € today" can never disagree about when today
        // started.
        $since = Factory::getDate('today midnight', Factory::getApplication()->get('offset', 'UTC'))->toSql();

        // Takings: settled payments, less chargebacks, plus won disputes. A
        // payment is money in its ORDER's currency, so the rows are grouped by
        // the currency the order names and only where that currency has a row:
        // the money of an order that names none is in no figure.
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('o.id_currency', 'id_currency'), 'SUM(' . PaymentLedger::takingsAmount('p') . ') AS total'])
                ->from($db->quoteName('#__alfa_order_payments', 'p') . PaymentLedger::reversalTargetJoin('p', 't') . PaymentLedger::orderJoin('p', 'o', 'c'))
                ->where(PaymentLedger::takingsPredicate('p', 't'))
                ->where($db->quoteName('p.processed_at') . ' >= :since')
                ->group($db->quoteName('o.id_currency'))
                ->bind(':since', $since),
        )->loadObjectList('id_currency') ?: [];

        $store = Currency::getDefault();
        $mine = (float) ($rows[$store->getId()]->total ?? 0.0);
        $money = Money::of($mine, $store)->format();

        $others = \count(array_filter(
            $rows,
            static fn (object $row): bool => (int) $row->id_currency !== $store->getId() && (float) $row->total > 0,
        ));

        return $others === 0
            ? $money
            : $money . ' ' . Text::plural('COM_ALFA_DASHCARD_TAKINGS_OTHER_CURRENCIES', $others);
    }

    /**
     * Stock units at or below the low-stock mark they asked to be watched at.
     * Combinations answer for themselves; a simple product answers for itself,
     * and a product whose combinations are all archived is a simple product.
     *
     *
     * @since  1.10.0
     */
    private function lowStockUnits(): int
    {
        $db = $this->db();

        // THE SHOP-WIDE THRESHOLD COMPLETES THE CHAIN HERE TOO.
        //
        // This counted only units whose own `stock_low` was positive, so every
        // product left on "Use Global" was invisible to the card: the same
        // gap the low-stock NOTIFICATION had, and the third of the three
        // places this one rule was being resolved. The item form has always
        // resolved it correctly and states the contract: an empty item value
        // means "inherit the shop-wide threshold", not "this product has
        // none".
        //
        // A float from the component configuration, so it is interpolated
        // rather than bound; NULLIF keeps a stored 0 meaning "not set", which
        // is how the combination expression already read it.
        $global = (float) ComponentHelper::getParams('com_alfa')->get('stock_low', 0);
        $itemThreshold = 'COALESCE(NULLIF(i.' . $db->quoteName('stock_low') . ', 0), ' . $global . ')';
        $unitThreshold = 'COALESCE(NULLIF(c.' . $db->quoteName('stock_low') . ', 0), NULLIF(i.'
            . $db->quoteName('stock_low') . ', 0), ' . $global . ')';

        $items = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items', 'i')
            . ' WHERE i.' . $db->quoteName('state') . ' = 1'
            . ' AND i.' . $db->quoteName('manage_stock') . ' = 1'
            . ' AND ' . $itemThreshold . ' > 0'
            . ' AND i.' . $db->quoteName('available_quantity') . ' <= ' . $itemThreshold
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_item_combinations', 'c')
            . ' WHERE c.' . $db->quoteName('item_id') . ' = i.' . $db->quoteName('id')
            . ' AND ' . CombinationState::currentSql(column: 'c.' . $db->quoteName('state')) . ')',
        )->loadResult();

        $combinations = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_combinations', 'c')
            . ' JOIN ' . $db->quoteName('#__alfa_items', 'i') . ' ON i.' . $db->quoteName('id') . ' = c.' . $db->quoteName('item_id')
            . ' WHERE c.' . $db->quoteName('state') . ' = 1 AND i.' . $db->quoteName('state') . ' = 1'
            . ' AND ' . $unitThreshold . ' > 0'
            . ' AND c.' . $db->quoteName('available_quantity') . ' <= ' . $unitThreshold,
        )->loadResult();

        return $items + $combinations;
    }

    /**
     * The last few orders, already shaped for the layout that lists them.
     *
     *
     * @since  1.10.0
     */
    private function latestOrders(): array
    {
        $db = $this->db();

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('o.id'),
                $db->quoteName('o.reference'),
                $db->quoteName('o.created'),
                // The order's own customer snapshot: guests have a name here too.
                $db->quoteName('o.customer_name', 'customer'),
                $db->quoteName('s.color'),
                $db->quoteName('s.bg_color'),
            ])
            ->from($db->quoteName('#__alfa_orders', 'o'))
            ->join('LEFT', $db->quoteName('#__alfa_orders_statuses', 's'), 's.id = o.id_order_status')
            ->where($db->quoteName('o.state') . ' <> -2')
            ->order($db->quoteName('o.id') . ' DESC')
            ->setLimit(self::LATEST_ROWS);

        // The status name lives in the per-language auxiliary tables, so it comes
        // through the same join every other list uses, never a raw column read.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         's',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_orders_statuses',
            langPrimaryColumn: 'id_orderstatus',
            fields:            ['name'],
        );

        $rows = $db->setQuery($query)->loadObjectList() ?: [];
        $out = [];

        // The card is shown with orders.view; a row opens the order's edit screen, so its link
        // is offered only with orders.edit.
        $canEditOrders = AlfaHelper::can(section: 'orders', action: 'edit');

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'number' => (string) ($row->reference ?: ('#' . (int) $row->id)),
                'customer' => (string) ($row->customer ?: Text::_('COM_ALFA_DASHCARD_GUEST')),
                'created' => (string) $row->created,
                'status' => (string) ($row->name ?? ''),
                'color' => (string) ($row->color ?? ''),
                'bg_color' => (string) ($row->bg_color ?? ''),
                'url' => $canEditOrders ? Route::_('index.php?option=com_alfa&task=order.edit&id=' . (int) $row->id, false) : '',
            ];
        }

        return $out;
    }
}
