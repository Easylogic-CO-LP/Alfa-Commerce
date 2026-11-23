<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Order\OrderConfirmation;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * One order of the customer logged in, read by the order pages' read service (OrderConfirmation): only an order of
 * their account, not in the trash, never by a key, an e-mail or a name.
 *
 * @since  2.3.0
 */
class OrderModel extends BaseDatabaseModel
{
    /**
     * The customer's own order, as its page prints it; null when it is not theirs, is in the trash or is not there.
     *
     * @param int $id The order the request names.
     *
     * @since  2.3.0
     */
    public function getOrder(int $id): ?OrderConfirmation
    {
        $viewerId = (int) Factory::getApplication()->getIdentity()->id;

        return OrderConfirmation::access(orderId: $id, key: '', viewerId: $viewerId) === OrderConfirmation::GRANTED
            ? OrderConfirmation::read(orderId: $id)
            : null;
    }
}
