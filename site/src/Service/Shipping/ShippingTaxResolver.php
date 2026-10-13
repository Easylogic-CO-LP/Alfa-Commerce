<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\AppliedTax;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Which tax rules a shipping charge carries for one shipment.
 *
 * A METHOD REFERENCES TAX RULES; IT NEVER STATES A RATE. The method's own
 * rules (#__alfa_shipment_taxes), or, for a method that names none, the shop's
 * default shipping taxes. A method that names rules never falls back: a rule
 * that does not reach this destination is the destination answering 0%, not a
 * gap to fill. With neither, carriage is untaxed.
 *
 * THE DESTINATION DECIDES. Each rule is read as the catalogue reads it (state,
 * publish window, customer groups, customer) and its places against the
 * shipment's destination chain: a rule scoped to a place applies to every
 * destination inside it, and an unknown destination admits only rules that
 * name no place. Category and manufacturer scopes describe goods; a shipping
 * charge has neither, so they are not read. How several rules combine (only
 * this, combined, one after another) is the pricing engine's own reading.
 *
 * @since  2.0.0
 */
final class ShippingTaxResolver
{
    /**
     * The component option holding the shop's default shipping taxes.
     *
     * @since  2.0.0
     */
    public const SHOP_DEFAULT = 'shipping_tax_rules';

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
            $ids = self::positiveIds((array) ComponentHelper::getParams('com_alfa')->get(self::SHOP_DEFAULT, []));
        }

        if ($ids === []) {
            return [];
        }

        $places = self::positiveIds($context->destination);
        $audience = $context->audience;
        $groups = $audience->getUserGroupsSql();

        $query = $db->getQuery(true)
            ->select('DISTINCT t.id, t.ordering, t.value, t.behavior')
            ->from($db->quoteName('#__alfa_taxes', 't'))
            ->join('LEFT', $db->quoteName('#__alfa_tax_places', 'tp') . ' ON tp.tax_id = t.id')
            ->join('LEFT', $db->quoteName('#__alfa_tax_usergroups', 'tu') . ' ON tu.tax_id = t.id')
            ->join('LEFT', $db->quoteName('#__alfa_tax_users', 'tgu') . ' ON tgu.tax_id = t.id')
            ->whereIn($db->quoteName('t.id'), $ids)
            ->where($places === []
                ? '(tp.tax_id IS NULL OR tp.place_id = 0)'
                : '(tp.tax_id IS NULL OR tp.place_id = 0 OR tp.place_id IN (' . implode(',', $places) . '))')
            ->where('(tu.tax_id IS NULL OR tu.usergroup_id = 0 OR tu.usergroup_id IN (' . ($groups !== '' ? $groups : '0') . '))')
            ->where('(tgu.tax_id IS NULL OR tgu.user_id = 0 OR tgu.user_id = ' . (int) ($audience->getUserId() ?? 0) . ')')
            ->where((new ActiveDateCondition())->getActiveCondition('t.publish_up', 't.publish_down', $audience->getReferenceDate()))
            ->where('t.state = 1')
            // The order decides which "only this" rule wins and the order
            // sequential rates apply in, as it does for goods.
            ->order('t.ordering ASC, t.id ASC');

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         't',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_taxes',
            langPrimaryColumn: 'id_tax',
            fields:            ['name'],
        );

        return PriceComputationEngine::chainOf(
            taxes:    $db->setQuery($query)->loadObjectList() ?: [],
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
