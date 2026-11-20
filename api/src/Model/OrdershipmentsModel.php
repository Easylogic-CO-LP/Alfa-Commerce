<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

/**
 * The shipments of one order, as the order read gives them.
 *
 * @since  2.2.2
 */
class OrdershipmentsModel extends OrderPartsModel
{
    /**
     * The order read's name for the part.
     *
     * @since  2.2.2
     */
    protected const PART = 'shipments';
}
