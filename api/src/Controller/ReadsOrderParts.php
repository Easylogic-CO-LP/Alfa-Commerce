<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The read of one part of an order (its payments, its shipments) as a list of its own: GET v1/alfa/orders/:id/<part>.
 * The section asked is the order's (orders view), the list is the order read's own projection of that part
 * ({@see \Alfa\Component\Alfa\Api\Model\OrderPartsModel}), and an order that does not exist answers 404.
 *
 * @since  2.3.0
 */
trait ReadsOrderParts
{
    /**
     * An order's part is read with the order's own permission.
     *
     * @since  2.3.0
     */
    protected function aclSection(): string
    {
        return 'orders';
    }

    /**
     * The order's part, as a JSON:API list.
     *
     * @return static
     *
     * @throws ResourceNotFound 404 when the order does not exist.
     *
     * @since  2.3.0
     */
    public function displayList()
    {
        $orderId = (int) $this->input->getInt('id', 0);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $exists = $orderId > 0 && (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_orders'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $orderId, ParameterType::INTEGER),
        )->loadResult() > 0;

        if (!$exists) {
            throw new ResourceNotFound(Text::_('JLIB_APPLICATION_ERROR_RECORD'), 404);
        }

        $this->modelState->set('filter.order_id', $orderId);

        return parent::displayList();
    }
}
