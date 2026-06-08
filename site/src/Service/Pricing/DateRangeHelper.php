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

class DateRangeHelper
{
    /**
     * Build a SQL WHERE fragment that matches rows currently active within a [start, end] date window.
     * Empty ('0000-00-00 00:00:00') start/end values are treated as open-ended.
     *
     * @param string $startField The quoted-or-unquoted start date column name.
     * @param string $endField   The quoted-or-unquoted end date column name.
     *
     * @return string The SQL condition.
     */
    public function getActiveCondition(string $startField, string $endField): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        return sprintf(
            "IFNULL(NOW() >= %s OR %s = '0000-00-00 00:00:00', 1) = 1 " .
            "AND IFNULL(%s != '0000-00-00 00:00:00' AND NOW() > %s, 0) = 0",
            $db->qn($startField),
            $db->qn($startField),
            $db->qn($endField),
            $db->qn($endField),
        );
    }
}
