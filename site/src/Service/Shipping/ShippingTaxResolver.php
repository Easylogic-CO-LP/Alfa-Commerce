<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\AppliedTax;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceDataLoader;
use Alfa\Component\Alfa\Site\Service\Pricing\RulePrecedence;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Which tax rules a shipping charge carries for one shipment.
 *
 * A METHOD OWNS ITS TAXES; IT REFERENCES TAX RULES AND NEVER STATES A RATE.
 * The rules the method names (#__alfa_shipment_taxes) and nothing else: there
 * is no shop-level default and no inheritance. A method that names none is
 * untaxed, which is the merchant's explicit choice, and so is a shipment that
 * names no method. A rule that does not reach this destination is the
 * destination answering 0%, not a gap to fill.
 *
 * THE DESTINATION DECIDES. Each rule is read as the catalogue reads it (state,
 * publish window, customer groups, customer) and its places against the
 * shipment's destination chain: a rule scoped to a place applies to every
 * destination inside it, and an unknown destination admits only rules that
 * name no place. A destination that may not price (an unpublished country, or
 * a place inside one: PlaceTree::eligible()) is the unknown destination here,
 * as the same buyer's place is for the goods (PriceContext, 2.2.0); the
 * shipping zones keep the raw destination. Category and manufacturer scopes
 * describe goods; a shipping charge has neither, so they are not read. The
 * rules that match are put in the one order the goods' taxes of the same buyer
 * are put in (RulePrecedence, 2.2.1), and how they combine (only this,
 * combined, one after another) is the pricing engine's own reading.
 *
 * @since  2.0.0
 */
final class ShippingTaxResolver
{
    /**
     * The tax chain for this shipment, in the order the rates apply.
     *
     * @param ShipmentPricingContext $context The shipment being priced.
     *
     * @return AppliedTax[] Empty when the carriage is untaxed here.
     *
     * @since  2.0.0
     */
    public function resolve(ShipmentPricingContext $context): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $ids = $this->methodTaxIds(db: $db, methodId: $context->methodId);

        if ($ids === []) {
            return [];
        }

        $places = self::positiveIds($context->destination);

        // The nearest place is the destination itself; eligible() walks every
        // place above it. An ineligible one admits only the rules that name no
        // place, as the goods' taxes do for that buyer.
        if ($places !== [] && !PlaceTree::eligible(placeId: $places[0])) {
            $places = [];
        }

        $audience = $context->audience;
        $groups = $audience->getUserGroupsSql();

        $query = $db->getQuery(true)
            // The place of the scope row that matched, for the order (RulePrecedence), as the goods' taxes carry it.
            ->select('DISTINCT t.id, t.ordering, t.value, t.behavior, tp.place_id AS matched_place')
            ->from($db->quoteName('#__alfa_taxes', 't'))
            ->join('LEFT', $db->quoteName('#__alfa_tax_places', 'tp') . ' ON tp.tax_id = t.id')
            ->join('LEFT', $db->quoteName('#__alfa_tax_usergroups', 'tu') . ' ON tu.tax_id = t.id')
            ->join('LEFT', $db->quoteName('#__alfa_tax_users', 'tgu') . ' ON tgu.tax_id = t.id')
            ->whereIn($db->quoteName('t.id'), $ids)
            ->where(PriceDataLoader::placeMatch(alias: 'tp', foreignKey: 'tax_id', chain: $places))
            ->where('(tu.tax_id IS NULL OR tu.usergroup_id = 0 OR tu.usergroup_id IN (' . ($groups !== '' ? $groups : '0') . '))')
            ->where('(tgu.tax_id IS NULL OR tgu.user_id = 0 OR tgu.user_id = ' . (int) ($audience->getUserId() ?? 0) . ')')
            ->where((new ActiveDateCondition())->getActiveCondition('t.publish_up', 't.publish_down', $audience->getReferenceDate()))
            ->where('t.state = 1')
            ->order('t.ordering ASC, t.id ASC');

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         't',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_taxes',
            langPrimaryColumn: 'id_tax',
            fields:            ['name'],
        );

        // The order decides which "only this" rule wins and the order sequential rates apply in: the goods' order,
        // over the same chain.
        return PriceComputationEngine::chainOf(
            taxes:    RulePrecedence::order(rows: $db->setQuery($query)->loadObjectList() ?: [], chain: $places),
            currency: $context->currency,
        );
    }

    /**
     * The tax rules a method names; empty when it names none.
     *
     * @param DatabaseInterface $db The database.
     * @param int $methodId The shipping method.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private function methodTaxIds(DatabaseInterface $db, int $methodId): array
    {
        if ($methodId <= 0) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('tax_id'))
            ->from($db->quoteName('#__alfa_shipment_taxes'))
            ->where($db->quoteName('shipment_id') . ' = :methodId')
            ->bind(':methodId', $methodId, ParameterType::INTEGER);

        return self::positiveIds($db->setQuery($query)->loadColumn() ?: []);
    }

    /**
     * The positive integer ids of a list, in order, each once.
     *
     * @param array $values Ids as stored or posted.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private static function positiveIds(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
