<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * How many published products a saved discount rule's scope takes in and yet never reaches: the products excluded by
 * the rule itself (its excluded products and categories), and the products excluded on their own (their discount rules
 * switch off). Counted over the catalogue's links by the product side of the rule's scope, as the loader matches it:
 * its categories, its manufacturers and its products; places, customer groups and customers narrow the shoppers, not
 * the products. Every condition is RuleReach's, the builder the reach itself is counted by.
 *
 * @since  2.3.0
 */
final class DiscountExclusions
{
    /**
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * The counts of one saved rule.
     *
     * @param int $discountId The rule.
     *
     * @return array{by_rule: int, own: int}
     *
     * @since  2.3.0
     */
    public function counts(int $discountId): array
    {
        $db = $this->db;
        $item = $db->quoteName('a.id');
        $rule = (string) $discountId;

        // The scope, the rule's exclusions and the product's switch in the reach's own words (RuleReach), so these
        // counts and the reach beside them are parts of one sum.
        $byRule = RuleReach::excludedSql(ruleId: $rule, itemId: $item);
        $from = ' FROM ' . $db->quoteName('#__alfa_items', 'a') . ' WHERE ' . $db->quoteName('a.state') . ' = 1'
            . ' AND ' . RuleReach::scopeSql(kind: RuleReach::DISCOUNT, ruleId: $rule, itemId: $item);

        return [
            'by_rule' => (int) $db->setQuery('SELECT COUNT(*)' . $from . ' AND ' . $byRule)->loadResult(),
            'own' => (int) $db->setQuery('SELECT COUNT(*)' . $from . ' AND NOT ' . $byRule . ' AND ' . RuleReach::switchedOffSql(itemId: $item))->loadResult(),
        ];
    }
}
