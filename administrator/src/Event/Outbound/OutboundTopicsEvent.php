<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Outbound;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundTopics;
use Joomla\CMS\Event\AbstractEvent;
use LogicException;

/**
 * Which changes a plugin consumer wants to be told of (onAlfaOutboundTopics), asked of the one plugin when its
 * settings are saved, or it is enabled: core then keeps its consumer row (kind plugin) with those topics, and pauses
 * the row when the plugin is disabled. A plugin that names no topic has no row.
 *
 * @since  2.2.2
 */
final class OutboundTopicsEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.2.2
     */
    public const NAME = 'onAlfaOutboundTopics';

    /**
     * The topics named.
     *
     * @var string[]
     *
     * @since  2.2.2
     */
    private array $topics = [];

    /**
     * @since  2.2.2
     */
    public function __construct()
    {
        parent::__construct(self::NAME, []);
    }

    /**
     * Take a topic: stock, price, order.created or order.status.
     *
     * @param string $topic The topic.
     *
     * @throws LogicException When it is not one of the shop's topics.
     *
     * @since  2.2.2
     */
    public function topic(string $topic): static
    {
        if (!OutboundTopics::known($topic)) {
            throw new LogicException('Not a topic of the shop: ' . $topic . ' (stock, price, order.created, order.status).');
        }

        $this->topics[$topic] = true;

        return $this;
    }

    /**
     * The topics named, in the shop's order.
     *
     * @return string[]
     *
     * @since  2.2.2
     */
    public function getTopics(): array
    {
        return OutboundTopics::parse(topics: array_keys($this->topics));
    }
}
