<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class ActiveDateCondition
{
    /**
     * Build a SQL WHERE fragment that matches rows active within a [start, end]
     * date window. Empty ('0000-00-00 00:00:00') start/end values are treated
     * as open-ended.
     *
     * @param string $startField The quoted-or-unquoted start date column name.
     * @param string $endField The quoted-or-unquoted end date column name.
     * @param string|null $referenceDate Evaluate the window at this moment instead
     *                                   of the live UTC clock; the temporal price
     *                                   index computes future segments with this.
     *
     * @return string The SQL condition.
     * @since  1.0.0
     */
    public function getActiveCondition(string $startField, string $endField, ?string $referenceDate = null): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $moment = $db->quote($referenceDate ?? self::now());

        return sprintf(
            "IFNULL({$moment} >= %s OR %s = '0000-00-00 00:00:00', 1) = 1 " .
            "AND IFNULL(%s != '0000-00-00 00:00:00' AND {$moment} > %s, 0) = 0",
            $db->qn($startField),
            $db->qn($startField),
            $db->qn($endField),
            $db->qn($endField),
        );
    }

    /**
     * The live moment every publish / validity window is evaluated at, as an SQL
     * datetime in UTC.
     *
     * Every date this component STORES (publish_up / publish_down on items, the
     * price index's valid_from / valid_until, discount and tax windows) is written
     * by Joomla in UTC (Factory::getDate()->toSql()). Comparing them against the
     * database's NOW() is only correct while the DB session runs in UTC; on this
     * host @@time_zone is SYSTEM = EEST, so NOW() ran three hours AHEAD of the
     * stored values and a product scheduled for 12:04 UTC went on sale at 09:04.
     * The pricing index showed the same skew: a promo segment opened three hours
     * early. Joomla core's own com_content does exactly this (the PHP UTC clock,
     * bound into the query) so the catalogue now shares that contract. One clock,
     * here, so the listing, the product page and the price index can never disagree.
     *
     * @return string SQL datetime, UTC.
     * @since  1.3.0
     */
    public static function now(): string
    {
        return Factory::getDate()->toSql();
    }
}
