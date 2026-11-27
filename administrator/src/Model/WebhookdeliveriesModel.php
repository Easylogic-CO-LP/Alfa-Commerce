<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Joomla\CMS\Factory;

/**
 * One webhook's deliveries, the Deliveries tab of its form: the outgoing notifications' own reading
 * ({@see OutboundModel}) pinned to that consumer, a webhook address, so the tab and the outgoing notifications screen
 * never read the rows differently.
 *
 * @since  2.3.0
 */
class WebhookdeliveriesModel extends OutboundModel
{
    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'webhookdelivery';

    /**
     * The list's state: the webhook the form shows, newest first by default, and the reading pinned to that webhook
     * whatever the request's filters name.
     *
     * @param string $ordering The default ordering.
     * @param string $direction The default direction.
     *
     * @since  2.3.0
     */
    protected function populateState($ordering = 'd.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);

        $webhook = RequestScalar::int(Factory::getApplication()->getInput(), 'webhook_id');
        $this->setState('webhook.id', $webhook);
        $this->setState('filter.kind', $webhook);
        $this->setState('filter.type', OutboundConsumers::KIND_WEBHOOK);
    }
}
